<?php

namespace App\Repositories;

use App\Contracts\FinanceRepository;
use App\Models\FinanceRecord;
use App\Models\Transaction;
use Illuminate\Support\Collection;

class EloquentFinanceRepository implements FinanceRepository
{
    public function records(int $userId): Collection
    {
        return FinanceRecord::where('user_id', $userId)->orderBy('created_at')->get();
    }

    public function record(int $userId, string $id): FinanceRecord
    {
        return FinanceRecord::where('user_id', $userId)->findOrFail($id);
    }

    public function saveRecord(int $userId, array $data, ?FinanceRecord $record = null): FinanceRecord
    {
        $record ??= new FinanceRecord;
        $record->fill($data);
        $record->user_id = $userId;
        $record->save();

        return $record->fresh();
    }

    public function removeRecord(FinanceRecord $record): void
    {
        $record->delete();
    }

    public function transactions(int $userId): Collection
    {
        return Transaction::where('user_id', $userId)->orderByDesc('date')->orderByDesc('created_at')->get();
    }

    public function transaction(int $userId, string $id): Transaction
    {
        return Transaction::where('user_id', $userId)->findOrFail($id);
    }

    public function saveTransaction(int $userId, array $data, ?Transaction $transaction = null): Transaction
    {
        $transaction ??= new Transaction;
        $transaction->fill($data);
        $transaction->user_id = $userId;
        $transaction->save();

        return $transaction->fresh();
    }

    public function removeTransaction(Transaction $transaction): void
    {
        $transaction->delete();
    }
}
