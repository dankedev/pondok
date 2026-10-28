<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // KKM opsional per mapel diniyah (F096); rentang 0-100 dijaga validasi form.
        Schema::table('diniyah_subjects', function (Blueprint $table) {
            $table->unsignedTinyInteger('passing_score')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('diniyah_subjects', function (Blueprint $table) {
            $table->dropColumn('passing_score');
        });
    }
};
