<?php

namespace App\Actions\Prestasi;

use App\Actions\Konten\SaveCategory;
use App\Actions\Konten\SavePost;
use App\Actions\Prestasi\Concerns\FailsWithPrestasiErrors;
use App\Models\Achievement;
use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Membuat draf berita berkategori "Prestasi" dari prestasi publik terverifikasi yang
 * belum punya berita (termasuk yang dihapus). Isi disusun dari data prestasi
 * lalu disimpan lewat `SavePost`; berita tidak disinkronkan dengan perubahan
 * prestasi sesudahnya. Peserta dibaca tanpa cakupan jenjang agar seluruh tim
 * tercantum. Jenjang berita = jenjang bersama peserta, selain itu umum.
 */
class PublishAchievementAsPost
{
    use FailsWithPrestasiErrors;

    public function __construct(
        private readonly SavePost $savePost,
        private readonly SaveCategory $saveCategory,
    ) {}

    public function handle(Achievement $achievement, User $actor): Post
    {
        if (! $achievement->is_public) {
            $this->fail('achievement', 'not_public');
        }

        if (! $achievement->is_verified) {
            $this->fail('achievement', 'not_verified');
        }

        if ($achievement->post()->exists()) {
            $this->fail('achievement', 'already_published');
        }

        $participants = $achievement->students()->withoutGlobalScopes()->orderBy('name')->orderBy('students.id')->get();
        $units = $participants->pluck('unit_id')->unique();
        $level = __("pondok.achievement.levels.{$achievement->level}");
        $date = $achievement->achieved_on->locale('id')->translatedFormat('j M Y');

        // Exception dari SavePost (termasuk slug_taken) diteruskan apa adanya.
        $post = $this->savePost->handle([
            'status' => Post::STATUS_DRAFT,
            'published_at' => null,
            'category_id' => $this->achievementCategory()->id,
            'unit_id' => $units->count() === 1 ? $units->first() : null,
            'title' => $achievement->title,
            'excerpt' => Str::limit(__('pondok.achievement.post_excerpt', ['event' => $achievement->event_name, 'level' => $level, 'date' => $date]), 255, ''),
            'body' => view('prestasi.post-body', ['achievement' => $achievement, 'participants' => $participants, 'level' => $level, 'date' => $date])->render(),
        ], $actor);

        // Balapan dua klik: unique `posts.achievement_id` menolak berita kedua. Draf yang baru
        // dibuat dihapus lagi (kompensasi, bukan rollback, agar aman di Postgres yang
        // membatalkan seluruh transaksi setelah satu statement gagal).
        try {
            $post->forceFill(['achievement_id' => $achievement->id])->save();
        } catch (UniqueConstraintViolationException) {
            $post->forceDelete();

            $this->fail('achievement', 'already_published');
        }

        return $post;
    }

    /**
     * Kategori berita "Prestasi": dicari lewat nama atau slug, dibuat bila belum ada.
     */
    private function achievementCategory(): Category
    {
        return Category::query()
            ->ofType(Category::TYPE_POST)
            ->where(fn ($query) => $query->where('slug', 'prestasi')->orWhereRaw('lower(name) = ?', ['prestasi']))
            ->orderBy('id')
            ->first()
            ?? $this->saveCategory->handle(['type' => Category::TYPE_POST, 'name' => 'Prestasi']);
    }
}
