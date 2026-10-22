<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kunjungan kesehatan santri (F031). Keluhan, tindakan, dan catatan terenkripsi (cast `encrypted`).
        Schema::create('health_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->dateTime('visited_at');
            $table->text('complaint');
            $table->text('treatment')->nullable();
            $table->text('note')->nullable();
            $table->boolean('needs_referral')->default(false);
            $table->boolean('needs_leave')->default(false);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'visited_at']);
            $table->index('visited_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_visits');
    }
};
