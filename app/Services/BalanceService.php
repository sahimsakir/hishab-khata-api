<?php

namespace App\Services;

use Illuminate\Support\Collection;

class BalanceService
{
    public function account($record, Collection $tx): int
    {
        $balance = $record->data['opening_paisa'];
        foreach ($tx as $t) {
            if ($t->account_id === $record->id) {
                $balance += in_array($t->type, ['income', 'loan_received', 'savings_withdrawal']) ? $t->amount_paisa : -$t->amount_paisa;
            }
            if ($t->type === 'transfer' && $t->destination_id === $record->id) {
                $balance += $t->amount_paisa;
            }
        }

        return $balance;
    }

    public function loan($record, Collection $tx): int
    {
        return $record->data['opening_paisa'] - $tx->where('type', 'loan_payment')->where('loan_id', $record->id)->sum('principal_paisa');
    }

    public function card($record, Collection $tx): int
    {
        return $record->data['opening_paisa'] + $tx->where('type', 'expense')->where('account_id', $record->id)->sum('amount_paisa') - $tx->where('type', 'card_payment')->where('card_id', $record->id)->sum('amount_paisa');
    }

    public function saving($record, Collection $tx): int
    {
        return $record->data['opening_paisa'] + $tx->where('type', 'saving')->where('saving_id', $record->id)->sum('amount_paisa') - $tx->where('type', 'savings_withdrawal')->where('saving_id', $record->id)->sum('amount_paisa');
    }

    public function summary(Collection $records, Collection $transactions, string $from, string $to): array
    {
        $asOf = $transactions->filter(fn ($t) => $t->date <= $to);
        $period = $asOf->filter(fn ($t) => $t->date >= $from);
        $byKind = $records->groupBy('kind');
        $cash = ($byKind['accounts'] ?? collect())->sum(fn ($r) => $this->account($r, $asOf));
        $savings = ($byKind['savings'] ?? collect())->sum(fn ($r) => $this->saving($r, $asOf));
        $loans = ($byKind['loans'] ?? collect())->sum(fn ($r) => $this->loan($r, $asOf));
        $cards = ($byKind['cards'] ?? collect())->sum(fn ($r) => $this->card($r, $asOf));
        $cardIds = ($byKind['cards'] ?? collect())->pluck('id')->all();
        $income = $period->where('type', 'income')->sum('amount_paisa');
        $expenses = $period->where('type', 'expense')->sum('amount_paisa');
        $interest = $period->where('type', 'loan_payment')->sum('interest_paisa');
        $debt = $period->whereIn('type', ['loan_payment', 'card_payment'])->sum('amount_paisa');
        $contributions = $period->where('type', 'saving')->sum('amount_paisa') - $period->where('type', 'savings_withdrawal')->sum('amount_paisa');
        $cashExpenses = $period->where('type', 'expense')->whereNotIn('account_id', $cardIds)->sum('amount_paisa');

        return ['cash_paisa' => $cash, 'savings_paisa' => $savings, 'loans_paisa' => $loans, 'cards_paisa' => $cards, 'net_worth_paisa' => $cash + $savings - $loans - $cards,
            'income_paisa' => $income, 'spending_paisa' => $expenses + $interest, 'interest_paisa' => $interest, 'debt_payments_paisa' => $debt, 'net_savings_paisa' => $contributions,
            'cash_movement_paisa' => $income - $cashExpenses - $debt - $contributions + $period->where('type', 'loan_received')->sum('amount_paisa')];
    }
}
