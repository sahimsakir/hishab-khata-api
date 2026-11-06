<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => 'required|in:income,expense,saving,savings_withdrawal,loan_payment,card_payment,transfer,loan_received',
            'name' => 'required|string|max:120', 'category' => 'nullable|string|max:60', 'date' => 'required|date_format:Y-m-d',
            'amount' => ['required', 'string', 'regex:/^(0|[1-9]\d{0,9})(\.\d{1,2})?$/'],
            'principal' => ['nullable', 'string', 'regex:/^(0|[1-9]\d{0,9})(\.\d{1,2})?$/'],
            'account_id' => 'required|uuid', 'destination_id' => 'nullable|uuid', 'loan_id' => 'nullable|uuid', 'card_id' => 'nullable|uuid', 'saving_id' => 'nullable|uuid', 'source_id' => 'nullable|uuid',
            'cycle' => ['nullable', 'date_format:Y-m'], 'statement_date' => 'nullable|date_format:Y-m-d', 'idempotency_key' => 'required|uuid',
        ];
    }
}
