<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F040: syarat bayar daftar ulang jadi nominal rupiah per jenjang (`null` = wajib
 * lunas, `0` = tanpa syarat). Setting persen lama `psb.min_paid_percent` dihapus
 * tanpa konversi; `down()` hanya membuang kolom, nilai persen tidak dipulihkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->unsignedBigInteger('min_reregistration_amount')->nullable()->after('sort_order');
        });

        // Query builder tidak memicu event model, jadi cache setting dibersihkan manual.
        DB::table('settings')->where('key', 'psb.min_paid_percent')->delete();
        Setting::forget('psb.min_paid_percent');
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('min_reregistration_amount');
        });
    }
};
