<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menutup utang F004: `fee_rates.admission_wave_id` kini ber-FK. Di SQLite,
     * Laravel membangun ulang tabel; kolom generated dan unique kombinasi ikut
     * dibuat ulang (dibuktikan test FeeRateWaveForeignKeyTest).
     */
    public function up(): void
    {
        Schema::table('fee_rates', function (Blueprint $table) {
            $table->foreign('admission_wave_id')->references('id')->on('admission_waves')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fee_rates', function (Blueprint $table) {
            $table->dropForeign(['admission_wave_id']);
        });
    }
};
