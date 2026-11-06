<?php

namespace App\Support;

use Illuminate\Support\Collection;

class InstallmentPayments
{
    /** Count ledger payments once, including older dashboard savings contributions. */
    public static function paid($record, Collection $transactions, string $cycle): int
    {
        return $transactions->filter(function ($transaction) use ($record, $cycle) {
            if ($transaction->source_id === $record->id) {
                return $transaction->cycle === $cycle;
            }

            // Older dashboard entries have a savings plan but no schedule link.
            // Match only that plan and the calendar month of the actual payment.
            return $record->kind === 'savings'
                && ! $transaction->source_id
                && $transaction->type === 'saving'
                && $transaction->saving_id === $record->id
                && substr($transaction->date, 0, 7) === $cycle;
        })->sum('amount_paisa');
    }
}
