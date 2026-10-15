<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pembagian satu pembayaran ke item tagihan menurut prioritas jenis biaya.
        // Tidak pernah diubah atau dihapus; berlaku selama pembayarannya `diterima`.
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_item_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->timestamps();

            $table->unique(['payment_id', 'invoice_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
