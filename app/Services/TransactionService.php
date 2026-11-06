<?php

namespace App\Services;

use App\Contracts\FinanceRepository;
use App\Contracts\UserRepository;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function __construct(private FinanceRepository $repo, private UserRepository $users, private BalanceService $balances) {}

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['transaction' => $message]);
        }
    }

    public function save(int $uid, array $input, ?string $id = null)
    {
        return DB::transaction(function () use ($uid, $input, $id) {
            // Serialize one user's ledger changes so simultaneous payments cannot over-repay a loan.
            $this->users->lock($uid);
            $all = $this->repo->transactions($uid);
            $old = $id ? $this->repo->transaction($uid, $id) : null;
            $fingerprint = $input;
            unset($fingerprint['idempotency_key']);
            $fingerprint['amount'] = Money::parse($input['amount']);
            if (isset($fingerprint['principal'])) {
                $fingerprint['principal'] = Money::parse($fingerprint['principal']);
            }
            ksort($fingerprint);
            $requestHash = hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));
            $duplicate = $all->firstWhere('idempotency_key', $input['idempotency_key']);
            if (! $id && $duplicate) {
                $same = hash_equals($duplicate->request_hash, $requestHash);
                $this->check($same, 'This request key was already used for a different transaction.');

                return $duplicate;
            }
            $tx = $all->reject(fn ($t) => $t->id === $id);
            $type = $input['type'];
            $amount = Money::parse($input['amount']);
            $this->check($amount > 0, 'Amount must be greater than zero.');
            $account = $this->repo->record($uid, $input['account_id']);
            $this->check($account->kind === 'accounts' || ($type === 'expense' && $account->kind === 'cards'), 'Use a money account, or a credit card only for a purchase.');
            $data = ['type' => $type, 'name' => $input['name'], 'category' => $input['category'] ?? null, 'date' => $input['date'], 'amount_paisa' => $amount, 'principal_paisa' => 0, 'interest_paisa' => 0, 'account_id' => $account->id, 'destination_id' => null, 'loan_id' => null, 'card_id' => null, 'saving_id' => null, 'source_id' => null, 'cycle' => null, 'statement_date' => null, 'request_hash' => $requestHash, 'idempotency_key' => $old?->idempotency_key ?? $input['idempotency_key']];
            if ($type === 'transfer') {
                $to = $this->repo->record($uid, $input['destination_id'] ?? '');
                $this->check($to->kind === 'accounts' && $to->id !== $account->id, 'Choose a different destination money account.');
                $data['destination_id'] = $to->id;
            }
            if ($type === 'loan_payment') {
                $loan = $this->repo->record($uid, $input['loan_id'] ?? '');
                $this->check($loan->kind === 'loans', 'Select a loan.');
                $principal = Money::parse($input['principal'] ?? '0');
                $this->check($principal <= $amount, 'Principal cannot exceed the payment.');
                $this->check($principal <= $this->balances->loan($loan, $tx), 'Principal exceeds the remaining loan.');
                $data['loan_id'] = $loan->id;
                $data['principal_paisa'] = $principal;
                $data['interest_paisa'] = $amount - $principal;
            }
            if (in_array($type, ['saving', 'savings_withdrawal'])) {
                $saving = $this->repo->record($uid, $input['saving_id'] ?? '');
                $this->check($saving->kind === 'savings', 'Select a savings plan.');
                if ($type === 'savings_withdrawal') {
                    $this->check($amount <= $this->balances->saving($saving, $tx), 'Withdrawal exceeds the savings balance.');
                }$data['saving_id'] = $saving->id;
            }
            if ($type === 'card_payment') {
                $card = $this->repo->record($uid, $input['card_id'] ?? '');
                $this->check($card->kind === 'cards', 'Select a credit card.');
                $data['card_id'] = $card->id;
                $data['statement_date'] = $input['statement_date'] ?? $card->data['statement_date'];
            }
            if (! empty($input['source_id'])) {
                $source = $this->repo->record($uid, $input['source_id']);
                $expected = match ($type) {
                    'income' => 'incomes','expense' => 'bills','loan_payment' => 'loans','saving' => 'savings',default => ''
                };
                $this->check($source->kind === $expected, 'Scheduled source does not match the transaction type.');
                if ($type === 'loan_payment') {
                    $this->check($source->id === $data['loan_id'], 'Scheduled loan does not match.');
                }if ($type === 'saving') {
                    $this->check($source->id === $data['saving_id'], 'Scheduled savings plan does not match.');
                }
                $cycle = $input['cycle'] ?? null;
                $this->check((bool) $cycle, 'Select a scheduled month.');
                $this->check($cycle >= $source->data['start'] && (empty($source->data['end']) || $cycle <= $source->data['end']), 'This month is outside the recurring schedule.');
                if (in_array($source->kind, ['loans', 'savings'])) {
                    $index = ((int) substr($cycle, 0, 4) - (int) substr($source->data['start'], 0, 4)) * 12 + (int) substr($cycle, 5, 2) - (int) substr($source->data['start'], 5, 2);
                    $this->check($index >= (int) ($source->data['previously_paid_installments'] ?? 0), 'This installment was already paid before tracking began. Record additional payments separately.');
                }
                if ($source->kind === 'loans') {
                    $months = ((int) substr($cycle, 0, 4) - (int) substr($source->data['start'], 0, 4)) * 12 + (int) substr($cycle, 5, 2) - (int) substr($source->data['start'], 5, 2);
                    $this->check($months < $source->data['term'], 'This month is beyond the loan term.');
                }
                if ($source->kind === 'savings') {
                    $this->check($cycle <= substr($source->data['maturity'], 0, 7), 'This month follows the maturity date.');
                    if (isset($source->data['installment_count'])) {
                        $months = ((int) substr($cycle, 0, 4) - (int) substr($source->data['start'], 0, 4)) * 12 + (int) substr($cycle, 5, 2) - (int) substr($source->data['start'], 5, 2);
                        $this->check($months < $source->data['installment_count'], 'This month is beyond the installment schedule.');
                    }
                }
                $expectedAmount = $source->data[match ($source->kind) {
                    'loans' => 'emi_paisa','savings' => 'monthly_paisa',default => 'amount_paisa'
                }];
                $paid = $tx->where('source_id', $source->id)->where('cycle', $cycle)->sum('amount_paisa');
                $this->check($amount <= $expectedAmount - $paid, 'Payment exceeds the scheduled remainder. Record extra payments separately.');
                $data['source_id'] = $source->id;
                $data['cycle'] = $cycle;
            }
            $saved = $this->repo->saveTransaction($uid, $data, $old);
            // Recheck all dependent balances after edits, including changing a past savings deposit.
            $this->assertBalances($uid);

            return $saved;
        });
    }

    private function assertBalances(int $uid): void
    {
        $tx = $this->repo->transactions($uid);
        foreach ($this->repo->records($uid) as $r) {
            if ($r->kind === 'loans') {
                $this->check($this->balances->loan($r, $tx) >= 0, 'This change would over-repay a loan.');
            }if ($r->kind === 'savings') {
                $this->check($this->balances->saving($r, $tx) >= 0, 'This change would leave negative savings.');
            }
        }
    }

    public function remove(int $uid, string $id): void
    {
        DB::transaction(function () use ($uid, $id) {
            $this->users->lock($uid);
            $this->repo->removeTransaction($this->repo->transaction($uid, $id));
            $this->assertBalances($uid);
        });
    }
}
