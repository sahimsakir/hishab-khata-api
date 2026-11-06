<?php

namespace App\Services;

use App\Contracts\FinanceRepository;
use App\Contracts\UserRepository;
use App\Support\Money;
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
            $money = ['required', 'string', 'regex:/^(0|[1-9]\d{0,9})(\.\d{1,2})?$/'];
            $schedule = ['day' => 'required|integer|min:1|max:31', 'start' => 'required|date_format:Y-m', 'end' => 'nullable|date_format:Y-m|after_or_equal:start'];
            $rules = match ($input['kind']) {
                'accounts' => ['type' => 'required|in:Cash,Bank,Mobile wallet,Business account,Other', 'opening' => $money],
                'incomes' => $schedule + ['type' => 'required|in:Salary,Business profit,Freelance,Rental income,Dividend / interest,Other', 'amount' => $money, 'account_id' => 'required|uuid'],
                'bills' => $schedule + ['amount' => $money, 'category' => 'required|string|max:60', 'account_id' => 'required|uuid'],
                'loans' => $schedule + ['type' => 'required|string|max:40', 'lender' => 'required|string|max:120', 'opening' => $money, 'emi' => $money, 'rate' => 'required|numeric|min:0|max:100', 'term' => 'required|integer|min:1|max:600'],
                'cards' => ['bank' => 'required|string|max:120', 'limit' => $money, 'opening' => $money, 'statement' => $money, 'minimum' => $money, 'statement_date' => 'required|date_format:Y-m-d', 'due_date' => 'required|date_format:Y-m-d|after_or_equal:statement_date'],
                'savings' => $schedule + ['type' => 'required|in:DPS,FDR,SIP,Goal', 'bank' => 'required|string|max:120', 'target' => $money, 'opening' => $money, 'monthly' => $money, 'maturity' => (($input['data']['maturity_mode'] ?? 'exact') === 'duration' ? 'nullable|date_format:Y-m-d' : 'required|date_format:Y-m-d'), 'maturity_mode' => 'nullable|in:duration,exact', 'term_value' => 'required_if:maturity_mode,duration|nullable|integer|min:1|max:1200', 'term_unit' => 'required_if:maturity_mode,duration|nullable|in:months,years', 'annual_rate' => 'nullable|numeric|min:'.(($input['data']['type'] ?? '') === 'SIP' ? '-99.99' : '0').'|max:100', 'compounding' => 'nullable|in:simple,monthly,quarterly,yearly', 'projection_start' => 'nullable|date_format:Y-m-d'],
                'budgets' => ['category' => 'required|string|max:60', 'limit' => $money],
            };
            $data = Validator::make($input['data'], $rules)->validate();
            if ($input['kind'] === 'savings' && ($data['maturity_mode'] ?? null) === 'duration') {
                $months = (int) $data['term_value'] * ($data['term_unit'] === 'years' ? 12 : 1);
                $this->check($months <= 1200, 'Maturity duration cannot exceed 100 years or 1200 months.');
                $firstMonth = CarbonImmutable::createFromFormat('!Y-m-d', $data['start'].'-01');
                $firstDue = $firstMonth->setDay(min((int) $data['day'], $firstMonth->daysInMonth));
                $data['maturity'] = $firstDue->addMonthsNoOverflow($months)->format('Y-m-d');
                $data['term_value'] = (int) $data['term_value'];
            }

            foreach ($data as $key => $value) {
                if (in_array($key, ['opening', 'amount', 'emi', 'limit', 'statement', 'minimum', 'target', 'monthly'])) {
                    unset($data[$key]);
                    $data[$key.'_paisa'] = Money::parse($value);
                }
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
