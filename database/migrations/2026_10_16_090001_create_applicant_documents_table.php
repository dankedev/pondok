<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu berkas per pendaftar per syarat; unggah ulang menimpa baris (file di disk `private`).
        Schema::create('applicant_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained()->restrictOnDelete();
            $table->foreignId('admission_requirement_id')->constrained()->restrictOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_kb');
            $table->enum('status', ['menunggu', 'valid', 'ditolak'])->default('menunggu');
            $table->text('note')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['applicant_id', 'admission_requirement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_documents');
    }
};
