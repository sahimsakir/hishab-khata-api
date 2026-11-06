<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class InstallmentSchedule
{
    /** Ordered contractual due dates, including the cutoff date. */
    public static function dates(string $kind, array $data, string $through): array
    {
        $month = CarbonImmutable::parse($data['start'].'-01');
        $rows = [];
        for ($n = 0; $n < ($kind === 'savings' ? 1200 : 600) && $month->format('Y-m') <= substr($through, 0, 7); $n++, $month = $month->addMonth()) {
            $cycle = $month->format('Y-m');
            if ((! empty($data['end']) && $cycle > $data['end']) ||
                ($kind === 'loans' && $n >= $data['term']) ||
                ($kind === 'savings' && isset($data['installment_count']) && $n >= $data['installment_count'])) {
                break;
            }
            $due = $month->setDay(min((int) $data['day'], $month->daysInMonth))->format('Y-m-d');
            if ($kind === 'savings' && $n === 0 && ! empty($data['first_payment'])) {
                $due = $data['first_payment'];
            }
            if ($kind === 'savings' && ($due > $data['maturity'] ||
                (($data['maturity_mode'] ?? null) === 'duration' && ! isset($data['installment_count']) && $due === $data['maturity']))) {
                break;
            }
            if ($due <= $through) {
                $rows[] = ['cycle' => $cycle, 'date' => $due];
            }
        }
        return $rows;
    }
}
