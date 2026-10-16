# F015 prestasi — Prestasi santri

Status rencana: APPROVED

Owner: diklaim orkestrator di `feature_list.json` (PM tidak mengubah file itu pada siklus ini). Dependensi tertulis: F002 (`done`), F013 (rencana APPROVED, belum `done`). **Dependensi nyata tambahan:** F016 (tabel `posts`, `Post`, `SavePost`, `GenerateSlug`, `PostResource`/`ListPosts`) dan F018 (trait `InteractsWithDashboardModule`, kerangka `RecentAchievementsWidget`). Red-tester tidak boleh mulai sebelum F013, F016, dan F018 terimplementasi di repo (lihat Risiko #1).

## Ringkasan & tujuan

Mencatat prestasi santri sebagai data pondok (bukan sekadar berita):

- **Prestasi** (`achievements`) individu atau beregu, dengan kategori, tingkat, tanggal, penyelenggara, pencatat, verifikator, dan penanda boleh dipublikasikan (`is_public`).
- **Peserta** (`achievement_student`): satu prestasi beregu cukup dicatat sekali dan dihubungkan ke banyak santri, masing-masing dengan peringkat dan peran.
- **Sertifikat dan foto** disimpan di repositori dokumen F013 (`documents`, pemilik `achievement`).
- **Verifikasi**: prestasi yang dicatat kesantrian diverifikasi oleh admin sekolah; perubahan isi setelah verifikasi membatalkan verifikasi.
- **Rekap per santri dan per tahun**: tab prestasi di halaman santri, halaman rekap (matriks kategori × tingkat dan daftar santri berprestasi per tahun ajaran).
- **Publikasi sebagai berita**: prestasi publik yang sudah terverifikasi bisa dijadikan draf `posts` bertipe `prestasi` dengan `posts.achievement_id` (FK ditambahkan di F015).
- **Widget dashboard "Prestasi terbaru"** diisi (kerangka dari F018).

Acuan: PRD 2 (Kesantrian: prestasi; Admin Konten: prestasi publik), PRD 3.1 (berita prestasi mengambil data dari modul Prestasi), PRD 3.7, PRD 3.10 (widget prestasi terbaru); ERD "Prestasi Santri" (`achievements`, `achievement_student`, catatan sertifikat/foto di `documents`, publikasi lewat `posts.achievement_id`), ERD "Konten Publik" (`posts.achievement_id`), tabel index ERD (`achievements`); `docs/plans/F013-plan.md` (morph map, `applyUnitScope`, `visibleOwnerTypesFor`, `UploadDocument`, `DocumentsRelationManager`), `docs/plans/F016-plan.md` (`posts`, `SavePost`, `GenerateSlug`), `docs/plans/F018-plan.md` (widget); design guide §3 (`Trophy`), §4, §5, §6, §7, §8, §9.

## Ruang lingkup

- Termasuk:
  - Migration `achievements`, `achievement_student`; model `Achievement` + factory; relasi `achievements()` di `Student`.
  - Migration pada `posts`: bersihkan `achievement_id` yatim, ganti index `achievement_id` menjadi **unique**, tambah FK `achievement_id → achievements` `restrictOnDelete`. Relasi `Post::achievement()` dan `Achievement::post()`.
  - Action `app/Actions/Prestasi/`: `SaveAchievement`, `VerifyAchievement`, `DeleteAchievement`, `PublishAchievementAsPost`, `SummarizeAchievements`, `SummarizeStudentAchievements`, `ListRecentAchievements`.
  - Global scope cakupan jenjang `Achievement` (lewat santri peserta) dan helper `Achievement::isFullyVisibleTo(User)`.
  - Perluasan titik F013: alias morph `achievement`, cabang `achievement` di `Document::applyUnitScope`, aturan jenis pemilik `achievement` di `Document::visibleOwnerTypesFor` dan `DocumentPolicy`, cabang pemilik di `UploadDocument`/`ReplaceDocument`, `DocumentsRelationManager` dipasang di `AchievementResource`, label/pencarian pemilik prestasi di `DocumentResource`, `HasDocuments` di `Achievement`.
  - Satu jenis dokumen baru di `DocumentTypeSeeder`: `FOTO_PRESTASI` ("Foto prestasi", owner `prestasi`). `SERTIFIKAT` sudah ada.
  - Permission + policy (`shield:generate`), pembagian ke peran, saklar `modules.prestasi.enabled` ke `ENABLED_BY_DEFAULT`.
  - UI Filament: `AchievementResource` (list/create/edit), halaman `AchievementRecap`, `AchievementsRelationManager` di `StudentResource`, aksi "Buat berita dari prestasi" di `ListPosts`, aksi "Terbitkan sebagai berita" di `AchievementResource`.
  - Pengisian `RecentAchievementsWidget` (F018).
  - Penjaga hapus: `EditUser` (pencatat/verifikator prestasi), `AcademicYearResource` (tahun ajaran yang punya prestasi).
  - Teks `lang/id/pondok.php`, grup navigasi `prestasi`, baris status prestasi di §4 design guide, sinkronisasi `docs/erd.md`.
- Tidak termasuk (eksplisit):
  - Usulan otomatis prestasi dari kelulusan ujian khataman tahfidz (Fase 3, modul Tahfidz).
  - Poin positif kedisiplinan (Fase 3).
  - Tampilan prestasi di portal publik (F017) dan portal wali (Fase 2). F015 hanya menyediakan relasi `Post::achievement()` dan Action rekap yang bisa dipakai ulang.
  - Menyalin foto prestasi (disk `private`) menjadi sampul berita (disk `public`). Sampul diunggah admin konten di `EditPost` (Pertanyaan #6).
  - Menerbitkan berita langsung (`status = terbit`). Aksi publikasi hanya membuat **draf**; admin konten menerbitkannya lewat `PostResource`.
  - Activity log prestasi (bukan data keuangan; Pertanyaan #8).
  - Rapor/surat rekomendasi yang memuat prestasi (F014/Fase 4).
  - Ekspor CSV rekap.

## Model data

Semua migration portabel SQLite/MySQL/Postgres: enum disimpan sebagai `string` + validasi di Action (pola F016), tanpa fungsi khusus database. Timestamp migration setelah migration F013/F016 terakhir.

### `achievements` — model `App\Models\Achievement` (tanpa soft delete)

| Kolom | Tipe | Catatan |
|---|---|---|
| id | id | |
| academic_year_id | foreignId → academic_years, `restrictOnDelete` | diturunkan dari `achieved_on` oleh Action |
| category | string(20) | `tahfidz \| akademik \| keagamaan \| olahraga \| seni \| organisasi \| lainnya` |
| title | string(255) | "Juara 1 MTQ Tahfidz 10 Juz" |
| event_name | string(255) | "MTQ Tingkat Kabupaten 2026" |
| organizer | string(255) nullable | |
| level | string(20) | `pondok \| kecamatan \| kabupaten \| provinsi \| nasional \| internasional` |
| kind | string(10) | `individu \| beregu` |
| achieved_on | date | index |
| recorded_by | foreignId → users, `restrictOnDelete` | wajib |
| verified_by | foreignId nullable → users, `restrictOnDelete` | NULL = belum diverifikasi |
| is_public | boolean default false | |
| timestamps | | |

Index: `achievements_year_category_level_index` (`academic_year_id`, `category`, `level`) (ERD), (`achieved_on`) untuk widget dan urutan daftar.

Model:
- `#[Fillable]` semua kolom kecuali `id`/timestamps.
- Konstanta `CATEGORIES`, `LEVELS` (urut dari rendah ke tinggi: `pondok`, `kecamatan`, `kabupaten`, `provinsi`, `nasional`, `internasional`), `KIND_INDIVIDUAL = 'individu'`, `KIND_TEAM = 'beregu'`, `KINDS`, `MAX_PARTICIPANTS = 50`, `RANK_MAX_LENGTH = 50`, `ROLE_MAX_LENGTH = 50`, `VERIFICATION_COLORS = ['terverifikasi' => 'success', 'belum' => 'warning']`.
- Cast: `achieved_on` → `date`, `is_public` → `boolean`.
- `$attributes = ['is_public' => false]`.
- Relasi: `academicYear()`, `recorder()` (`recorded_by`), `verifier()` (`verified_by`), `students(): BelongsToMany` (tabel `achievement_student`, `withPivot(['rank', 'role'])`, **termasuk santri soft-deleted** lewat `->withTrashed()` agar riwayat tidak hilang; global scope jenjang `Student` tetap berlaku sehingga peserta di luar cakupan tidak ikut tampil), `post(): HasOne` (`Post::class`, `achievement_id`, **withTrashed**), `documents(): MorphMany` (dari trait `HasDocuments` F013).
- Accessor `is_verified` (`verified_by !== null`) dan `verification_status` (`terverifikasi` / `belum`).
- `HasUnitScope` dengan `applyUnitScope` khusus (lihat Permission & cakupan).
- `isFullyVisibleTo(User $user): bool`: user tanpa cakupan → true; selain itu true bila **tidak ada** peserta (query `withoutGlobalScopes`, termasuk santri terarsip) yang `unit_id`-nya di luar `scopedUnitIds()`.
- `isPublishable(): bool`: `is_public`, terverifikasi, dan belum punya post (termasuk post terhapus).
- `scopeForAcademicYear(Builder, int $yearId)`, `scopeForUnit(Builder, int $unitId)` (lewat `whereExists` peserta).

`Student`: `achievements(): BelongsToMany` (inverse, `withPivot(['rank', 'role'])`).
`Post`: `achievement(): BelongsTo`.
`User`: `recordedAchievements(): HasMany`, `verifiedAchievements(): HasMany`.
`AcademicYear`: `achievements(): HasMany`.

### `achievement_student`

| Kolom | Tipe | Catatan |
|---|---|---|
| achievement_id | foreignId → achievements, `cascadeOnDelete` | |
| student_id | foreignId → students, `restrictOnDelete` | santri hanya soft delete; force delete tertahan |
| rank | string(50) | "Juara 1", "Harapan 2", "Finalis", "Peserta" |
| role | string(50) nullable | "Ketua tim" |

Primary key komposit (`achievement_id`, `student_id`), index (`student_id`) untuk rekap per santri. Tanpa timestamps (sesuai ERD).

### `posts` (perubahan, milik F016)

Migration `..._add_achievement_foreign_key_to_posts_table`, `up()` terdiri dari tiga langkah terpisah (review #1 #3; tiap langkah blok `Schema::table` sendiri agar rebuild SQLite membaca index yang sudah final):
1. Bersihkan yatim lewat query builder: `DB::table('posts')->whereNotNull('achievement_id')->whereNotIn('achievement_id', DB::table('achievements')->select('id'))->update(['achievement_id' => null])` (portabel). F016 mengizinkan angka sembarang; tanpa langkah ini pembuatan FK di MySQL/Postgres gagal. Pada instalasi yang baru menjalankan migration ini, `achievements` masih kosong sehingga **semua** `achievement_id` non-NULL menjadi NULL (perilaku yang dimaksud).
2. `Schema::table`: hapus index `posts_achievement_id_index`, tambah unique `posts_achievement_id_unique` (satu berita per prestasi, termasuk baris soft-deleted; NULL boleh banyak di ketiga database).
3. `Schema::table`: tambah FK `achievement_id → achievements.id` `restrictOnDelete`.

`down()` membalik dalam blok terpisah juga: hapus FK, lalu hapus unique dan kembalikan index biasa.

**Risiko SQLite:** menambah FK pada SQLite membuat Laravel membangun ulang tabel `posts`. Wajib dites setelah migration: unique `slug`, index (`status`, `published_at`), index `type`, kolom `deleted_at` + perilaku soft delete, FK `category_id`/`unit_id`/`author_id` (dengan aksi `on_delete` masing-masing), default kolom `status` = `draft`, `achievement_id` nullable, serta kolom `meta` dan `cover_path` tetap ada (AC-3).

**Portabilitas `rank`** (review #1 #9): `RANK` adalah kata terpesan di MySQL 8.0.2+. Nama kolom mengikuti ERD dan tidak diganti; semua akses `rank`, `role`, dan `level` wajib lewat query builder/Eloquent/`pivot` (identifier dikutip otomatis). **Dilarang** menyebut kolom itu di fragmen SQL mentah (`selectRaw`, `orderByRaw`, `whereRaw`, `havingRaw`, `DB::raw`). Urutan tingkat (`highest_level`) dihitung di PHP dari hasil `GROUP BY level`, bukan `CASE` mentah.

### Jenis dokumen

`DocumentTypeSeeder::TYPES` ditambah `['code' => 'FOTO_PRESTASI', 'name' => 'Foto prestasi', 'owner' => DocumentType::OWNER_ACHIEVEMENT]` (seeder `firstOrCreate` per kode, aman di-seed ulang).

## Action class

Semua Action melempar `ValidationException::withMessages([$field => __("pondok.prestasi.errors.{$reason}")])`, tanpa perubahan DB saat gagal (validasi sebelum menulis, tulis dalam `DB::transaction`). Tanggal diparse ketat `Y-m-d` (pola F009/F013: `Carbon::createFromFormat('!Y-m-d')` + cek format ulang). Hari ini dari `today()` (zona aplikasi). Action tidak memeriksa permission (dicek policy/UI). **Cakupan jenjang mengikuti mekanisme F013 (review #1 #5): user login.** Pencarian santri dan prestasi memakai query dengan global scope (`Student::query()`, `Achievement::query()`), dan pemeriksaan "seluruh peserta dalam cakupan" memakai `isFullyVisibleTo(Auth::user())`; tanpa user login (console, seeder) tidak ada penyaringan (sama dengan `UnitScope`). Parameter `$actor`/`$verifier` hanya dipakai untuk kolom pelaku (`recorded_by`, `verified_by`, `author_id`). Test cakupan wajib memakai `actingAs(<user>)`; pemanggilan dari panel selalu oleh user login sehingga hasilnya sama.

**Tanggal tampil** (review #1 #2): semua tanggal untuk teks (excerpt/body berita, kolom tabel, widget, halaman rekap) memakai `translatedFormat('j M Y')` dengan locale `id` (mis. "17 Agu 2026", "15 Okt 2026"), atau `->date('j M Y')` Filament yang sudah memakai locale aplikasi. `format()` biasa dilarang untuk nama bulan.

### `App\Actions\Prestasi\SaveAchievement::handle(array $data, User $actor, ?Achievement $achievement = null): Achievement`

`$data`: `array{category: mixed, title: mixed, event_name: mixed, organizer?: mixed, level: mixed, kind: mixed, achieved_on: mixed, is_public?: mixed, participants: list<array{student_id: mixed, rank: mixed, role?: mixed}>}`.

Validasi berurutan (berhenti di error pertama):
1. Edit: `$achievement->isFullyVisibleTo(Auth::user())` false (user login bercakupan) → `achievement`/`outside_scope`.
2. `category` ∈ `CATEGORIES` → `category`/`category_invalid`.
3. `title` di-trim, kosong → `title`/`title_required`; > 255 → `title`/`too_long`.
4. `event_name` di-trim, kosong → `event_name`/`event_name_required`; > 255 → `event_name`/`too_long`.
5. `organizer` di-trim, kosong → NULL; > 255 → `organizer`/`too_long`.
6. `level` ∈ `LEVELS` → `level`/`level_invalid`.
7. `kind` ∈ `KINDS` → `kind`/`kind_invalid`.
8. `achieved_on`: tidak valid → `achieved_on`/`date_invalid`; setelah hari ini → `achieved_on`/`achieved_on_future`; tahun ajaran diturunkan: `AcademicYear` dengan `starts_on <= achieved_on <= ends_on` (**kedua batas inklusif**, `whereDate`; bila lebih dari satu tahun ajaran tumpang tindih, pilih `starts_on` terbaru, lalu `id` terbesar); tidak ada → `achieved_on`/`academic_year_not_found`. Diturunkan ulang setiap simpan, sehingga edit `achieved_on` memindahkan `academic_year_id`.
9. `participants`:
   - kosong → `participants`/`participants_required`;
   - lebih dari `MAX_PARTICIPANTS` → `participants`/`participants_too_many`;
   - `student_id` ganda → `participants`/`participant_duplicate`;
   - `individu` dengan jumlah ≠ 1 → `participants`/`individual_single`; `beregu` dengan jumlah < 2 → `participants`/`team_too_small`;
   - setiap santri dicari dengan `Student::withTrashed()` (global scope jenjang user login **tetap** berlaku, termasuk terarsip): tidak ditemukan (tidak ada atau di luar cakupan) → `participants`/`student_not_found`; santri terarsip (`deleted_at` terisi) yang **belum** menjadi peserta prestasi ini → `participants`/`student_archived` (peserta lama yang kemudian diarsipkan tetap boleh dipertahankan);
   - `rank` di-trim, kosong → `participants`/`rank_required`; > 50 → `participants`/`too_long`; `role` di-trim, kosong → NULL, > 50 → `participants`/`too_long`.
10. `is_public` (bool, bawaan false). Edit: mengubah `true → false` saat prestasi punya post **tidak terhapus** berstatus `terbit` → `is_public`/`public_post_exists` (tarik berita dulu).

Efek:
- Buat baru: `recorded_by = $actor->id`, `verified_by = NULL`, isi kolom, sinkronkan peserta (insert pivot).
- Edit: isi kolom dan sinkronkan peserta (`sync` dengan pivot `rank`/`role`). Bila prestasi terverifikasi dan ada perubahan **substantif** (salah satu dari `category`, `title`, `event_name`, `organizer`, `level`, `kind`, `achieved_on`, himpunan peserta, atau `rank`/`role` peserta mana pun), `verified_by` dikosongkan. Perubahan `is_public` saja tidak membatalkan verifikasi. `recorded_by` tidak berubah.
- Mengembalikan model segar dengan `students` termuat.

### `App\Actions\Prestasi\VerifyAchievement::handle(Achievement $achievement, User $verifier): Achievement`

1. Tidak `isFullyVisibleTo(Auth::user())` → `achievement`/`outside_scope`.
2. Sudah terverifikasi → `achievement`/`already_verified`.
3. Efek: `verified_by = $verifier->id`. Pencatat boleh memverifikasi prestasinya sendiri (Pertanyaan #3).

### `App\Actions\Prestasi\DeleteAchievement::handle(Achievement $achievement, User $actor): void`

1. Tidak `isFullyVisibleTo(Auth::user())` → `achievement`/`outside_scope`.
2. Terverifikasi → `achievement`/`verified_locked` (edit dulu agar verifikasi gugur, atau minta verifikator).
3. Punya post (termasuk terhapus) → `achievement`/`has_post`.
4. Punya dokumen (dicek **tanpa** unit scope, `hasAnyDocuments()` F013) → `achievement`/`has_documents` (dokumen tidak bisa dihapus di F013; Pertanyaan #5).
5. Efek: hapus baris (pivot ikut terhapus lewat `cascadeOnDelete`).

### `App\Actions\Prestasi\PublishAchievementAsPost::handle(Achievement $achievement, User $actor): Post`

1. `is_public` false → `achievement`/`not_public`.
2. Belum terverifikasi → `achievement`/`not_verified`.
3. Sudah punya post (termasuk terhapus) → `achievement`/`already_published` (pesan menyarankan memulihkan/menyunting berita yang ada).
4. Susun data untuk `SavePost` (F016), tanpa mengubah `SavePost`:
   - `type` = `Post::TYPE_ACHIEVEMENT`, `status` = `draft`, `published_at` = NULL, `category_id` = NULL;
   - `title` = `achievements.title`; slug otomatis lewat `SavePost`/`GenerateSlug`;
   - `excerpt` = `__('pondok.achievement.post_excerpt', ['event' => event_name, 'level' => label tingkat, 'date' => achieved_on->translatedFormat('j M Y')])` dipotong 255 (locale `id`, mis. "17 Agu 2026");
   - `body` = HTML dari view `resources/views/prestasi/post-body.blade.php`: paragraf ringkasan (`pondok.achievement.post_intro` dengan acara, penyelenggara bila ada, tingkat, tanggal) dan `<ul>` peserta `Nama — Peringkat (Peran)` urut nama. Semua nilai di-escape (`{{ }}`); `SavePost` menyanitasi ulang;
   - `unit_id` = jenjang bersama bila semua peserta satu jenjang, selain itu NULL (umum).
   Peserta dibaca `withoutGlobalScopes` (termasuk terarsip) sehingga berita memuat seluruh tim walau admin konten bercakupan.
5. Dalam satu transaksi: `SavePost::handle($data, $actor)`, lalu `forceFill(['achievement_id' => $achievement->id])->save()`. **Try/catch hanya membungkus langkah `forceFill()->save()`** (review #1 #10a): `UniqueConstraintViolationException` di sana (balapan dua klik pada `achievement_id`) dipetakan ke `already_published` dan transaksi dibatalkan (tidak ada post tersisa). Exception dari `SavePost` (termasuk unique slug, yang sudah dipetakan F016 ke `slug_taken`, dan `ValidationException`) diteruskan apa adanya, tidak pernah menjadi `already_published`.
   Setelah berita dibuat, berita **tidak disinkronkan** dengan perubahan prestasi (review #1 #10b): edit substantif prestasi yang beritanya sudah `terbit` membatalkan verifikasi prestasi, tetapi berita tetap tayang dengan isi lama; admin konten menyunting atau menarik berita secara manual (Risiko #15).
6. Mengembalikan `Post`.

### Aturan hitungan rekap (keputusan orkestrator, review #1 #1 opsi a)

Rekap adalah angka resmi yang kelak dipakai rapor, portal wali, dan surat rekomendasi, sehingga **bawaan hanya menghitung prestasi terverifikasi** (`verified_by IS NOT NULL`). `SummarizeAchievements`, `SummarizeStudentAchievements`, dan `ListRecentAchievements` menerima `bool $verifiedOnly = true`. Pemanggil yang memakai `false`:
- halaman rekap bila toggle "Termasuk yang belum diverifikasi" dinyalakan (toggle hanya tampil bagi pengelola, yaitu user yang boleh `create` atau `verify` Achievement);
- tab prestasi di halaman santri **menampilkan semua** prestasi dengan badge status, tetapi ringkasan deskripsinya tetap memakai `verifiedOnly = true` (angka resmi) ditambah keterangan jumlah yang belum diverifikasi.
Widget dashboard memakai bawaan (`true`). Deskripsi aksi verifikasi ("dihitung di rekap resmi") dengan demikian benar.

### `App\Actions\Prestasi\SummarizeAchievements::handle(?int $academicYearId, ?int $unitId = null, bool $verifiedOnly = true): array`

Output:
```
array{
  total: int,                                   // jumlah prestasi (distinct)
  participations: int,                          // jumlah baris peserta
  by_category: array<string, int>,              // semua kategori, 0 bila kosong, urut CATEGORIES
  by_level: array<string, int>,                 // semua tingkat, urut LEVELS
  matrix: array<string, array<string, int>>,    // [category][level]
}
```
- Berangkat dari `Achievement::query()` (global scope jenjang berlaku), disaring `verified_by IS NOT NULL` bila `$verifiedOnly`. `$academicYearId` NULL = semua tahun. `$unitId` menyaring prestasi yang punya peserta di jenjang itu (`forUnit`).
- `participations` = jumlah baris peserta dari prestasi yang lolos saring, dengan santri yang terlihat (global scope `Student`); bila `$unitId` diisi, **hanya peserta di jenjang itu** yang dihitung (review #1 #8), sehingga tim lintas jenjang tidak menggelembungkan angka jenjang lain.
- Hitungan lewat agregat `GROUP BY category, level` (satu query, builder tanpa SQL mentah untuk `level`) + satu query `participations`. Maksimal 3 query.

### `App\Actions\Prestasi\SummarizeStudentAchievements::handle(Student $student, ?int $academicYearId = null, bool $verifiedOnly = true): array`

Output `array{total: int, unverified: int, highest_level: ?string, by_level: array<string, int>, by_category: array<string, int>}`. `total`, `highest_level`, `by_level`, `by_category` mengikuti `$verifiedOnly`; `unverified` selalu jumlah prestasi santri yang belum diverifikasi (untuk keterangan tab). `highest_level` = tingkat tertinggi menurut urutan `LEVELS` (dihitung di PHP), NULL bila tidak ada. Prestasi dibaca `withoutUnitScope` karena pemanggil sudah memegang santrinya (pola `CheckDocumentCompleteness`). Dipakai tab santri, halaman rekap, dan nanti portal wali.

### `App\Actions\Prestasi\ListRecentAchievements::handle(int $limit = 5, bool $verifiedOnly = true): Collection<Achievement>`

`Achievement::query()` (terscope, terverifikasi saja bila `$verifiedOnly`) urut `achieved_on` desc, lalu `id` desc, `limit`, eager-load `students` (terscope, `withTrashed`) dan `academicYear`. Maksimal 3 query (tanpa N+1).

## UI (Filament / Blade)

Rujukan: design guide §0, §3 (`Heroicon::OutlinedTrophy` untuk prestasi, `$hasTitleCaseModelLabel = false`), §4 (badge verifikasi), §5 (tanggal `j M Y` lewat `->date('j M Y')`/`translatedFormat` locale `id`, kosong `-`), §6 (struktur resource, maks. ±7 kolom, `defaultSort`, eager-load, filter jenjang `accessible()`, aksi lain di `ActionGroup`), §7 (empty state, modul mati), §8 (konfirmasi hapus/terbitkan, label kata kerja + objek), §9. Panggil `/anti-ui-slop` bila tersedia; halaman rekap adalah layar baru sehingga perlu kontrak desain singkat (§13.3) di laporan green-coder.

Grup navigasi baru `pondok.navigation.prestasi` = "Prestasi". Resource dan halaman memakai `BelongsToModule` (`getModule(): 'prestasi'`) dan `ShowsActionErrorsOnForm` untuk aksi modal.

### `AchievementResource` — "Prestasi" (`/admin/achievements`, sort 1, `Heroicon::OutlinedTrophy`)

Halaman `ListAchievements`, `CreateAchievement`, `EditAchievement` (form panjang). `CreateAchievement::handleRecordCreation` dan `EditAchievement::handleRecordUpdate` memanggil `SaveAchievement` dan memetakan `ValidationException` ke `data.<field>` (field `participants` → `data.participants`).

- Form `Schemas/AchievementForm`:
  - Section `section_achievement` (`columns(2)`): `title` (columnSpanFull), `event_name`, `organizer`, `category` (Select `pondok.achievement.categories`), `level` (Select `pondok.achievement.levels`), `kind` (Radio `pondok.achievement.kinds`, `live()`), `achieved_on` (DatePicker, `maxDate(today())`, helper "Tahun ajaran ditentukan dari tanggal ini."), `is_public` (Toggle, helper "Prestasi boleh ditampilkan di website dan dijadikan berita.").
  - Section `section_participants`: Repeater `participants` (tidak terikat relasi; diisi lewat `mutateFormDataBeforeFill` dari peserta yang terlihat) berisi `student_id` (Select searchable, opsi dari `Student::query()` terscope, tanpa terarsip, label "NIS — Nama"; peserta lama terarsip tetap tampil dengan label "(diarsipkan)"), `rank` (TextInput, datalist saran "Juara 1", "Juara 2", "Juara 3", "Harapan 1", "Finalis", "Peserta"), `role` (TextInput, tampil hanya bila `kind = beregu`). `minItems(1)`, `maxItems(MAX_PARTICIPANTS)`, tombol "Tambah peserta".
  - Saat edit prestasi terverifikasi, deskripsi form: "Mengubah isi prestasi membatalkan verifikasi."
- Tabel `Tables/AchievementsTable`:
  - Kolom: `achieved_on` (pengenal, `j M Y`), `title` (deskripsi `event_name`), peserta (nama peserta yang terlihat, maks. 3 + "dan :count lainnya"), `category` (label), `level` (label), `verification_status` (badge `VERIFICATION_COLORS`), `is_public` (IconColumn), berita (teks "Draf"/"Terbit"/"-" dari `post`, toggleable), `academicYear.name`, `recorder.name`, `verifier.name` (toggleable tersembunyi).
  - `defaultSort('achieved_on', 'desc')`; eager-load `students`, `academicYear`, `recorder`, `verifier`, `post`.
  - Filter: tahun ajaran (SelectFilter, default tahun aktif), `category`, `level`, `kind`, jenjang (`Unit::accessible()`, `forUnit`), verifikasi (TernaryFilter "Terverifikasi"/"Belum diverifikasi"), `is_public`, santri (SelectFilter searchable `student`, `whereHas('students')`; dipakai link dari halaman rekap).
  - Pencarian: `title`, `event_name`, nama/NIS peserta.
  - Aksi baris: `EditAction` (tampil bila policy `update`); `ActionGroup`: "Verifikasi prestasi" (`requiresConfirmation`, deskripsi "Prestasi yang diverifikasi dihitung di rekap resmi dan bisa diterbitkan sebagai berita."), "Terbitkan sebagai berita" (lihat di bawah), "Hapus prestasi" (`DeleteAction` `->using(DeleteAchievement)`, `danger`, konfirmasi "Data peserta prestasi ikut terhapus."). Tidak ada bulk action.
  - Empty state: ikon `Trophy`, "Belum ada prestasi untuk tahun ajaran ini.", aksi "Catat prestasi" bila boleh `create`.
- `EditAchievement` header: "Verifikasi prestasi", "Terbitkan sebagai berita", hapus. Relation manager: `DocumentsRelationManager` (F013) untuk sertifikat dan foto.

### Aksi "Terbitkan sebagai berita" (satu definisi: `AchievementResource::publishAction()`)

- Tampil bila: `Setting::moduleEnabled('konten')`, policy `publish` (`publish:achievement` + `Gate::allows('create', Post::class)` + `isPublishable()`).
- `requiresConfirmation`, deskripsi "Draf berita dibuat dari data prestasi ini. Periksa dan terbitkan dari menu Berita & Kegiatan."
- Memanggil `PublishAchievementAsPost`; error tampil sebagai notifikasi `danger`. Sukses: notifikasi "Draf berita untuk prestasi :title dibuat." dan redirect ke `EditPost` bila `PostResource::canEdit($post)`, selain itu tetap di halaman.

### Aksi "Buat berita dari prestasi" di `ListPosts` (F016)

- Header action (gray) `ListPosts::fromAchievementAction()` tampil bila `Setting::moduleEnabled('prestasi')`, user punya `publish:achievement`, dan `PostResource::canCreate()` (`create:post`).
- Form: Select `achievement_id` (searchable) dengan opsi prestasi terscope yang `isPublishable()` (query: `is_public`, `verified_by` terisi, tanpa post termasuk terhapus), label "Judul — <achieved_on translatedFormat 'j M Y'>", urut `achieved_on` desc. Tanpa opsi → helper "Belum ada prestasi publik terverifikasi yang belum diberitakan."
- Handler memeriksa ulang policy `publish` untuk prestasi terpilih (id dikirim langsung lewat Livewire) → bila gagal, error `achievement_id`/`not_publishable` di field; lalu memanggil `PublishAchievementAsPost` lewat `saveShowingFormErrors` dan redirect ke `EditPost`.

### `AchievementRecap` — "Rekap prestasi" (`/admin/achievement-recap`, Filament Page, sort 2, `Heroicon::OutlinedChartBar`)

- Akses: `canAccess()` = modul `prestasi` aktif dan `view-any:achievement` (tanpa permission halaman baru).
- Filter di atas (form Livewire): tahun ajaran (default aktif; opsi "Semua tahun"), jenjang (`Unit::accessible()`, opsional), toggle "Termasuk yang belum diverifikasi" (bawaan mati; hanya tampil bagi user yang boleh `create` atau `verify` Achievement; nilai dikirim langsung tanpa hak itu diabaikan dan dianggap mati).
- Keterangan di bawah judul: "Hanya prestasi terverifikasi." atau "Termasuk prestasi yang belum diverifikasi." sesuai toggle.
- Bagian 1 "Ringkasan": angka total prestasi dan jumlah keikutsertaan santri (`SummarizeAchievements` dengan `verifiedOnly` = kebalikan toggle), lalu tabel matriks kategori (baris) × tingkat (kolom) dengan total baris dan kolom, angka `tabular-nums` rata kanan, sel 0 tampil `0`.
- Bagian 2 "Santri berprestasi" (Filament table pada `Student::query()` terscope, termasuk terarsip, `whereHas('achievements')` sesuai filter dan toggle verifikasi): kolom NIS, nama, jenjang, jumlah prestasi (`withCount` dengan saringan sama), tingkat tertinggi (label, dihitung di PHP dari `level` prestasi santri yang lolos saring, tanpa SQL mentah), urut jumlah desc lalu nama. Aksi baris "Lihat prestasi" → `AchievementResource` index dengan filter santri dan tahun ajaran terisi.
- Empty state: "Belum ada prestasi untuk tahun ajaran ini."

### `AchievementsRelationManager` di `StudentResource` — tab "Prestasi"

- `canViewForRecord`: modul `prestasi` aktif dan `viewAny` Achievement.
- Read-only, menampilkan **semua** prestasi santri (terverifikasi maupun belum): kolom `achieved_on`, `title` (+ `event_name`), `level`, `category`, `pivot.rank`, `pivot.role`, badge verifikasi. Default sort `achieved_on` desc. Filter tahun ajaran dan verifikasi.
- Deskripsi tabel dari `SummarizeStudentAchievements` (bawaan `verifiedOnly = true`): ":total prestasi terverifikasi, tingkat tertinggi :level." ditambah " :unverified belum diverifikasi." bila `unverified` > 0; tanpa prestasi terverifikasi: "Belum ada prestasi terverifikasi." (+ keterangan belum diverifikasi bila ada).
- Header action "Catat prestasi" → URL `CreateAchievement` dengan santri terisi (`?student=<id>`), tampil bila `create` Achievement. Tanpa aksi edit/hapus di tab (dikelola di `AchievementResource`).

### Widget `RecentAchievementsWidget` (F018)

- `hasMetricSource()` → `true`. Modul `prestasi`, tabel wajib `achievements`, `achievement_student` (tetap seperti F018).
- `canView()` di-override: permission `view-student-metrics` (F018) **dan** `view-any:achievement` (review #1 #10), agar peran yang hanya diberi metrik santri tidak melihat daftar prestasi. Peran bawaan yang melihat widget tidak berubah (`admin-sekolah`, `kesantrian`, super-admin).
- Isi dari `ListRecentAchievements(5)` (bawaan terverifikasi saja): daftar baris tanggal (`translatedFormat('j M Y')`), judul, label tingkat, nama peserta terlihat (maks. 2 + "dan :count lainnya").
- Empty state: "Belum ada prestasi terverifikasi."
- Link header "Lihat semua prestasi" → `urlIfRouteAvailable(AchievementResource index, AchievementResource::canViewAny())`.

### Perubahan resource lain

- `DocumentResource`/`DocumentsRelationManager` (F013): label pemilik `pondok.document.owner_types.achievement` = "Prestasi"; nama pemilik prestasi = `title`; pencarian pemilik memasukkan `achievement.title` (`whereHasMorph`); kolom jenjang `-` untuk prestasi; opsi jenis unggah untuk prestasi = jenis aktif owner `prestasi`.
- `EditUser`: `DeleteAction` disembunyikan juga bila user punya `recordedAchievements` atau `verifiedAchievements` (tanpa unit scope).
- `AcademicYearResource`: `DeleteAction` disembunyikan bila tahun ajaran punya prestasi (`withExists` tanpa unit scope).

### Teks `lang/id/pondok.php` (kunci baru)

`navigation.prestasi`; `achievement.{label, plural, title, event_name, organizer, category, categories.{tahfidz,akademik,keagamaan,olahraga,seni,organisasi,lainnya}, level, levels.{pondok,kecamatan,kabupaten,provinsi,nasional,internasional}, kind, kinds.{individu,beregu}, achieved_on, achieved_on_helper, academic_year, is_public, is_public_helper, participants, participant_student, participant_rank, participant_role, add_participant, rank_suggestions, archived_suffix, others (:count), recorded_by, verified_by, verification_statuses.{terverifikasi,belum}, verification_filter, post, post_statuses, section_achievement, section_participants, edit_resets_verification, verify_action, verify_description, verified (:title), publish_action, publish_description, published (:title), from_achievement_action, from_achievement_empty, post_excerpt (:event, :level, :date), post_intro, post_participant, delete_action, delete_description, create_action, empty, student_filter, view_achievements}`; `achievement_recap.{title, navigation, summary, total, participations, matrix_total, students, achievement_count, highest_level, all_years, include_unverified, verified_only_note, including_unverified_note, empty}`; `achievement_student_tab.{title, summary (:total, :level), unverified_note (:count), empty}`; `dashboard.achievements.{empty, list_link, others}`; `document.owner_types.achievement`; `prestasi.errors.{outside_scope, category_invalid, title_required, too_long, event_name_required, level_invalid, kind_invalid, date_invalid, achieved_on_future, academic_year_not_found, participants_required, participants_too_many, participant_duplicate, individual_single, team_too_small, student_not_found, student_archived, rank_required, public_post_exists, already_verified, verified_locked, has_post, has_documents, not_public, not_verified, already_published, not_publishable}`. Kunci `prestasi.errors.*` ditulis **bersama** Action pemakainya. Setiap pesan menyebut cara memperbaiki (§9), mis. `team_too_small`: "Prestasi beregu memerlukan minimal dua santri. Pilih jenis individu bila hanya satu santri."

### Design guide §4

Tambah baris (commit `docs(prestasi)` bersama kode): `Prestasi | terverifikasi | success`, `Prestasi | belum diverifikasi | warning`. Tambah ikon `ChartBar` = "Rekap / laporan" di tabel §3 bila belum ada.

## Permission & cakupan

### Permission baru

`RolePermissionSeeder::RESOURCE_MODELS` ditambah `Achievement`; `EXTRA_RESOURCE_ACTIONS['Achievement'] = ['verify', 'publish']` → `verify:achievement`, `publish:achievement`. Policy dibuat dengan `php artisan shield:generate` setelah resource ada, lalu `AchievementPolicy` ditambah `verify(User, Achievement)`, `publish(User, Achievement)`; `update`, `delete`, dan `verify` juga mensyaratkan `isFullyVisibleTo($user)`; `update` dan `delete` tidak mensyaratkan status; `delete` mensyaratkan bukan terverifikasi, tanpa post, tanpa dokumen (agar aksi tersembunyi, Action tetap pengaman); `verify` mensyaratkan belum terverifikasi; `publish` mensyaratkan `publish:achievement`, `Gate::allows('create', Post::class)` (review #1 #7; membuat draf berita tidak boleh melewati permission Konten), dan `isPublishable()`. Test "every permission that a generated policy checks" tetap lulus.

### Pembagian ke peran

| Peran | Permission prestasi |
|---|---|
| super-admin | semua |
| kesantrian | `view-any`, `view`, `create`, `update`, `delete` |
| admin-sekolah | `view-any`, `view`, `verify` |
| admin-konten | `publish` |
| lainnya | — |

Dataset "grants each role exactly its permissions" di `RolePermissionSeederTest` diperbarui untuk tiga peran itu (**tambahkan** baris, jangan menimpa perubahan F009/F013/F016). Tidak ada peran selain super-admin yang punya `delete-any:achievement`.

### Saklar modul

`modules.prestasi.enabled` ditambahkan ke `SettingSeeder::ENABLED_BY_DEFAULT` (gabungkan dengan perubahan F009/F013/F016). Modul mati → menu Prestasi dan Rekap prestasi hilang, URL 403, tab prestasi santri tidak tampil, aksi "Buat berita dari prestasi" tidak tampil, widget menampilkan "Modul belum tersedia." Tab dokumen di halaman prestasi mengikuti saklar `dokumen` (F013). Aksi publikasi juga butuh saklar `konten`.

### Cakupan jenjang (keputusan)

Prestasi tidak punya `unit_id`; jenjangnya berasal dari santri peserta.

- **Terlihat** bila minimal satu peserta (santri termasuk terarsip, `withoutGlobalScopes`) ber-`unit_id` dalam cakupan user. `Achievement::applyUnitScope`:
  ```
  EXISTS (SELECT 1 FROM achievement_student JOIN students ON students.id = achievement_student.student_id
          WHERE achievement_student.achievement_id = achievements.id AND students.unit_id IN (:unitIds))
  ```
  Prestasi tanpa peserta (tidak mungkin lewat Action) tidak terlihat oleh user bercakupan (fail-closed).
- **Dikelola** (update, delete, verify) hanya bila `isFullyVisibleTo` (semua peserta dalam cakupan). Prestasi beregu lintas jenjang terlihat oleh admin kedua jenjang tetapi hanya bisa diubah oleh user tanpa cakupan atau yang cakupannya mencakup semua jenjang peserta.
- Daftar peserta yang ditampilkan mengikuti global scope `Student`, sehingga nama santri jenjang lain tidak terlihat oleh user bercakupan.
- Rekap: hitungan prestasi memakai scope `Achievement`; keikutsertaan dan daftar santri memakai scope `Student`.
- Mekanisme: global scope membaca user login (pola `UnitScope`/F013). Action memakai `Auth::user()` untuk `isFullyVisibleTo`; `isFullyVisibleTo(User)` juga dipakai policy.
- Route model binding resource memakai query terscope → prestasi di luar cakupan 404.

### Perluasan F013 (dokumen prestasi)

- `Document::DOCUMENTABLE_TYPES['achievement'] = Achievement::class` (morph map, tetap non-enforce).
- `Document::applyUnitScope` ditambah cabang: `documentable_type = 'achievement' AND documentable_id IN (SELECT achievement_student.achievement_id ... students.unit_id IN :unitIds)` (santri termasuk terarsip).
- `Document::visibleOwnerTypesFor($user)` menambah `achievement` bila user punya `view-any:achievement`. Hasil: `kesantrian` = [`student`, `achievement`], `admin-sekolah` = [`student`, `guardian`, `staff`, `achievement`].
- `DocumentPolicy` untuk pemilik prestasi:
  - `view`/`download`/`verify`: aturan F013 (`view:document`, kerahasiaan, `verify:document`) + jenis pemilik boleh;
  - `createFor` (unggah/versi baru) untuk pemilik prestasi: `Gate::allows('update', $achievement)` (bukan `create:document`), plus aturan kerahasiaan F013. Dengan begitu `kesantrian` bisa mengunggah sertifikat/foto prestasi tanpa mendapat hak unggah dokumen santri.
- `UploadDocument`/`ReplaceDocument` langkah pemilik (mekanisme F013, user login): prestasi harus ditemukan lewat `Achievement::query()` (global scope cakupan user login) → selain itu `documentable`/`owner_not_found`. Test memakai `actingAs(uploader)`.
- Signature policy pemilik: F013 sudah memakai `Gate::allows('uploadType', [Document::class, $type])` dan pemeriksaan pemilik di policy `create`; F015 menambah method eksplisit `DocumentPolicy::createFor(User $user, Model $owner): bool` (dipanggil `Gate::allows('createFor', [Document::class, $owner])`) yang menjadi satu sumber aturan unggah per pemilik: pemilik prestasi → `Gate::allows('update', $owner)`; pemilik lain → aturan `create` F013 tanpa perubahan (`create:document`, pemilik bukan santri terarsip). `DocumentsRelationManager` (aksi unggah dan versi baru) memakai `createFor`; `create(User)` bawaan Filament tetap ada untuk F013.
- `DocumentsRelationManager::canViewForRecord` untuk `Achievement`: modul `dokumen` aktif, `viewAny` Document, dan `view` Achievement.
- `Achievement` memakai trait `HasDocuments` (`hasAnyDocuments()` tanpa scope).

## Kriteria penerimaan (bisa dites)

Fixture bersama (helper `prestasi*` di **akhir** `tests/Pest.php`, bagian terpisah): `UnitSeeder` (SD, SLTP, SLTA), `RolePermissionSeeder`, `SettingSeeder`, `DocumentTypeSeeder`; tahun ajaran `2025/2026` (2025-07-01 s.d. 2026-06-30) dan `2026/2027` (2026-07-01 s.d. 2027-06-30, aktif); santri SD `S1`, SLTP `S2` dan `S3`; waktu dibekukan `2026-10-15 10:00` Asia/Jakarta; `Storage::fake('private')` dan `Storage::fake('public')`. User: `K` (`kesantrian`, tanpa cakupan), `A` (`admin-sekolah`, tanpa cakupan), `C` (`admin-konten`). Helper `prestasiUser(string $role, array $unitCodes = [])` dan `prestasiAssertRejected(Closure, string $reason, ?string $field)` (pola `keuanganAssertRejected` dengan `pondok.prestasi.errors.*`). Data valid bawaan: kategori `tahfidz`, judul "Juara 1 MTQ Tahfidz 10 Juz", acara "MTQ Tingkat Kabupaten 2026", tingkat `kabupaten`, `individu`, `achieved_on` `2026-08-17`, peserta `S2` peringkat "Juara 1". Setiap test yang memeriksa cakupan memanggil `actingAs(<user>)` sebelum Action/query (mekanisme user login, pola F013); test tanpa cakupan juga `actingAs` user tanpa baris `unit_user`. Fixture rekap/widget **selalu menyebut status verifikasi** tiap prestasi (factory state `verified()`); prestasi tanpa keterangan dianggap terverifikasi oleh `A`.

Skema & model
- AC-1: Setelah migration, `achievements` punya kolom ERD (`academic_year_id`, `category`, `title`, `event_name`, `organizer`, `level`, `kind`, `achieved_on`, `recorded_by`, `verified_by`, `is_public`) tanpa `deleted_at`; `Achievement` tidak memakai `SoftDeletes`. `Schema::getIndexes('achievements')` memuat index dengan kolom tepat [`academic_year_id`, `category`, `level`] dan [`achieved_on`]. `Schema::getForeignKeys('achievements')` memuat FK `academic_year_id`, `recorded_by`, `verified_by` dengan `on_delete` `restrict` (case-insensitive). `achievement_student` punya kolom `achievement_id`, `student_id`, `rank`, `role`, primary key [`achievement_id`, `student_id`], index [`student_id`], FK `achievement_id` `cascade` dan `student_id` `restrict`.
- AC-2: Constraint: menghapus tahun ajaran atau user pencatat yang dirujuk prestasi → `QueryException`; force delete santri peserta → `QueryException`; dua baris pivot (achievement, student) sama → `UniqueConstraintViolationException`; menghapus prestasi lewat query menghapus baris pivotnya; `is_public` bawaan false.
- AC-3: Migration `posts`: setelah `migrate`, `Schema::getForeignKeys('posts')` memuat FK `achievement_id → achievements` `restrict` serta FK `category_id`, `unit_id`, `author_id` dengan `on_delete` sama seperti sebelum migration F015 (`restrict`, `restrict`, `set null`); `Schema::getIndexes('posts')` memuat unique [`slug`], unique [`achievement_id`], index [`status`, `published_at`], index [`type`], dan **tidak** memuat index non-unique [`achievement_id`]; kolom `deleted_at` ada dan `Post` soft delete masih bekerja (post terhapus tidak muncul di `Post::query()`, muncul di `withTrashed()`). Dua post dengan `achievement_id` sama (salah satunya soft-deleted) → `UniqueConstraintViolationException`; menghapus prestasi yang dirujuk post → `QueryException`. Pasca rebuild juga: default kolom `status` = `draft` (insert tanpa `status` menghasilkan `draft`), kolom `achievement_id` nullable, serta kolom `meta` dan `cover_path` masih ada (`Schema::hasColumns`).
- AC-4: Pembersihan data lama, **tanpa** `--step` (review #1 #4): test memuat instance migration F015 dari file (`$migration = require database_path('migrations/<file F015 posts>.php')`) lalu memanggil `$migration->down()`; buat prestasi `PX` dan tiga post: `achievement_id` 999 (tidak ada), `achievement_id` = `PX.id`, dan NULL; panggil `$migration->up()` → sukses, post 999 menjadi NULL, post ber-`PX.id` **tetap** `PX.id`, post NULL tetap NULL. Kasus kedua: dengan tabel `achievements` dikosongkan sebelum `up()`, semua `achievement_id` non-NULL menjadi NULL. Setelah `down()`, `Schema::getIndexes('posts')` memuat index non-unique [`achievement_id`] dan `getForeignKeys` tidak memuat FK `achievement_id`. Test tidak bergantung pada urutan migration lain.
- AC-5: Relasi: `S2->achievements` memuat prestasi beserta `pivot.rank`; `Achievement->students` memuat santri terarsip (soft-deleted) yang menjadi peserta; `Post->achievement` dan `Achievement->post` (termasuk post soft-deleted) saling merujuk; `(new Achievement)->getMorphClass()` = `achievement`.

Seeder & modul
- AC-6: Instalasi baru (`settings` kosong lalu `SettingSeeder`) → `Setting::moduleEnabled('prestasi')` true; nilai `false` yang sudah ada tidak ditimpa seed ulang. `DocumentTypeSeeder` membuat `FOTO_PRESTASI` (owner `prestasi`, aktif, tidak wajib, tidak rahasia); seed ulang tidak menggandakan.
- AC-7: `RolePermissionSeeder` membuat `view-any:achievement`, `view:achievement`, `create:achievement`, `update:achievement`, `delete:achievement`, `delete-any:achievement`, `verify:achievement`, `publish:achievement`. Dataset peran: `kesantrian` mendapat tambahan tepat `view-any`, `view`, `create`, `update`, `delete` achievement; `admin-sekolah` tambahan tepat `view-any`, `view`, `verify`; `admin-konten` tambahan tepat `publish:achievement`; peran lain tidak berubah; hanya super-admin yang punya `delete-any:achievement`. Test policy-permission tetap lulus.

SaveAchievement
- AC-8: `K` membuat prestasi dengan data bawaan dan `title` `'  Juara 1 MTQ Tahfidz 10 Juz  '`, `organizer` `'  '` → satu baris: `title` di-trim, `organizer` NULL, `academic_year_id` = `2026/2027`, `recorded_by` `K`, `verified_by` NULL, `is_public` false; satu baris pivot `S2` peringkat "Juara 1", `role` NULL.
- AC-9: Prestasi beregu dengan peserta `S2` ("Juara 1", role "Ketua tim") dan `S3` ("Juara 1") → dua baris pivot dengan pivot masing-masing. Batas tahun ajaran inklusif (dataset): `achieved_on` `2026-03-10` dan `2026-06-30` → `2025/2026`; `2026-07-01` → `2026/2027`; `2025-07-01` → `2025/2026`. Tumpang tindih: tahun ajaran tambahan `2026/2027 Khusus` (2026-08-01 s.d. 2026-12-31, dibuat di test) dan `achieved_on` `2026-08-17` → tahun dengan `starts_on` terbaru (`2026/2027 Khusus`).
- AC-10: Gagal tanpa baris baru (dataset): kategori `sains` → `category`/`category_invalid`; judul `'  '` → `title`/`title_required`; judul 256 karakter → `title`/`too_long`; acara kosong → `event_name`/`event_name_required`; tingkat `dunia` → `level`/`level_invalid`; jenis `tim` → `kind`/`kind_invalid`; `achieved_on` `2026-02-30` → `achieved_on`/`date_invalid`; `2026-10-16` → `achieved_on`/`achieved_on_future`; `2024-01-10` (tidak ada tahun ajaran) → `achieved_on`/`academic_year_not_found`; peserta kosong → `participants`/`participants_required`; 51 peserta → `participants_too_many`; `S2` dua kali → `participant_duplicate`; `individu` dengan dua peserta → `individual_single`; `beregu` dengan satu peserta → `team_too_small`; santri id 999 → `student_not_found`; santri terarsip → `student_archived`; peringkat kosong → `rank_required`; peringkat 51 karakter → `participants`/`too_long`. Tanggal di sela tahun ajaran: tahun ajaran `2027/2028` dibuat mulai 2027-07-15 dan waktu dibekukan 2027-07-20, `achieved_on` `2027-07-10` (setelah 2027-06-30) → `academic_year_not_found`.
- AC-11: Cakupan (`actingAs(K2)`, `kesantrian` bercakupan SLTP): membuat prestasi untuk `S1` (SD) → `participants`/`student_not_found`; untuk `S2` berhasil. `K2` mengubah prestasi beregu `S1`+`S2` → `achievement`/`outside_scope`, data tidak berubah. Tanpa user login (`Auth::logout()`), Action yang sama untuk `S1` berhasil (tanpa penyaringan, pola `UnitScope`).
- AC-12: Edit: mengubah judul prestasi terverifikasi → `verified_by` menjadi NULL; mengubah `rank` satu peserta prestasi terverifikasi → `verified_by` NULL; mengubah hanya `is_public` prestasi terverifikasi → `verified_by` tetap; `recorded_by` tidak berubah oleh editor lain. Mengganti peserta `S2` → `S3` (individu) meninggalkan tepat satu pivot `S3`. Peserta lama `S3` yang kemudian diarsipkan tetap boleh dipertahankan saat edit (tanpa error), dan tidak boleh ditambahkan ke prestasi lain (`student_archived`). Edit `achieved_on` prestasi `2026-08-17` (2026/2027) menjadi `2026-03-10` → `academic_year_id` berpindah ke `2025/2026`.
- AC-13: Prestasi publik dengan post `terbit` (tidak terhapus): edit `is_public` false → `is_public`/`public_post_exists`, tetap true. Bila post itu `draft` atau soft-deleted → edit berhasil.

Verify & delete
- AC-14: `actingAs(A)`: `A` memverifikasi prestasi `K` → `verified_by` `A`. Memverifikasi lagi → `achievement`/`already_verified`. `actingAs(A2)` (`admin-sekolah` bercakupan SLTP) memverifikasi prestasi beregu `S1`+`S2` → `outside_scope`, `verified_by` tetap NULL. `K` boleh memverifikasi prestasi yang ia catat sendiri secara Action (permission yang membatasi di UI).
- AC-15: `DeleteAchievement` (dataset gagal, baris tetap): prestasi terverifikasi → `verified_locked`; punya post soft-deleted → `has_post`; punya dokumen (dibuat dengan `withoutUnitScope`) → `has_documents`; bercakupan SLTP pada prestasi beregu SD+SLTP → `outside_scope`. Prestasi belum terverifikasi tanpa post dan dokumen → baris dan pivotnya terhapus.

Publikasi
- AC-16: Prestasi publik, terverifikasi, beregu `S2` ("Juara 1", "Ketua tim") + `S3` ("Juara 1"), organizer "Kemenag Kabupaten", `achieved_on` `2026-08-17` → `PublishAchievementAsPost(prestasi, C)` → satu post: `type` `prestasi`, `status` `draft`, `published_at` NULL, `title` = judul prestasi, `slug` `juara-1-mtq-tahfidz-10-juz`, `author_id` `C`, `achievement_id` = id prestasi, `unit_id` = SLTP (semua peserta SLTP), `excerpt` memuat "MTQ Tingkat Kabupaten 2026", "Kabupaten", dan "17 Agu 2026" (bukan "Aug"), `body` memuat nama `S2` dan `S3`, "Ketua tim", "Kemenag Kabupaten", dan "17 Agu 2026". Prestasi kedua dengan `achieved_on` `2026-10-03` → excerpt memuat "3 Okt 2026" (bukan "Oct").
- AC-17: Peserta lintas jenjang (`S1` SD + `S2` SLTP) → `unit_id` NULL; nama santri `<b>Ali</b>` muncul ter-escape (body tidak memuat tag `<b>` dari nama).
- AC-18: Gagal tanpa post baru (dataset): `is_public` false → `not_public`; belum terverifikasi → `not_verified`; sudah punya post (termasuk soft-deleted) → `already_published`. Judul yang slug-nya bentrok dengan post lain tidak gagal (slug diberi akhiran `-2`). Balapan: post lain ber-`achievement_id` sama disisipkan lewat `DB::table` di listener `Post::saving` saat `achievement_id` di-set (setelah `SavePost` selesai) → `already_published`, tidak ada post baru dari pemanggilan itu. Exception unique slug yang dilempar dari dalam `SavePost` (disimulasikan dengan mock `GenerateSlug` yang mengembalikan slug terpakai) **tidak** dipetakan menjadi `already_published` (pesan `slug_taken` F016 atau `UniqueConstraintViolationException` diteruskan).

Rekap
- AC-19: Data (`actingAs(A)`; semua terverifikasi kecuali disebut): tahun `2026/2027`: P1 (tahfidz, kabupaten, `S2`), P2 (tahfidz, pondok, `S2`+`S3`), P3 (olahraga, provinsi, `S1`), PU (akademik, nasional, `S3`, **belum diverifikasi**); tahun `2025/2026`: P4 (seni, nasional, `S2`). `SummarizeAchievements(2026/2027)` → `total` 3, `participations` 4, `by_category['tahfidz']` 2, `by_category['akademik']` 0, `by_category['seni']` 0, `by_level['provinsi']` 1, `by_level['nasional']` 0, `matrix['tahfidz']['pondok']` 1; semua kunci `CATEGORIES`/`LEVELS` ada. `academicYearId` NULL → `total` 4.
- AC-20: Bukti aturan verifikasi dan jenjang: `SummarizeAchievements(2026/2027, verifiedOnly: false)` → `total` 4, `participations` 5, `by_category['akademik']` 1, `by_level['nasional']` 1. `unitId` SLTP → `total` 2, `participations` 3 (P1 `S2`, P2 `S2`+`S3`). Prestasi beregu lintas jenjang P5 (`S1` SD + `S2` SLTP, terverifikasi, 2026/2027) ditambahkan: `unitId` SLTP → `participations` 4 (hanya `S2` dari P5), `unitId` SD → `total` 2 (P3, P5) dan `participations` 2. Sebagai `actingAs(A2)` (bercakupan SLTP) tanpa P5: `total` 2 (P3 tidak terlihat), `participations` 3. Jumlah query ≤ 3.
- AC-21: `SummarizeStudentAchievements(S2)` → `total` 3, `unverified` 0, `highest_level` `nasional`, `by_level['kabupaten']` 1; dengan tahun `2026/2027` → `total` 2, `highest_level` `kabupaten`. `S3` (P2 terverifikasi + PU belum) → `total` 1, `unverified` 1, `highest_level` `pondok`; dengan `verifiedOnly: false` → `total` 2, `highest_level` `nasional`. Santri tanpa prestasi → `total` 0, `highest_level` NULL.

Cakupan & policy
- AC-22: Prestasi P1 (`S2` SLTP), P3 (`S1` SD), P5 (beregu `S1`+`S2`), P6 (peserta `S1` lalu `S1` diarsipkan). `actingAs(A2)` (SLTP): `Achievement::query()` = [P1, P5]. `actingAs` user bercakupan SD: [P3, P5, P6]. `actingAs` user tanpa cakupan: semua. `P5->isFullyVisibleTo(A2)` false, `P1->isFullyVisibleTo(A2)` true. Untuk `A2`, `P5->students` hanya memuat `S2`.
- AC-23: Policy (dataset peran × aksi pada prestasi belum terverifikasi tanpa post): `kesantrian` boleh `viewAny`, `view`, `create`, `update`, `delete`, tidak boleh `verify`, `publish`; `admin-sekolah` boleh `viewAny`, `view`, `verify`, tidak boleh `create`, `update`, `delete`; `admin-konten` tidak boleh `viewAny`, boleh `publish` hanya untuk prestasi publik terverifikasi tanpa post; `bendahara`, `admin-psb`, `musyrif` tidak boleh apa pun. `delete` false untuk prestasi terverifikasi/punya post/punya dokumen; `verify` false untuk prestasi terverifikasi; `update`/`delete`/`verify` false untuk prestasi yang tidak sepenuhnya dalam cakupan user. `publish` mensyaratkan juga `create:post`: user uji dengan `publish:achievement` tanpa `create:post` → `publish` false untuk prestasi yang memenuhi syarat; `admin-konten` (punya keduanya) → true.

Dokumen prestasi (perluasan F013)
- AC-24: Morph & cakupan dokumen: dokumen `SERTIFIKAT` milik P1 (`S2` SLTP) dan P3 (`S1` SD). `Document::query()` sebagai `A2` memuat dokumen P1 dan tidak memuat dokumen P3; tanpa cakupan keduanya. `documentable` dokumen P1 adalah instance P1, `documentable_type` `achievement`.
- AC-25: Dinilai sebagai **himpunan** (urutan bebas): `Document::visibleOwnerTypesFor(kesantrian)` = {`student`, `achievement`} dan tidak memuat `guardian`/`staff`; `admin-sekolah` = {`student`, `guardian`, `staff`, `achievement`}; user tanpa `view-any:achievement` (mis. peran uji dengan permission dokumen saja) tidak memuat `achievement`.
- AC-26: `actingAs(K)`: `UploadDocument` untuk P1 jenis `SERTIFIKAT` → dokumen di disk `private` folder `documents/achievement/{P1.id}/`, `status` `menunggu`. Jenis `KK` (owner santri) untuk P1 → `document_type_owner_mismatch`. `actingAs(K2)` (SLTP) mengunggah untuk P3 (SD) → `documentable`/`owner_not_found`, disk kosong.
- AC-27: `Gate::forUser(...)->allows('createFor', [Document::class, $owner])`: `kesantrian` (tanpa `create:document`) boleh untuk P1; `admin-sekolah` (punya `create:document`, tanpa `update:achievement`) tidak boleh untuk P1; `kesantrian` tetap **tidak** boleh untuk pemilik santri `S2`; `admin-sekolah` tetap boleh untuk `S2` (aturan F013 tidak berubah). `K2` (SLTP) tidak boleh untuk P5 (beregu lintas jenjang). `download` dokumen `SERTIFIKAT` P1 oleh `kesantrian` → boleh; `verify` oleh `admin-sekolah` → boleh.
- AC-28: `DocumentsRelationManager` di `EditAchievement` (P1) sebagai `K`: tab tampil; aksi "Unggah dokumen" dengan opsi jenis hanya `SERTIFIKAT` dan `FOTO_PRESTASI` (tanpa jenis santri); unggah berhasil. Modul `dokumen` mati → tab tidak tampil, `EditAchievement` tetap 200. `ListDocuments` sebagai `A` menampilkan dokumen P1 dengan jenis pemilik "Prestasi" dan nama pemilik = judul P1; pencarian judul P1 menemukannya.

UI Filament
- AC-29: Akses (dataset): `kesantrian` dan `admin-sekolah` → `GET /admin/achievements` dan `/admin/achievement-recap` 200, menu "Prestasi" tampil; `kesantrian` → `/admin/achievements/create` 200; `admin-sekolah` → `/admin/achievements/create` 403; `admin-konten`, `bendahara`, `admin-psb`, `musyrif` → `/admin/achievements` dan `/admin/achievement-recap` 403. Modul `prestasi` mati → keduanya 403 untuk `kesantrian` dan super-admin, menu tidak tampil. `A2` membuka `/admin/achievements/{P3}/edit` (SD) → 404.
- AC-30: `CreateAchievement` sebagai `K`: isi form valid (beregu, dua peserta lewat Repeater) → tersimpan lewat `SaveAchievement` (`recorded_by` `K`). Peserta satu orang dengan jenis beregu → error `team_too_small` tampil pada `data.participants`, tidak ada baris baru. Opsi santri di Select untuk `K2` (SLTP) tidak memuat `S1`. URL `?student={S2.id}` mengisi peserta pertama dengan `S2`.
- AC-31: `EditAchievement` sebagai `K` untuk prestasi terverifikasi: mengubah judul lalu simpan → `verified_by` NULL dan notifikasi sukses; form memuat peserta lama (termasuk peringkat dan peran).
- AC-32: `ListAchievements` sebagai `A`: filter tahun ajaran bawaan = tahun aktif (P4 tahun lalu tidak tampil); badge verifikasi "Belum diverifikasi"/"Terverifikasi"; aksi "Verifikasi prestasi" tampil untuk prestasi belum terverifikasi, memanggilnya → `verified_by` `A`; aksi tersembunyi untuk yang sudah terverifikasi; aksi edit dan hapus tidak tampil untuk `A`. Sebagai `K`: aksi hapus tampil untuk prestasi belum terverifikasi tanpa post/dokumen dan menghapusnya; tersembunyi untuk prestasi terverifikasi. Tidak ada bulk action. Filter santri `S3` hanya menampilkan prestasi dengan peserta `S3`. Kolom tanggal P1 (`2026-08-17`) tampil "17 Agu 2026".
- AC-33: Publikasi dari `ListAchievements` sebagai super-admin: aksi "Terbitkan sebagai berita" tampil hanya untuk prestasi publik terverifikasi tanpa post; memanggilnya → post draf dengan `achievement_id` terisi dan redirect ke `EditPost`. Modul `konten` mati → aksi tidak tampil. Sebagai `K` aksi tidak tampil.
- AC-34: `ListPosts` sebagai `C`: header action "Buat berita dari prestasi" tampil; opsi Select hanya memuat prestasi publik terverifikasi tanpa post (tidak memuat yang belum publik, belum terverifikasi, atau sudah punya post); memilih satu → post draf dibuat dan redirect ke `EditPost`. Mengirim id prestasi belum terverifikasi langsung lewat Livewire → error `achievement_id`/`not_publishable`, tidak ada post baru. Modul `prestasi` mati → aksi tidak tampil, `/admin/posts` tetap 200. User `admin-konten` tanpa `publish:achievement` (permission dicabut di test) → aksi tidak tampil. User `admin-konten` dengan `create:post` dicabut (permission dicabut di test) → aksi tidak tampil dan pemanggilan langsung ditolak (tidak ada post baru).
- AC-35: Halaman `AchievementRecap` sebagai `A` dengan data AC-19: matriks menampilkan angka per kategori × tingkat sesuai `SummarizeAchievements` (PU tidak dihitung, keterangan "Hanya prestasi terverifikasi."); tabel santri tahun aktif memuat `S2` (2) di baris pertama, lalu `S1` (1) dan `S3` (1) urut nama (fixture memberi nama sehingga `S1` < `S3`); tingkat tertinggi `S2` "Kabupaten", `S3` "Pondok". Toggle "Termasuk yang belum diverifikasi" dinyalakan oleh `A` (punya `verify`) → total 4 dan tingkat tertinggi `S3` "Nasional". Peran uji yang hanya punya `view-any:achievement` (tanpa `create`/`verify`) tidak melihat toggle, dan mengeset nilainya langsung lewat Livewire tidak mengubah angka. Filter jenjang SLTP menghilangkan `S1`; tahun `2024/2025` tanpa data (dibuat di test) menampilkan "Belum ada prestasi untuk tahun ajaran ini." Sebagai `A2` (SLTP) baris `S1` tidak ada.
- AC-36: Tab prestasi di `EditStudent` (`S3`) sebagai `A`: tampil, memuat P2 (badge "Terverifikasi") **dan** PU (badge "Belum diverifikasi") dengan peringkat; deskripsi "1 prestasi terverifikasi, tingkat tertinggi Pondok. 1 belum diverifikasi."; untuk `S2` deskripsi "3 prestasi terverifikasi, tingkat tertinggi Nasional." tanpa keterangan belum diverifikasi; tidak ada aksi edit/hapus; header "Catat prestasi" tidak tampil untuk `A` (tanpa `create`) dan tampil bagi super-admin dengan URL `CreateAchievement?student={S3.id}`. Modul `prestasi` mati → tab tidak tampil dan `EditStudent` tetap 200.
- AC-37: Penjaga hapus (sebagai super-admin): `EditUser` aksi `delete` tersembunyi untuk user yang mencatat prestasi dan untuk user yang memverifikasi prestasi, tampil untuk user tanpa keduanya; pemeriksaan tanpa unit scope (prestasi santri SD tetap menahan walau dibuat oleh user bercakupan SLTP lain). `AcademicYearResource` aksi `delete` tersembunyi untuk tahun ajaran nonaktif yang punya prestasi, tampil untuk tahun ajaran nonaktif tanpa prestasi.

Dashboard
- AC-38: `RecentAchievementsWidget` sebagai `A` dengan 6 prestasi terverifikasi + 1 prestasi belum diverifikasi yang `achieved_on`-nya paling baru: `isModuleAvailable()` true; menampilkan 5 judul terverifikasi terbaru urut `achieved_on` desc (prestasi terverifikasi tertua dan prestasi belum diverifikasi tidak tampil); tanggal tampil format `translatedFormat` (mis. "17 Agu 2026"); link "Lihat semua prestasi" ke index `AchievementResource`. Hanya prestasi belum diverifikasi → "Belum ada prestasi terverifikasi." Modul `prestasi` mati atau tabel `achievements` tidak ada → "Modul belum tersedia." (kasus milik widget ini dipindah dari F018 AC-6 ke sini). `actingAs(A2)` prestasi SD saja tidak tampil. `canView()`: false untuk user uji yang punya `view-dashboard` + `view-student-metrics` tanpa `view-any:achievement` (widget tidak dirender, `assertDontSeeLivewire`), true untuk `admin-sekolah` dan `kesantrian`. `ListRecentAchievements` ≤ 3 query untuk 5 prestasi beregu.

Teks
- AC-39: Semua kunci `pondok.prestasi.errors.*` yang dipakai Action ada (iterasi daftar alasan, `__()` tidak mengembalikan kunci mentah); `pondok.achievement.levels.*` dan `categories.*` lengkap untuk semua nilai konstanta; `pondok.navigation.prestasi` = "Prestasi"; `pondok.document.owner_types.achievement` = "Prestasi".

## Rencana test

| AC | File | Nama test |
|---|---|---|
| 1, 2 | `tests/Feature/Database/PrestasiConstraintsTest.php` | `AC-1: creates achievement tables from the ERD`, `AC-2: enforces achievement constraints` |
| 3, 4 | `tests/Feature/Database/PostAchievementForeignKeyTest.php` | `AC-3: links posts to achievements and keeps posts indexes`, `AC-4: clears orphan achievement ids before adding the foreign key`, `AC-4: restores a plain index on down` |
| 5, 22 | `tests/Feature/Models/AchievementTest.php` | `AC-5: relates achievements, students and posts`, `AC-22: scopes achievements by participant units` |
| 6 | `tests/Feature/Database/Seeders/PrestasiSeedersTest.php` | `AC-6: enables prestasi and seeds the achievement photo type` |
| 7 | `tests/Feature/Database/Seeders/RolePermissionSeederTest.php` (dataset + test baru) | `AC-7: creates achievement permissions`, `AC-7: keeps delete-any achievement for super-admin only` |
| 8–13 | `tests/Feature/Actions/Prestasi/SaveAchievementTest.php` | `AC-8: records an individual achievement`, `AC-9: records a team achievement and derives the academic year` (dataset batas inklusif + tumpang tindih), `AC-10: rejects invalid achievements` (dataset), `AC-11: limits participants to the unit scope`, `AC-12: resets verification on substantive edits`, `AC-13: keeps published achievements public` |
| 14, 15 | `tests/Feature/Actions/Prestasi/VerifyAndDeleteAchievementTest.php` | `AC-14: verifies an achievement once`, `AC-15: guards achievement deletion` (dataset), `AC-15: deletes an unverified achievement` |
| 16–18 | `tests/Feature/Actions/Prestasi/PublishAchievementAsPostTest.php` | `AC-16: creates a draft post from an achievement`, `AC-17: uses a general unit and escapes names`, `AC-18: rejects unpublishable achievements` (dataset), `AC-18: maps a duplicate post race to already_published` |
| 19–21 | `tests/Feature/Actions/Prestasi/SummarizeAchievementsTest.php` | `AC-19: summarizes achievements per year`, `AC-20: counts unverified achievements only on request`, `AC-20: counts participations per unit and scope`, `AC-21: summarizes a student` |
| 23 | `tests/Feature/Policies/AchievementPolicyTest.php` | `AC-23: authorizes achievement actions per role` (dataset), `AC-23: requires full scope to manage team achievements` |
| 24–27 | `tests/Feature/Actions/Dokumen/AchievementDocumentsTest.php` | `AC-24: scopes achievement documents`, `AC-25: adds achievement to visible owner types`, `AC-26: uploads certificates for achievements`, `AC-27: lets achievement editors upload without document create permission` |
| 28 | `tests/Feature/Filament/Resources/Achievements/AchievementDocumentsRelationManagerTest.php` | `AC-28: manages certificates and photos of an achievement`, `AC-28: lists achievement documents in the document queue` |
| 29 | `tests/Feature/Filament/PrestasiAccessTest.php` | `AC-29: shows prestasi pages per role` (dataset), `AC-29: hides prestasi when the module is off`, `AC-29: hides achievements outside the unit scope` |
| 30, 31 | `tests/Feature/Filament/Resources/Achievements/AchievementFormTest.php` | `AC-30: creates an achievement through the form`, `AC-30: shows participant errors on the form`, `AC-31: edits a verified achievement` |
| 32, 33 | `tests/Feature/Filament/Resources/Achievements/ListAchievementsTest.php` | `AC-32: lists, verifies and deletes achievements`, `AC-33: publishes an achievement as a draft post` |
| 34 | `tests/Feature/Filament/Resources/Posts/PostFromAchievementTest.php` | `AC-34: creates a post from an achievement`, `AC-34: rejects an unpublishable achievement sent directly`, `AC-34: hides the action without module or permission` |
| 35 | `tests/Feature/Filament/Pages/AchievementRecapTest.php` | `AC-35: shows the verified achievement recap`, `AC-35: includes unverified achievements for managers only` |
| 36 | `tests/Feature/Filament/Resources/Students/StudentAchievementsTest.php` | `AC-36: shows achievements on the student page` |
| 37 | `tests/Feature/Filament/Resources/Achievements/AchievementDeleteGuardsTest.php` | `AC-37: keeps users and academic years with achievements` |
| 38 | `tests/Feature/Filament/Widgets/RecentAchievementsWidgetTest.php` (+ pembaruan test F018 AC-6) | `AC-38: lists recent verified achievements on the dashboard`, `AC-38: shows the unavailable state`, `AC-38: requires achievement access to view the widget` |
| 39 | `tests/Feature/Filament/Resources/Achievements/PrestasiTranslationsTest.php` | `AC-39: translates every prestasi message` |

Catatan untuk red-tester: berhenti dan laporkan bila migration `documents` (F013), `posts` (F016), atau trait `InteractsWithDashboardModule` (F018) belum ada di repo.

Perubahan test fitur lain bersifat **tambahan minimal** (review #1 #6), dicatat sebagai diff di laporan RED:
- F013 AC-29: asersi `visibleOwnerTypesFor` diubah menjadi perbandingan himpunan (urutan bebas); `admin-sekolah` memuat keempat alias (`student`, `guardian`, `staff`, `achievement`); `kesantrian` tetap **tidak** memuat `guardian` maupun `staff`. Asersi lain di test itu tidak disentuh.
- F018 AC-6: hanya mencabut `RecentAchievementsWidget` dari daftar widget kerangka; widget kerangka lain dibiarkan sesuai kondisi repo saat itu (F013/F016 mungkin sudah mengubahnya). Kasus "saklar mati / tabel tidak ada" untuk widget ini dipindah ke AC-38. Bila F018 AC-1 (matriks widget per peran) memakai user dengan `view-student-metrics` tanpa `view-any:achievement`, matriks itu disesuaikan hanya untuk `RecentAchievementsWidget`.
- Test F009 yang mengasersi daftar persis `DocumentTypeSeeder::TYPES` (bila ada): tambah `FOTO_PRESTASI` saja.

## Urutan implementasi

1. Migration `achievements` + `achievement_student`, model `Achievement` (konstanta, cast, relasi, accessor), factory (state `verified`, `public`, `team`, `forStudents(...)`), relasi di `Student`/`User`/`AcademicYear` — AC-1, 2, 5 (sebagian).
2. Migration FK + unique `posts.achievement_id` (tiga blok `Schema::table` terpisah), relasi `Post::achievement()`/`Achievement::post()` — AC-3, 4, 5.
3. `HasUnitScope` + `applyUnitScope` + `isFullyVisibleTo` pada `Achievement` — AC-22.
4. `SettingSeeder`, `DocumentTypeSeeder` (`FOTO_PRESTASI`), `RolePermissionSeeder` + test dataset — AC-6, 7.
5. `SaveAchievement` + kunci error miliknya — AC-8–13.
6. `VerifyAchievement`, `DeleteAchievement` + kunci error — AC-14, 15.
7. `PublishAchievementAsPost` + view body + kunci error/teks — AC-16–18.
8. `SummarizeAchievements`, `SummarizeStudentAchievements`, `ListRecentAchievements` (parameter `verifiedOnly`, bawaan true; `participations` per jenjang) — AC-19–21.
9. Perluasan F013: morph map, `applyUnitScope` dokumen, `visibleOwnerTypesFor`, `DocumentPolicy::createFor`, cabang pemilik `UploadDocument`/`ReplaceDocument`, `HasDocuments` — AC-24–27.
10. `AchievementResource` (form, tabel, aksi), `shield:generate`, `AchievementPolicy` (verify/publish + `create:post`/cakupan), navigasi — AC-23, 29–33.
11. `DocumentsRelationManager` di `EditAchievement` + label/pencarian pemilik prestasi di `DocumentResource` — AC-28.
12. Aksi "Buat berita dari prestasi" di `ListPosts` — AC-34.
13. Halaman `AchievementRecap` — AC-35.
14. `AchievementsRelationManager` di `StudentResource` — AC-36.
15. Penjaga hapus `EditUser` dan `AcademicYearResource` — AC-37.
16. Isi `RecentAchievementsWidget` + pembaruan test F018 — AC-38.
17. Sisa teks `lang/id`, design guide §3/§4, checklist §0, Pint — AC-39.
18. Sinkronkan `docs/erd.md` (commit `docs(prestasi)`): `achievement_student` PK komposit + FK aksi, index `achieved_on`, `posts.achievement_id` unique + FK `restrictOnDelete`, `academic_year_id` diturunkan dari `achieved_on`, alias morph `achievement`, jenis dokumen `FOTO_PRESTASI`, aturan cakupan prestasi lewat peserta.

## Risiko & pertanyaan terbuka

1. **Dependensi.** `depends_on` F015 hanya F002 dan F013, padahal F015 juga membutuhkan F016 (`posts`, `SavePost`, `ListPosts`) dan F018 (widget). *Default:* orkestrator menambahkan F016 dan F018 ke `depends_on` F015 (atau memastikan keduanya terimplementasi sebelum RED). Bila F018 belum siap, langkah 16 dan AC-38 dapat dipisah menjadi entri tersendiri (lihat usulan).
2. **Cakupan jenjang prestasi.** *Default:* terlihat bila ada minimal satu peserta dalam cakupan; dikelola (ubah, hapus, verifikasi) hanya bila semua peserta dalam cakupan; nama peserta jenjang lain disembunyikan. Alternatif: wajib satu jenjang per prestasi (lebih sederhana tetapi tim lintas jenjang harus dicatat ganda).
3. **Siapa memverifikasi.** *Default:* `admin-sekolah` (dan super-admin) memverifikasi; `kesantrian` mencatat. Pencatat boleh memverifikasi prestasinya sendiri bila punya permission (tidak ada aturan empat mata). Alternatif: `kesantrian` juga mendapat `verify`.
4. **Edit membatalkan verifikasi; rekap hanya terverifikasi.** *Diputuskan orkestrator (review #1 #1, opsi a):* rekap per tahun, rekap per santri, dan widget bawaan hanya menghitung prestasi terverifikasi (`verifiedOnly = true`); halaman rekap punya toggle "Termasuk yang belum diverifikasi" untuk pengelola; tab santri menampilkan semua dengan badge. Perubahan substantif mengosongkan `verified_by` sehingga prestasi keluar dari angka resmi sampai diverifikasi ulang; `is_public` tidak. ERD tidak punya `verified_at`; tidak ditambahkan.
5. **Hapus prestasi bersertifikat.** Dokumen F013 tidak bisa dihapus, sehingga prestasi yang sudah punya sertifikat tidak bisa dihapus (`has_documents`). *Default:* diterima; koreksi lewat edit. Penghapusan dokumen perlu entri baru bila dibutuhkan.
6. **Sampul berita prestasi.** Foto prestasi ada di disk `private`; berita butuh gambar di disk `public`. *Default:* tidak disalin otomatis (menghindari publikasi foto anak tanpa pemeriksaan); admin konten mengunggah sampul sendiri di `EditPost`.
7. **Data pribadi di berita.** Nama santri dan peringkat masuk ke `body` berita. *Default:* `is_public` dianggap persetujuan publikasi (diisi kesantrian), berita tetap draf sampai admin konten menerbitkannya.
8. **Activity log prestasi.** Bukan data keuangan. *Default:* tidak; jejak cukup `recorded_by`/`verified_by`.
9. **Satu berita per prestasi.** *Default:* unique `posts.achievement_id` termasuk post terhapus; berita yang terhapus dipulihkan, bukan dibuat ulang. Pengubahan `type` post berprestasi oleh admin konten tidak dikunci di F015 (cukup dicatat; F017 menampilkan data prestasi lewat `achievement_id`).
10. **Tahun ajaran dari tanggal.** *Default:* `academic_year_id` diturunkan dari `achieved_on`; tanggal di sela dua tahun ajaran ditolak (`academic_year_not_found`). Mengubah rentang tahun ajaran kemudian tidak memindahkan prestasi lama (snapshot).
11. **Beregu minimal dua santri.** *Default:* ya (`team_too_small`). Tim campuran dengan pihak luar yang hanya satu santri pondok dicatat sebagai individu dengan peran "Anggota tim".
12. **Rebuild SQLite pada `posts`.** Penambahan FK membangun ulang tabel di SQLite; AC-3 menjaga index, unique slug, FK lain, dan soft delete. Di MySQL/Postgres data `achievement_id` yatim dibersihkan dulu (AC-4).
13. **Pengubahan test fitur lain.** F015 mengubah ekspektasi test F013 (AC-29 `visibleOwnerTypesFor`) dan F018 (AC-6 widget kerangka). Ini disengaja dan harus dicatat di laporan RED.
14. **Kesantrian tidak bisa membuka `EditStudent`.** Tab prestasi santri hanya untuk peran dengan `update:student`; `kesantrian` memakai halaman rekap dan filter santri di daftar prestasi.
15. **Berita tidak disinkronkan dengan prestasi** (review #1 #10b). *Default:* berita yang dibuat dari prestasi adalah salinan saat dibuat. Edit substantif prestasi yang beritanya sudah `terbit` membatalkan verifikasi prestasi, tetapi berita tetap tayang dengan isi lama; admin konten menyunting atau menarik berita secara manual. Satu-satunya kunci adalah `public_post_exists` (prestasi tidak bisa dijadikan non-publik selama beritanya terbit).
16. **Portabilitas `rank`** (review #1 #9). `RANK` kata terpesan di MySQL 8.0.2+; akses `rank`/`role`/`level` hanya lewat builder/Eloquent/`pivot`, tanpa fragmen SQL mentah. Green-coder memastikan `grep -n "Raw\|DB::raw" app/Actions/Prestasi` tidak menyebut kolom itu.
17. **Mekanisme cakupan** (review #1 #5). Seluruh pemeriksaan cakupan F015 memakai user login (global scope + `Auth::user()`), sama dengan F013. Konsekuensinya Action yang dipanggil dari console tidak menyaring jenjang; dapat diterima karena console tidak punya cakupan.

## Riwayat revisi

- r1 (DRAFT): rencana awal.
- r2 (DRAFT, menjawab Review #1): #1 major — keputusan orkestrator opsi (a): `verifiedOnly = true` bawaan di `SummarizeAchievements`, `SummarizeStudentAchievements` (+ kolom `unverified`), `ListRecentAchievements`; toggle "Termasuk yang belum diverifikasi" di halaman rekap hanya untuk pengelola; tab santri menampilkan semua dengan badge; fixture AC-19/20/21/35/36/38 menyebut status verifikasi, prestasi `PU` belum diverifikasi sebagai bukti; Risiko #4. #2 — tanggal teks lewat `translatedFormat`/locale `id`, data bawaan `2026-08-17`, assert "Agu"/"Okt" (AC-16, AC-32, AC-38). #3 — migrasi `posts` dipecah tiga blok `Schema::table`, AC-3 menambah assert default `status`, `achievement_id` nullable, `meta`/`cover_path`. #4 — AC-4 memanggil `down()`/`up()` instance migration (tanpa `--step`), kasus id yang valid dipertahankan dan semua yatim saat `achievements` kosong. #5 — cakupan memakai user login seperti F013 (`Auth::user()`, global scope), semua test cakupan `actingAs`; `DocumentPolicy::createFor(User, Model $owner)` sebagai signature unggah per pemilik (AC-27). #6 — perubahan test F013 AC-29 (himpunan) dan F018 AC-6 (cabut satu widget) dinyatakan minimal; kasus modul mati widget dipindah ke AC-38. #7 — `publish` mensyaratkan `create:post` (policy, aksi `ListPosts`, AC-23, AC-34). #8 — batas tahun ajaran inklusif + tumpang tindih (AC-9), tanggal di sela (AC-10), edit `achieved_on` memindahkan tahun (AC-12), `participations` per `unitId` hanya peserta jenjang itu (AC-20). #9 — larangan SQL mentah untuk `rank`/`role`/`level`, `highest_level` dihitung di PHP (Risiko #16). #10 — try/catch hanya di `forceFill()->save()` (AC-18 kasus slug), berita tidak disinkronkan (Risiko #15), `RecentAchievementsWidget::canView` mensyaratkan `view-any:achievement` (AC-38).

## Review #1 — 2026-10-15

Verdict: CHANGES_REQUESTED

Ringkasan: 0 blocker, 1 major, 9 minor. Cakupan kebutuhan (feature_list, PRD 3.7, ERD Prestasi) tercapai dan struktur rencana kuat: migrasi `posts` (bersih yatim, unique, FK, uji rebuild SQLite), cakupan jenjang (terlihat/kelola), perluasan F013, publikasi lewat `SavePost`. Satu kontradiksi makna verifikasi harus diputuskan sebelum RED. Dependensi F013/F016/F018 sudah di `depends_on` F015 (belum `done`; red-tester tidak boleh mulai sebelum ketiganya terimplementasi, sesuai catatan rencana).

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | major | Verifikasi vs rekap/widget (UI "Verifikasi prestasi", `SummarizeAchievements`, `SummarizeStudentAchievements`, `ListRecentAchievements`, AC-19/21/35/38) | Deskripsi aksi verifikasi berjanji "Prestasi yang diverifikasi dihitung di rekap resmi dan bisa diterbitkan sebagai berita", tetapi semua Action rekap menghitung SEMUA prestasi (terverifikasi atau belum); AC-19/21/35 tidak menyebut status verifikasi di fixture. Hanya "terbit sebagai berita" yang benar-benar bergantung verifikasi. Akibatnya teks UI menyesatkan, dan angka rekap (yang kelak dipakai rapor/portal wali/surat rekomendasi, ERD Prestasi) bisa memuat prestasi belum diverifikasi, sedangkan edit substantif membatalkan verifikasi sehingga angka naik-turun tanpa kontrol. | Putuskan dan tulis eksplisit: (a) rekap dan `SummarizeStudentAchievements` hanya menghitung terverifikasi (atau parameter `verifiedOnly`, bawaan true, dengan opsi "termasuk belum diverifikasi" di halaman rekap); atau (b) rekap menghitung semua dan deskripsi verifikasi diubah menjadi hanya soal penerbitan berita. Sesuaikan fixture AC-19/20/21/35/36/38 (set `verified_by` per prestasi) dan tambahkan satu kasus yang membuktikan keputusan itu (prestasi belum terverifikasi masuk/tidak masuk hitungan). Catat di Risiko. |
| 2 | minor | `PublishAchievementAsPost` / `excerpt` (AC-16) | Tanggal `j M Y` memakai nama bulan; `format()` Carbon memberi "Oct", sedangkan UI Indonesia butuh "Okt". AC-16 memakai 20 September ("Sep" sama di kedua bahasa) sehingga bug tidak tertangkap. | Wajibkan `translatedFormat` (locale `id`) dan ganti tanggal AC-16 ke bulan yang berbeda ejaan (mis. 17 Agu 2026 atau 2026-05-xx "Mei"), lalu assert "Agu"/"Okt". Berlaku juga untuk kolom tabel dan widget. |
| 3 | minor | Migrasi `posts` (langkah 1-3) | Menjalankan `dropIndex`, `unique`, dan `foreign` dalam satu `Schema::table` di SQLite berisiko: rebuild tabel membaca index saat kompilasi, sehingga index lama bisa dibuat ulang atau unique hilang. Preseden `add_admission_wave_foreign_key_to_fee_rates_table` hanya menambah FK. | Pisahkan tiga langkah dalam tiga blok `Schema::table` berurutan (bersihkan yatim, lalu drop index + unique, lalu FK) dan `down()` kebalikannya. AC-3 sudah menjaga hasilnya; tambahkan assert default kolom `status` = `draft`, `achievement_id` nullable, dan `meta`/`cover_path` tetap ada pasca rebuild. |
| 4 | minor | AC-4 (uji pembersihan yatim) | `migrate:rollback --step=1` bergantung pada migration `posts` F015 menjadi migration terakhir; migration fitur lain yang datang kemudian (mis. F011/F017) akan merusak test. Perlu diingat juga tabel `achievements` masih kosong saat pembersihan, jadi SEMUA `achievement_id` non-NULL menjadi yatim (perilaku benar, tetapi harus diuji eksplisit). | Gunakan `Artisan::call('migrate:rollback', ['--path' => <file>, '--realpath' => true])` (atau muat instance migration dan panggil `down()`/`up()`), lalu `migrate --path`. Tambahkan kasus: post ber-`achievement_id` yang kebetulan sama dengan id prestasi yang ada tetap dipertahankan bila prestasi itu dibuat sebelum migration dijalankan ulang. |
| 5 | minor | `UploadDocument`/`ReplaceDocument` cabang prestasi (AC-26) | F013 memeriksa pemilik terhadap cakupan "user login" (`Student::query()`), sedangkan Action F015 lain memakai `$actor->scopedUnitIds()` eksplisit. Rencana menulis "dengan cakupan uploader" tanpa menyebut mekanisme; test `K2` bisa lulus/gagal tergantung `actingAs`. | Tegaskan: gunakan `$uploader->scopedUnitIds()` + `Achievement::withoutUnitScope()` agar sama dengan Action prestasi lain, atau wajibkan `actingAs(K2)` di AC-26. Sebutkan juga signature `DocumentPolicy::create` untuk pemilik (policy `create` Filament bawaan tidak menerima model; F013 harus punya varian dengan `$owner`). |
| 6 | minor | Perubahan test F013 AC-29 dan F018 AC-6 | Maksud tidak dilemahkan, tetapi perintahnya terlalu longgar ("perlu diperbarui"). Saat F015 dikerjakan, F013/F016 sudah menyentuh daftar widget kerangka di F018 AC-6; menimpa seluruh daftar akan membuang perubahan mereka. | Nyatakan perubahan sebagai tambahan minimal: F013 AC-29 `visibleOwnerTypesFor` dinilai sebagai himpunan (urutan bebas) dengan `kesantrian` tetap TIDAK memuat `guardian`/`staff`, dan `admin-sekolah` memuat keempat alias; F018 AC-6 hanya mencabut `RecentAchievementsWidget` dari daftar kerangka (widget lain dibiarkan sesuai kondisi saat itu), sementara kasus "saklar mati/tabel tak ada" milik widget ini dipindah ke AC-38. Catat diff test ini di laporan RED. |
| 7 | minor | Policy `publish` / `PublishAchievementAsPost` | Aksi membuat `posts` tetapi policy hanya mensyaratkan `publish:achievement`; peran khusus yang diberi `publish:achievement` tanpa `create:post` bisa membuat draf berita (melewati permission Konten). `admin-konten` bawaan punya keduanya, jadi tidak terdeteksi test. | Syaratkan juga `Gate::allows('create', Post::class)` pada `publish` dan opsi `ListPosts`, dan tambahkan kasus ke AC-23/AC-34 (permission `create:post` dicabut → aksi tidak tampil). |
| 8 | minor | Dataset/validasi `SaveAchievement` (AC-9, AC-10) | Batas tahun ajaran tidak diuji padahal inklusif (`starts_on <= achieved_on <= ends_on`) dan ada aturan tumpang tindih (`starts_on` terbaru). Tidak ada AC bahwa edit `achieved_on` menurunkan ulang `academic_year_id`, dan `participations` pada `SummarizeAchievements` dengan filter `unitId` untuk tim lintas jenjang belum ditentukan (peserta semua jenjang atau hanya jenjang itu). | Tambahkan kasus `2026-06-30` -> `2025/2026`, `2026-07-01` -> `2026/2027`, tahun tumpang tindih, edit tanggal memindahkan tahun ajaran; tentukan dan uji `participations` per `unitId` (disarankan hanya peserta jenjang itu). |
| 9 | minor | `rank` pada `achievement_student` | `RANK` kata terpesan di MySQL 8.0.2+ (fungsi window). Aman lewat query builder yang mengutip identifier, tetapi gagal pada SQL mentah (`selectRaw`/`orderByRaw`/`whereRaw`) yang menyebut `rank` tanpa kutip. Nama kolom sudah di ERD, jadi jangan diganti. | Tambahkan ketentuan: semua akses `rank`/`role`/`level` lewat builder atau `pivot`, tanpa fragmen SQL mentah yang menyebut kolom itu; sebutkan di Risiko portabilitas. |
| 10 | minor | `PublishAchievementAsPost` langkah 5 dan edit setelah terbit | (a) `UniqueConstraintViolationException` juga dilempar `SavePost` untuk slug; catch harus dibatasi pada `forceFill->save()` agar slug tidak salah dipetakan ke `already_published`. (b) Edit substantif prestasi yang sudah ber-post `terbit` membatalkan verifikasi tetapi body berita tetap versi lama dan tetap tayang; tidak disebut. | (a) Batasi try/catch pada langkah update. (b) Tulis sebagai keputusan di Risiko (berita tidak disinkronkan; admin konten menyunting manual) atau tolak edit substantif seperti `public_post_exists`; minimal satu catatan agar tidak jadi kejutan. Tambahan kecil: aksi widget/halaman bisa ditambah syarat `AchievementResource::canViewAny()` agar daftar widget tidak menampilkan data bagi peran yang hanya diberi `view-student-metrics`. |

Catatan hasil cek lain (tanpa temuan): AC memetakan seluruh `acceptance` F015 (peringkat/sertifikat/foto, rekap per santri dan tahun, terbit sebagai berita) dan PRD 3.7; tabel/kolom/FK/index sesuai ERD (index `achieved_on` dan unique `posts.achievement_id` ditambahkan dan akan disinkronkan ERD langkah 18; unique cocok dengan relasi `achievements |o--o| posts`); tidak ada soft delete baru; permission per peran sesuai PRD 2 (kesantrian: prestasi; admin-konten: prestasi publik lewat `publish`; admin-sekolah memverifikasi); cakupan terlihat/kelola dan fail-closed konsisten dengan F013; tanpa Redis/Node, query portabel, test wajib CLAUDE.md (akses peran, cakupan jenjang, dashboard saat modul mati) tercakup.
## Review #2 — 2026-10-15

Verdict: APPROVED

Ringkasan: 0 blocker, 0 major, 2 minor (catatan). Semua temuan Review #1 sudah ditangani dan terpetakan ke AC.

| # | Tingkat | Bagian | Temuan | Saran |
|---|---|---|---|---|
| 1 | minor | Verifikasi #1 (major) | Selesai. `verifiedOnly = true` bawaan di tiga Action; toggle rekap hanya untuk user `create`/`verify` dan nilai kiriman langsung diabaikan; tab santri menampilkan semua dengan badge dan angka resmi terpisah; fixture AC-19/20/21/35/36/38 menyebut status verifikasi dengan `PU` belum diverifikasi sebagai bukti. Hitungan angka AC-19 (3/4, `academicYearId` NULL = 4), AC-20 (4/5 dengan `false`; SLTP 2/3, SD 2/2, SLTP + P5 = 4), AC-35 (S2 2, S1 1, S3 1; toggle menjadikan total 4) dan AC-36 sudah dicek konsisten. | - |
| 2 | minor | Verifikasi #2-#10 | Semua tertangani: `translatedFormat` dengan "Agu"/"Okt" (AC-16, 32, 38); migrasi `posts` dipecah dan AC-3 menambah default/nullable; AC-4 lewat instance migration; mekanisme cakupan user login + `actingAs` dan `DocumentPolicy::createFor` (AC-26/27); perubahan test F013 AC-29/F018 AC-6 minimal; `create:post` pada `publish` (AC-23/34); batas tahun inklusif, tumpang tindih, tanggal di sela, edit `achieved_on` (AC-9/10/12); larangan SQL mentah `rank` (Risiko #16); catch dibatasi di `forceFill()->save()` (AC-18) dan Risiko #15; `canView` widget (AC-38). | - |
| 3 | minor | AC-20 | Kalimat memulai dengan `verifiedOnly: false` lalu memberi angka `unitId` SLTP (total 2) tanpa menyebut ulang parameter; dengan `false`, `PU` (`S3`, SLTP) membuat total 3. Angka 2/3/4/2 hanya benar untuk bawaan `true`. | Tulis eksplisit `verifiedOnly` default pada tiap asersi `unitId` agar red-tester tidak salah baca. |
| 4 | minor | `DocumentPolicy::createFor` (F013) | `DocumentsRelationManager` F013 belum memakai `createFor` (rencana F013 hanya `create`); F015 mengubah file F013 untuk aksi unggah dan versi baru. | Pastikan perubahan itu dilaporkan green-coder dan test F013 yang memeriksa visibilitas aksi unggah (santri/wali/staff) tetap hijau. |
