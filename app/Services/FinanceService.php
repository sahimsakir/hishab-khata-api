<?php

namespace App\Services;

use App\Contracts\FinanceRepository;
use App\Support\InstallmentSchedule;
use Carbon\CarbonImmutable;

class FinanceService
{
    public function __construct(private FinanceRepository $repo, private BalanceService $balances) {}

    public function snapshot(int $uid, string $date, string $period): array
    {
        $records = $this->repo->records($uid);
        $tx = $this->repo->transactions($uid);
        $at = CarbonImmutable::parse($date);
        $from = match ($period) {
            'Daily' => $at,'Yearly' => $at->startOfYear(),default => $at->startOfMonth()
        };
        $to = match ($period) {
            'Daily' => $at,'Yearly' => $at->endOfYear(),default => $at->endOfMonth()
        };
        $planner = [];
        $through = $at->format('Y-m');
        foreach ($records as $r) {
            $d = $r->data;
            $type = match ($r->kind) {
                'incomes' => 'income','bills' => 'expense','loans' => 'loan_payment','savings' => 'saving',default => null
            };
            if ($type) {
                $amount = $d[match ($r->kind) {
                    'loans' => 'emi_paisa','savings' => 'monthly_paisa',default => 'amount_paisa'
                }];
                if (! $amount) {
                    continue;
                }if ($r->kind === 'loans' && $this->balances->loan($r, $tx) <= 0) {
                    continue;
                }
                $dates = InstallmentSchedule::dates($r->kind, $d, $at->endOfMonth()->format('Y-m-d'));
                foreach ($dates as $n => $scheduled) {
                    $cycle = $scheduled['cycle'];
                    $due = $scheduled['date'];
                    $historical = $n < (int) ($d['previously_paid_installments'] ?? 0);
                    $paid = $tx->where('source_id', $r->id)->where('cycle', $cycle)->sum('amount_paisa');
                    $paid = max($paid, $historical ? $amount : 0);
                    $planner[] = ['record_id' => $r->id, 'kind' => $r->kind, 'type' => $type, 'name' => $r->name, 'cycle' => $cycle, 'date' => $due, 'amount_paisa' => $amount, 'paid_paisa' => $paid, 'left_paisa' => max(0, $amount - $paid), 'previously_paid' => $historical];
                }
            }
            if ($r->kind === 'cards' && substr($d['due_date'], 0, 7) <= $through && $d['statement_paisa'] > 0) {
                $paid = $tx->where('type', 'card_payment')->where('card_id', $r->id)->where('statement_date', $d['statement_date'])->sum('amount_paisa');
                $planner[] = ['record_id' => $r->id, 'kind' => 'cards', 'type' => 'card_payment', 'name' => $r->name, 'cycle' => $d['statement_date'], 'date' => $d['due_date'], 'amount_paisa' => $d['statement_paisa'], 'paid_paisa' => $paid, 'left_paisa' => max(0, $d['statement_paisa'] - $paid)];
            }
        }
        usort($planner, fn ($a, $b) => strcmp($a['date'], $b['date']));
        $recordBalances = [];
        foreach ($records as $r) {
            $recordBalances[$r->id] = match ($r->kind) {
                'accounts' => $this->balances->account($r, $tx),'loans' => $this->balances->loan($r, $tx),'cards' => $this->balances->card($r, $tx),'savings' => $this->balances->saving($r, $tx),default => 0
            };
        }

        return ['records' => $records, 'transactions' => $tx, 'balances' => $recordBalances, 'planner' => $planner, 'summary' => $this->balances->summary($records, $tx, $from->format('Y-m-d'), $to->format('Y-m-d')), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
    }
}
