<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pendaftar PSB tanpa akun. Akses publik lewat nomor pendaftaran + token
        // (yang disimpan hanya hash-nya). Soft delete untuk pendaftaran spam/ganda.
        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_wave_id')->constrained()->restrictOnDelete();
            $table->foreignId('admission_track_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('registration_no', 20)->unique();
            $table->string('access_token_hash');
            $table->string('name');
            $table->string('nisn', 10)->nullable();
            $table->enum('gender', ['L', 'P']);
            $table->string('birth_place', 100);
            $table->date('birth_date');
            $table->string('previous_school');
            $table->string('guardian_name');
            // Tanpa format, awalan 08.
            $table->string('guardian_phone', 20);
            $table->string('email')->nullable();
            $table->enum('status', ['draft', 'diajukan', 'diverifikasi', 'diterima', 'cadangan', 'ditolak'])->default('diajukan');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            // Bukti persetujuan pemrosesan data pribadi (UU PDP), diisi server saat pendaftaran publik.
            $table->timestamp('consented_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['admission_wave_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicants');
    }
};
