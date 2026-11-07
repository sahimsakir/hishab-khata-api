<?php

namespace App\Services;

use App\Contracts\FinanceRepository;
use App\Contracts\UserRepository;
use App\Support\Money;
use App\Support\InstallmentPayments;
use App\Support\InstallmentSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordService
{
    public function __construct(private FinanceRepository $repo, private UserRepository $users, private BalanceService $balances) {}

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['data' => $message]);
        }
    }

    public function save(int $uid, array $input, ?string $id = null)
    {
        return DB::transaction(function () use ($uid, $input, $id) {
            $this->users->lock($uid);
            $record = $id ? $this->repo->record($uid, $id) : null;
            $this->check(! $record || $record->kind === $input['kind'], 'A record type cannot be changed.');
            $input['data'] = $this->prepareData($input['kind'], $input['data']);
            $money = ['required', 'string', 'regex:/^(0|[1-9]\d{0,9})(\.\d{1,2})?$/'];
            $optionalMoney = ['nullable', ...array_slice($money, 1)];
            $schedule = ['day' => 'required|integer|min:1|max:31', 'start' => 'required|date_format:Y-m', 'end' => 'nullable|date_format:Y-m|after_or_equal:start'];
            $rules = match ($input['kind']) {
                'accounts' => ['type' => 'required|in:Cash,Bank,Mobile wallet,Business account,Other', 'opening' => $money, 'institution' => 'nullable|string|max:120'],
                'incomes' => $schedule + ['type' => 'required|in:Salary,Business profit,Freelance,Rental income,Dividend / interest,Other', 'amount' => $money, 'account_id' => 'required|uuid', 'frequency' => 'nullable|in:monthly,once', 'payment_date' => 'nullable|date_format:Y-m-d', 'payer' => 'nullable|string|max:120'],
                'bills' => $schedule + ['amount' => $money, 'category' => 'required|string|max:60', 'account_id' => 'required|uuid', 'frequency' => 'nullable|in:monthly,once', 'payment_date' => 'nullable|date_format:Y-m-d'],
                'loans' => $schedule + ['type' => 'required|string|max:40', 'lender' => 'required|string|max:120', 'opening' => $money, 'emi' => $optionalMoney, 'processing_fee' => $optionalMoney, 'previously_paid_installments' => 'nullable|integer|min:0|max:1200', 'interest_bearing' => 'nullable|in:yes,no', 'rate' => 'required|numeric|min:0|max:100', 'term' => 'required|integer|min:1|max:600'],
                'cards' => ['bank' => 'required|string|max:120', 'limit' => $money, 'opening' => $money, 'statement' => $money, 'minimum' => $money, 'statement_date' => 'required|date_format:Y-m-d', 'due_date' => 'required|date_format:Y-m-d|after_or_equal:statement_date'],
                'savings' => $schedule + ['type' => 'required|in:DPS,FDR,SIP,Goal', 'bank' => 'required|string|max:120', 'target' => $money, 'opening' => $money, 'monthly' => $money, 'bank_accrued_interest' => $optionalMoney, 'maturity' => (($input['data']['maturity_mode'] ?? 'exact') === 'duration' ? 'nullable|date_format:Y-m-d' : 'required|date_format:Y-m-d'), 'maturity_mode' => 'nullable|in:duration,exact', 'term_value' => 'required_if:maturity_mode,duration|nullable|integer|min:1|max:1200', 'term_unit' => 'required_if:maturity_mode,duration|nullable|in:months,years', 'annual_rate' => 'nullable|numeric|min:'.(($input['data']['type'] ?? '') === 'SIP' ? '-99.99' : '0').'|max:100', 'compounding' => 'nullable|in:simple,monthly,quarterly,yearly', 'projection_start' => 'nullable|date_format:Y-m-d', 'opening_date' => 'nullable|date_format:Y-m-d', 'first_payment' => 'nullable|date_format:Y-m-d', 'calculation_basis' => 'nullable|in:monthly_installments,actual_days', 'payment_timing' => 'nullable|in:beginning,end', 'previously_paid_installments' => 'nullable|integer|min:0|max:1200'],
                'budgets' => ['category' => 'required|string|max:60', 'limit' => $money],
            };
            $data = Validator::make($input['data'], $rules, [], ['annual_rate' => 'annual rate', 'opening_date' => 'opening date', 'first_payment' => 'first payment date', 'term_value' => 'tenure', 'term_unit' => 'tenure unit', 'end' => 'last month', 'start' => 'first month', 'projection_start' => 'projection date', 'compounding' => 'interest method', 'account_id' => 'account'])->validate();
            if ($input['kind'] === 'savings' && ($data['maturity_mode'] ?? null) === 'duration') {
                $months = (int) $data['term_value'] * ($data['term_unit'] === 'years' ? 12 : 1);
                $this->check($months <= 1200, 'Maturity duration cannot exceed 100 years or 1200 months.');
                $firstMonth = CarbonImmutable::createFromFormat('!Y-m-d', $data['start'].'-01');
                $firstDue = ! empty($data['opening_date']) ? CarbonImmutable::parse($data['opening_date']) : $firstMonth->setDay(min((int) $data['day'], $firstMonth->daysInMonth));
                $data['maturity'] = $firstDue->addMonthsNoOverflow($months)->format('Y-m-d');
                $data['term_value'] = (int) $data['term_value'];
            }

            foreach ($data as $key => $value) {
                if (in_array($key, ['opening', 'amount', 'emi', 'limit', 'statement', 'minimum', 'target', 'monthly', 'processing_fee', 'bank_accrued_interest'])) {
                    unset($data[$key]);
                    if ($value !== null && $value !== '') {
                        $data[$key.'_paisa'] = Money::parse($value);
                    }
                }
            }
            if ($input['kind'] === 'loans') {
                $data['emi_auto'] = isset($data['emi_paisa']) ? 'no' : 'yes';
            }
            if ($input['kind'] === 'loans' && $data['emi_auto'] === 'yes') {
                $months = (int) $data['term'];
                $monthlyRate = (float) $data['rate'] / 1200;
                $principal = $data['opening_paisa'];
                $payment = $monthlyRate === 0.0
                    ? $principal / $months
                    : $principal * $monthlyRate / -expm1(-$months * log1p($monthlyRate));
                $data['emi_paisa'] = (int) round($payment);
            }
            if ($input['kind'] === 'savings' && ($data['calculation_basis'] ?? null) === 'monthly_installments') {
                $this->check(in_array($data['type'], ['DPS', 'SIP']), 'Monthly installments are available for DPS and SIP.');
                $opening = CarbonImmutable::parse($data['opening_date']);
                $maturity = CarbonImmutable::parse($data['maturity']);
                $months = ($maturity->year - $opening->year) * 12 + $maturity->month - $opening->month;
                $this->check($months >= 1 && $months <= 1200 && $opening->addMonthsNoOverflow($months)->format('Y-m-d') === $data['maturity'], 'Monthly installments require a whole number of months. Choose actual days for irregular dates.');
                $this->check($data['monthly_paisa'] > 0, 'Monthly installment must be greater than zero.');
                $first = CarbonImmutable::parse($data['first_payment']);
                $this->check($first >= $opening && $first < $opening->addMonthNoOverflow(), 'The first installment must fall within the first monthly period.');
                $lastMonth = $first->startOfMonth()->addMonths($months - 1);
                $last = $months === 1 ? $first : $lastMonth->setDay(min((int) $data['day'], $lastMonth->daysInMonth));
                $this->check($last <= $maturity, 'The payment schedule extends beyond maturity. Check the first installment date and payment day.');
                $data['installment_count'] = $months;
                $data['target_paisa'] = $data['monthly_paisa'] * $months + ($data['type'] === 'SIP' ? $data['opening_paisa'] : 0);
            }
            foreach (['day', 'term'] as $key) {
                if (isset($data[$key])) {
                    $data[$key] = (int) $data[$key];
                }
            }
            foreach (['amount_paisa', 'emi_paisa', 'limit_paisa', 'target_paisa'] as $key) {
                if (isset($data[$key])) {
                    $this->check($data[$key] > 0, 'Expected amounts, EMI, limits, and targets must be greater than zero.');
                }
            }
            if ($input['kind'] === 'budgets') {
                foreach ($this->repo->records($uid) as $other) {
                    $this->check($other->kind !== 'budgets' || $other->id === $id || ($other->data['category'] ?? null) !== $data['category'], 'A budget already exists for this category. Edit that budget instead.');
                }
            }
            if (isset($data['account_id'])) {
                $this->check($this->repo->record($uid, $data['account_id'])->kind === 'accounts', 'Select a cash, bank, or wallet account.');
            }
            if ($input['kind'] === 'cards') {
                $this->check($data['minimum_paisa'] <= $data['statement_paisa'], 'Minimum due cannot exceed statement balance.');
            }
            if ($input['kind'] === 'savings') {
                $this->check(substr($data['maturity'], 0, 7) >= $data['start'], 'Maturity must follow the first scheduled month.');
                if (isset($data['annual_rate'])) {
                    $this->check(! empty($data['projection_start']) && ! empty($data['compounding']), 'Choose a projection start date and growth calculation when entering an annual rate.');
                    $this->check($data['maturity'] >= $data['projection_start'], 'Maturity must be on or after the projection start date.');
                    $this->check((int) substr($data['maturity'], 0, 4) - (int) substr($data['projection_start'], 0, 4) <= 100, 'Projection duration cannot exceed 100 years.');
                    $this->check($data['type'] !== 'FDR' || $data['monthly_paisa'] === 0, 'Use zero monthly contribution for a one-time FDR.');
                    $data['annual_rate'] = (float) $data['annual_rate'];
                }
            }
            if (in_array($input['kind'], ['loans', 'savings'])) {
                $history = (int) ($data['previously_paid_installments'] ?? 0);
                $eligibleType = $input['kind'] === 'loans' || in_array($data['type'], ['DPS', 'SIP']);
                $this->check($history === 0 || $eligibleType, 'Previously paid installments apply only to loans, DPS, and SIP.');
                $today = CarbonImmutable::now('Asia/Dhaka')->format('Y-m-d');
                $eligible = InstallmentSchedule::dates($input['kind'], $data, $today);
                $this->check($history <= count($eligible), 'Previously paid installments cannot exceed the '.count($eligible).' installments due by '.$today.'.');
                if ($history > 0 && $record) {
                    $cycles = array_column(array_slice($eligible, 0, $history), 'cycle');
                    $overlap = collect($cycles)->contains(fn ($cycle) => InstallmentPayments::paid($record, $this->repo->transactions($uid), $cycle) > 0);
                    $this->check(! $overlap, 'These installments already have recorded payments. Exclude recorded installments from opening payment history.');
                }
                if ($eligibleType) {
                    $data['previously_paid_installments'] = $history;
                    if ($history > 0) {
                        $data['history_recorded_on'] = $record && (int) ($record->data['previously_paid_installments'] ?? 0) === $history
                            ? ($record->data['history_recorded_on'] ?? $today) : $today;
                    }
                } else {
                    unset($data['previously_paid_installments']);
                }
            }
            if ($record) {
                $copy = clone $record;
                $copy->data = $data;
                $tx = $this->repo->transactions($uid);
                if ($record->kind === 'loans') {
                    $this->check($this->balances->loan($copy, $tx) >= 0, 'Opening principal cannot be below principal repaid.');
                }if ($record->kind === 'savings') {
                    $this->check($this->balances->saving($copy, $tx) >= 0, 'This edit would make savings negative.');
                }
            }

            return $this->repo->saveRecord($uid, ['kind' => $input['kind'], 'name' => $input['name'], 'data' => $data], $record);
        });
    }

    private function prepareData(string $kind, array $raw): array
    {
        if (in_array($kind, ['incomes', 'bills'])) {
            Validator::make($raw, ['frequency' => 'nullable|in:monthly,once', 'payment_date' => 'required_if:frequency,once|nullable|date_format:Y-m-d'])->validate();
            if (($raw['frequency'] ?? null) === 'once') {
                $raw['start'] = substr($raw['payment_date'], 0, 7);
                $raw['end'] = $raw['start'];
                $raw['day'] = (int) substr($raw['payment_date'], 8, 2);
            } else {
                unset($raw['payment_date']);
            }
        }
        if ($kind === 'accounts' && ! in_array($raw['type'] ?? '', ['Bank', 'Business account', 'Mobile wallet'])) {
            unset($raw['institution']);
        }
        if ($kind === 'loans' && ($raw['type'] ?? '') === 'Family / friend' && ($raw['interest_bearing'] ?? null) === 'no') {
            $raw['rate'] = 0;
        }
        if ($kind !== 'savings') {
            return $raw;
        }
        Validator::make($raw, ['opening_date' => 'nullable|date_format:Y-m-d', 'first_payment' => 'nullable|date_format:Y-m-d', 'calculation_basis' => 'nullable|in:monthly_installments,actual_days', 'payment_timing' => 'nullable|in:beginning,end'])->validate();
        if (! empty($raw['opening_date'])) {
            $type = $raw['type'] ?? '';
            if ($type === 'FDR') {
                $raw['monthly'] = '0';
                $raw['start'] = substr($raw['opening_date'], 0, 7);
                $raw['day'] = (int) substr($raw['opening_date'], 8, 2);
                $raw['projection_start'] = $raw['opening_date'];
                $raw['calculation_basis'] = 'actual_days';
                unset($raw['first_payment'], $raw['payment_timing']);
            } else {
                $raw['first_payment'] = $raw['first_payment'] ?? $raw['opening_date'];
                $this->check($raw['first_payment'] >= $raw['opening_date'], 'The first payment date cannot precede the opening date.');
                $raw['start'] = substr($raw['first_payment'], 0, 7);
                if ($type === 'Goal') {
                    $raw['bank'] = 'Personal savings';
                    $raw['calculation_basis'] = 'actual_days';
                    unset($raw['annual_rate'], $raw['compounding'], $raw['payment_timing']);
                    $raw['projection_start'] = $raw['opening_date'];
                } elseif (($raw['calculation_basis'] ?? null) === 'monthly_installments') {
                    $raw['compounding'] = 'monthly';
                    $raw['projection_start'] = $raw['opening_date'];
                    $raw['target'] = '1'; // Canonical total is derived from validated installments.
                    $raw['payment_timing'] = $raw['payment_timing'] ?? 'beginning';
                }
            }
        } elseif (($raw['calculation_basis'] ?? null) === 'monthly_installments') {
            $this->check(false, 'Opening date is required for monthly installments.');
        }
        return $raw;
    }

    public function remove(int $uid, string $id): void
    {
        DB::transaction(function () use ($uid, $id) {
            $this->users->lock($uid);
            $r = $this->repo->record($uid, $id);
            foreach ($this->repo->transactions($uid) as $t) {
                foreach (['account_id', 'destination_id', 'loan_id', 'card_id', 'saving_id', 'source_id'] as $key) {
                    $this->check($id !== $t->$key, 'This record has transactions. Keep it for historical reporting.');
                }
            }foreach ($this->repo->records($uid) as $record) {
                $this->check(($record->data['account_id'] ?? null) !== $id, 'This account is used by a scheduled income or bill.');
            }$this->repo->removeRecord($r);
        });
    }
}
