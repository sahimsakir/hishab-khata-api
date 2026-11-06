<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['kind' => 'required|in:accounts,incomes,loans,cards,savings,bills,budgets', 'name' => 'required|string|max:120', 'data' => 'required|array'];
    }
}
