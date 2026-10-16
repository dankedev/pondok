<?php

use App\Models\Post;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Berita, kegiatan, dan prestasi (kolom `type`). Soft delete; slug unik termasuk baris terhapus.
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            // Penulis boleh kosong agar menghapus user tidak menghapus atau menahan beritanya.
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 10);
            $table->string('title');
            $table->string('slug', Post::SLUG_MAX_LENGTH)->unique();
            $table->string('excerpt')->nullable();
            $table->longText('body');
            $table->string('cover_path')->nullable();
            $table->string('youtube_url')->nullable();
            // FK ke `achievements` ditambahkan oleh modul prestasi (F015).
            $table->unsignedBigInteger('achievement_id')->nullable()->index();
            $table->json('meta')->nullable();
            $table->string('status', 10)->default(Post::STATUS_DRAFT);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
