<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Item tagihan per jenis biaya. Nominal adalah snapshot: tidak berubah
        // meskipun tarif atau keringanan diubah setelah tagihan dibuat.
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('fee_type_id')->constrained()->restrictOnDelete();
            $table->string('description');
            // Selalu base_amount - discount_amount = amount.
            $table->unsignedBigInteger('base_amount');
            $table->unsignedBigInteger('discount_amount');
            $table->unsignedBigInteger('amount');
            $table->enum('rate_source', ['angkatan', 'jenjang', 'manual']);
            $table->foreignId('fee_override_id')->nullable()->constrained()->restrictOnDelete();
            // Hasil alokasi pembayaran, diisi F007.
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->timestamps();

            $table->unique(['invoice_id', 'fee_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
