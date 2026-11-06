<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('google_id')->nullable()->unique();
            $t->string('password')->nullable()->change();
        });
        Schema::create('finance_records', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 24);
            $t->string('name', 120);
            $t->json('data');
            $t->timestamps();
            $t->index(['user_id', 'kind']);
        });
        Schema::create('transactions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('type', 32);
            $t->string('name', 120);
            $t->string('category', 60)->nullable();
            $t->date('date');
            $t->bigInteger('amount_paisa');
            $t->bigInteger('principal_paisa')->default(0);
            $t->bigInteger('interest_paisa')->default(0);
            foreach (['account_id', 'destination_id', 'loan_id', 'card_id', 'saving_id', 'source_id'] as $column) {
                $t->foreignUuid($column)->nullable()->constrained('finance_records')->restrictOnDelete();
            }
            $t->string('cycle', 7)->nullable();
            $t->date('statement_date')->nullable();
            $t->uuid('idempotency_key');
            $t->string('request_hash', 64);
            $t->timestamps();
            $t->unique(['user_id', 'idempotency_key']);
            $t->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('finance_records');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('google_id'));
    }
};
