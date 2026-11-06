<?php

namespace App\Contracts;

use App\Models\FinanceRecord;
use App\Models\Transaction;
use Illuminate\Support\Collection;

interface FinanceRepository
{
    public function records(int $userId): Collection;

    public function record(int $userId, string $id): FinanceRecord;

    public function saveRecord(int $userId, array $data, ?FinanceRecord $record = null): FinanceRecord;

    public function removeRecord(FinanceRecord $record): void;

    public function transactions(int $userId): Collection;

    public function transaction(int $userId, string $id): Transaction;

    public function saveTransaction(int $userId, array $data, ?Transaction $transaction = null): Transaction;

    public function removeTransaction(Transaction $transaction): void;
}
