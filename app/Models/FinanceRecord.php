<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FinanceRecord extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'kind', 'name', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
