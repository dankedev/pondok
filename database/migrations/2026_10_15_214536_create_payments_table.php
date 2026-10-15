<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pembayaran satu tagihan. Data keuangan: tidak dihapus, salah input dibatalkan
        // lewat status `batal` (baris, nomor kuitansi, dan alokasinya tetap ada).
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // `KW-YYYYMM-NNNNN`, urut per bulan pencatatan.
            $table->string('receipt_no', 20)->unique();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            // Tanggal uang diterima; boleh mundur, tidak boleh masa depan (dijaga Action).
            $table->date('paid_on');
            // `gateway` dicadangkan untuk F020.
            $table->enum('method', ['tunai', 'transfer', 'gateway']);
            // Path relatif di disk `private`.
            $table->string('proof_path')->nullable();
            $table->string('gateway_ref')->nullable();
            // Nullable untuk callback gateway (F020); pencatatan manual selalu mengisinya.
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            // Tambahan di luar ERD awal: pembatalan tanpa menghapus.
            $table->enum('status', ['diterima', 'batal'])->default('diterima');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'status']);
            $table->index(['status', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
