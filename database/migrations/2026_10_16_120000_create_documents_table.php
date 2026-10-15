<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Repositori dokumen santri, wali, dan staff (polymorphic, alias morph). Versi baru menambah
        // baris; baris lama ditandai `replaced_by_id`. Berkas di disk `private`, tidak pernah dihapus.
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->string('documentable_type', 50);
            $table->unsignedBigInteger('documentable_id');
            // Terenkripsi (cast `encrypted`): ciphertext lebih panjang dari 255 karakter.
            $table->text('number')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_kb');
            $table->enum('status', ['menunggu', 'valid', 'ditolak'])->default('menunggu');
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('replaced_by_id')->nullable()->unique()->constrained('documents')->restrictOnDelete();
            // FK ke `applicant_documents` ditambahkan saat penyalinan berkas PSB (F012).
            $table->unsignedBigInteger('source_applicant_document_id')->nullable()->index();
            $table->timestamps();

            $table->index(['documentable_type', 'documentable_id', 'document_type_id'], 'documents_owner_type_index');
            $table->index('expires_on');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
