<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F052: indeks laporan keuangan. `(status, due_on)` untuk tunggakan/jatuh tempo
 * (`ListTopArrears`, `SummarizeInvoices`), `(period, status)` untuk rentang
 * periode laporan dan widget dashboard (`FinanceReportFilter`). Tanpa perubahan data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['status', 'due_on']);
            $table->index(['period', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['status', 'due_on']);
            $table->dropIndex(['period', 'status']);
        });
    }
};
