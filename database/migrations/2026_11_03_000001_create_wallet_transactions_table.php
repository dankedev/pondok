<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ledger uang saku santri (F028). Data keuangan: tidak dihapus, salah catat dibatalkan
        // lewat status `batal`. Saldo dihitung dari ledger, tidak disimpan.
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->unsignedBigInteger('amount');
            $table->date('occurred_on');
            $table->string('note')->nullable();
            $table->string('status', 10)->default('berhasil');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status']);
            $table->index(['status', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
