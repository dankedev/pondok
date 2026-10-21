# F052 keuangan-performa-keandalan — Performa & keandalan keuangan (audit Grup 2)

Issue: #19
Status rencana: APPROVED

## Ringkasan & tujuan
Tindak lanjut audit ISO (`docs/audits/iso-25010-23026-audit.md`, Performance & Reliability): indeks untuk laporan tunggakan/ringkasan, generate tagihan manual lewat antrean agar tidak terpotong `max_execution_time` di cPanel, dan pembersih berkas bukti transfer yatim. Jalur penuh (migration, tagihan).

## Ruang lingkup
- Termasuk: (1) migration indeks `invoices(status, due_on)` dan `invoices(period, status)`; (2) aksi Filament "Buat tagihan bulanan" dan "Buat tagihan tahunan" men-dispatch Job, hasil dikirim sebagai notifikasi database Filament; (3) Action + command harian pembersih berkas `payment-proofs` tanpa Payment.
- Tidak termasuk (eksplisit, dengan alasan):
  - **Indeks `students(entered_on)`**: satu-satunya pemakai (`GenerateMonthlyInvoices.php:161-163`) memakai `whereNull(...) OR whereDate(entered_on) <= X`. `whereDate` membungkus kolom dengan fungsi (`date()`/`strftime`) sehingga indeks tidak terpakai, predikat cocok untuk hampir semua santri aktif, dan iterasi memakai `chunkById` (PK). Indeks tidak akan dipakai query mana pun (YAGNI).
  - **Ekspor CSV lewat antrean**: `ExportFinanceReportCsv` hanya membaca agregat SQL (ringkasan ≤ jumlah bulan, kas per hari/metode/jenis, subsidi per program). Satu-satunya bagian yang tumbuh adalah `tunggakan` (satu baris per santri menunggak: 1 query GROUP BY + 1 query santri, ribuan baris paling banyak), jauh di bawah batas waktu/memori. Antrean menuntut berkas di disk private, route unduh berizin, pemilik berkas, dan pembersihan, tanpa biaya nyata yang dihemat. Filament Exporter juga tidak cocok: ia mengekspor per record dari query model, sedangkan laporan ini blok agregat. **Butuh persetujuan user** (menyimpang dari ringkasan issue); bila user tetap ingin, jadikan issue terpisah.
  - **Cache agregat dashboard**: `FinanceOverviewWidget` tanpa polling, memanggil `SummarizeInvoices` (2 query agregat bulan berjalan, kini didukung indeks `(period, status)`) dan `SummarizeCashReceipts` (sudah ada indeks `payments(status, paid_on)`). Tidak ada bukti biaya; cache menambah invalidasi setelah pembayaran/pembatalan. Tidak dikerjakan.
  - Mengubah polling notifikasi panel (`databaseNotificationsPolling(null)` tetap), `ShouldBeUnique`, menghapus indeks lama `invoices(status, period)`, pembersihan direktori kosong, setting masa tenggang.

## Model data
Migration baru `database/migrations/2026_10_26_000000_add_report_indexes_to_invoices_table.php`:
- `up()`: `$table->index(['status', 'due_on'])` (dipakai `ListTopArrears` dan `SummarizeInvoices` overdue: `status IN (belum, sebagian) AND due_on < ?`) dan `$table->index(['period', 'status'])` (dipakai `FinanceReportFilter::invoiceQuery()`: rentang `period` + `status <> batal`, termasuk widget dashboard). Nama bawaan Laravel (`invoices_status_due_on_index`, `invoices_period_status_index`).
- `down()`: `dropIndex` keduanya. Tanpa perubahan data, tanpa SQL khusus satu database.
- `config/queue.php`: default `connections.database.retry_after` dinaikkan dari 90 ke 1900 (> `$timeout` Job 1800), env `DB_QUEUE_RETRY_AFTER` tetap bisa menimpa. Tanpa ini Job panjang dianggap kedaluwarsa dan ditandai gagal (`tries=1`) padahal masih berjalan.
- Tidak ada tabel baru: `jobs`, `failed_jobs`, dan `notifications` sudah ada; panel sudah `databaseNotifications()`.

## Action class
- `GenerateMonthlyInvoices::academicYearFor()` → `public` (perilaku sama, melempar `ValidationException` kunci `period`), untuk prasyarat sinkron sebelum dispatch.
- `GenerateAnnualInvoices`: ekstrak prasyarat (`annual_month_not_set`, `annual_month_outside_year`) ke `public function billingPeriod(AcademicYear): CarbonImmutable`; `handle()` memakainya. Logika generator tidak berubah.
- **Baru** `app/Actions/Keuangan/PruneOrphanPaymentProofs`: `handle(?CarbonInterface $now = null): int` (jumlah dihapus). Konstanta `GRACE_HOURS = 24`. Langkah: `Storage::disk(Payment::PROOF_DISK)->allFiles(Payment::PROOF_DIRECTORY)` → ambil yang `lastModified < now - 24 jam` → per potongan 500, cek `Payment::withoutGlobalScopes()->whereIn('proof_path', ...)` (semua status, termasuk `batal`) → abaikan berkas yang namanya diawali titik (`.gitkeep` dsb.) → hapus yang tidak direferensikan; bila satu `delete` gagal, catat peringatan dan lanjut (continue) → `Log::info` dengan `deleted` dan `checked`. Hanya `payments.proof_path` yang mereferensikan direktori ini (sudah dicek: `RecordPayment` satu-satunya penulis; portal wali/PSB tidak menyimpan ke `payment-proofs`).
- **Baru** `app/Jobs/Keuangan/GenerateInvoicesJob` (`ShouldQueue`, `php artisan make:job`): dibuat lewat `GenerateInvoicesJob::monthly(User, CarbonInterface $period)` atau `::annual(User, AcademicYear)`. `handle()` memanggil `GenerateMonthlyInvoices::handle($period, $user)` atau `GenerateAnnualInvoices::handle($year, $user)`; tidak ada logika bisnis di Job.
  - Berhasil → `Notification::make()->success()` judul `pondok.invoice.generated` (label periode/tahun, created/existing/skipped), body `generated_skipped_hint` bila skipped > 0, `->sendToDatabase($user)`.
  - `ValidationException` (mis. `already_running`) atau `RuntimeException` `number_conflict` → notifikasi database `danger` berisi pesan pertama; Job selesai normal (tidak masuk `failed_jobs`).
  - Exception lain → dilempar ulang; `failed(Throwable)` mengirim notifikasi `danger` generik `pondok.invoice.generate_failed` (bukan pesan exception).
  - `$tries = 1`, `$timeout = GenerateMonthlyInvoices::LOCK_SECONDS`, `public bool $deleteWhenMissingModels = true;` (pemicu dihapus → Job dibuang diam-diam). `failed()` aman bila user `null` (tidak mengirim apa pun).
  - Tanpa `ShouldBeUnique` (sengaja): klik ganda → dua Job diantrekan, Job kedua menghasilkan notifikasi `already_running` atau hitungan "sudah ada"; generator idempotent.

## UI (Filament / Blade)
- `InvoiceResource::generateAction()` / `generateAnnualAction()`: tetap `requiresConfirmation`, `authorize(can('generate'))`, field sama. Isi `action`: jalankan prasyarat lewat `runShowingFormErrors` (error tampil di field `period` / `academic_year_id` seperti sekarang), lalu `dispatch(GenerateInvoicesJob::...)`, lalu toast `success` `pondok.invoice.generate_queued` (menyebut periode/tahun) dengan body `generate_queued_body` ("Diproses di latar belakang. Hasilnya dikirim ke notifikasi (ikon lonceng) setelah selesai, biasanya 1–2 menit. Muat ulang halaman untuk melihatnya."). Penanganan `RuntimeException` di aksi dihapus (pindah ke Job).
- Teks baru di `lang/id/pondok.php`: `invoice.generate_queued`, `invoice.generate_queued_body`, `invoice.generate_failed`, `payment.proofs_pruned` (output command). design-guide §7 (Gagal/Berhasil), §9 bahasa.

## Permission & cakupan
Tidak ada permission baru. Dispatch dicek `Invoice.generate` (sudah ada). Job berjalan tanpa sesi; generator sudah `withoutUnitScope` (sama dengan command terjadwal). Pembersih memakai `withoutGlobalScopes()` agar bukti di jenjang mana pun dianggap direferensikan.

## Kriteria penerimaan (bisa dites)
- **AC-1** Setelah migrate, `Schema::getIndexes('invoices')` memuat indeks kolom `[status, due_on]` dan `[period, status]`; indeks lama (`[status, period]`, kedua unique) tetap ada; indeks `students` tidak bertambah.
- **AC-2** Dengan data tagihan yang ada: `down()` migration ini menghapus kedua indeks saja, `up()` lagi memasangnya; jumlah dan isi baris `invoices` tidak berubah.
- **AC-3** Bendahara, `Queue::fake()`, `callAction('generate', ['period' => '2026-10-01'])` → `GenerateInvoicesJob` di-push tepat sekali (user = bendahara, periode 2026-10), belum ada tagihan dibuat, toast `success` berisi `generate_queued` dengan "Oktober 2026".
- **AC-4** Sama untuk `generateAnnual` (tahun ajaran dipilih) → Job tahunan di-push sekali, toast menyebut nama tahun ajaran.
- **AC-5** Prasyarat tetap sinkron dan tidak men-dispatch: periode di luar opsi/tanpa tahun ajaran → form error `period`; bulan tagihan tahunan belum diatur atau di luar tahun ajaran → form error `academic_year_id`; `Queue::assertNothingPushed()`.
- **AC-6** Peran tanpa permission `generate` (mis. `admin-konten`, `petugas-pos`) tidak melihat/tidak bisa memanggil kedua aksi; tidak ada Job di-push.
- **AC-7** Menjalankan Job bulanan (antrean `sync`) → tagihan dibuat persis seperti `GenerateMonthlyInvoices` (`generated_by` = pemicu), pemicu menerima 1 notifikasi database (`$user->notifications`) berstatus success berjudul `pondok.invoice.generated` dengan hitungan benar; bila ada yang dilewati, body memuat `generated_skipped_hint`. User lain tidak menerima notifikasi.
- **AC-8** Menjalankan Job tahunan → tagihan tahunan dibuat dan notifikasi success menyebut nama tahun ajaran.
- **AC-9** Lock `keuangan:generate-invoices:<Y-m>` sedang dipegang → Job tidak membuat tagihan, pemicu menerima notifikasi database `danger` berisi `pondok.invoice.errors.already_running`, Job tidak melempar exception (tidak ada baris `failed_jobs`). Hal sama untuk `RuntimeException` `number_conflict`.
- **AC-10** `failed(new RuntimeException('detail internal'))` → pemicu menerima notifikasi `danger` berisi `generate_failed` dan tidak memuat "detail internal". Job: `tries === 1`, `timeout >= LOCK_SECONDS`, implements `ShouldQueue`, dan `config('queue.connections.database.retry_after')` > `timeout` Job.
- **AC-11** `Storage::fake('private')`: berkas `payment-proofs/...` berumur > 24 jam tanpa Payment → dihapus; berkas dirujuk Payment `diterima` dan Payment `batal` → tetap ada (meski > 24 jam); berkas tanpa Payment berumur < 24 jam → tetap ada. `handle()` mengembalikan jumlah yang dihapus dan menulis `Log::info` dengan jumlah itu.
- **AC-12** Berkas tua tanpa referensi di luar `payment-proofs/` (mis. `documents/...`) tidak disentuh; direktori `payment-proofs` tidak ada → hasil 0 tanpa error; berkas titik tua (`payment-proofs/.gitkeep`) tidak dihapus; Payment milik jenjang lain tetap terhitung walau ada user login dengan cakupan `unit_user` terbatas.
- **AC-13** `php artisan keuangan:prune-payment-proofs` menjalankan Action, mencetak `payment.proofs_pruned` dengan jumlah, exit 0. `Schedule` memuat command ini harian (zona `Asia/Jakarta`, `withoutOverlapping`); event `queue:work --stop-when-empty` tetap ada.

## Rencana test
- AC-1, AC-2 → `tests/Feature/Database/InvoiceReportIndexesTest.php` (`adds status-due_on and period-status indexes`, `rolls back only the new indexes without touching rows`).
- AC-3–AC-6 → `tests/Feature/Filament/Resources/Invoices/InvoiceGenerateQueueTest.php`.
- AC-7–AC-10 → `tests/Feature/Jobs/Keuangan/GenerateInvoicesJobTest.php`.
- AC-11, AC-12 → `tests/Feature/Actions/Keuangan/PruneOrphanPaymentProofsTest.php` (umur berkas via `touch(Storage::disk('private')->path($p), $time)` atau `travelTo`).
- AC-13 → `tests/Feature/Console/PrunePaymentProofsCommandTest.php`.
- Test lama yang membaca toast hasil generate (`InvoiceResourceTest.php` ±102/134, `InvoiceAnnualGenerateTest.php` ±41/80) disesuaikan red-tester agar membaca notifikasi database; assert form error yang ada (±120, AC-14 tahunan, `InvoiceVerifyTest` ±55) harus tetap lulus tanpa diubah.

## Urutan implementasi
Paket 1 — data + Job (hijau: `InvoiceReportIndexesTest`, `GenerateInvoicesJobTest`)
1. Migration indeks; `retry_after` database 1900 di `config/queue.php`.
2. `academicYearFor` public; ekstrak `GenerateAnnualInvoices::billingPeriod()`.
3. `GenerateInvoicesJob` + kunci lang `generate_failed`.

Paket 2 — Filament (hijau: `InvoiceGenerateQueueTest`, `InvoiceResourceTest`, `InvoiceAnnualGenerateTest`, `InvoiceVerifyTest`)
4. Kedua aksi: prasyarat → dispatch → toast `generate_queued`; kunci lang.

Paket 3 — pembersih (hijau: `PruneOrphanPaymentProofsTest`, `PrunePaymentProofsCommandTest`, `SchedulerHeartbeatTest`)
5. Action `PruneOrphanPaymentProofs`.
6. Command `PrunePaymentProofsCommand` + jadwal harian di `routes/console.php` (02:30 Asia/Jakarta) + kunci lang.
7. Pint, `docs/erd.md` catatan indeks invoices bila daftar indeks dicantumkan.

## Risiko & pertanyaan terbuka
- **Butuh keputusan user**: ekspor CSV tetap sinkron dan indeks `students(entered_on)` tidak dipasang (alasan di "Tidak termasuk"). Bila disetujui, body issue diperbarui.
- `databaseNotificationsPolling(null)`: hasil baru terlihat setelah halaman dimuat ulang; diterangkan di body toast. Tidak mengubah polling (beban shared hosting).
- `retry_after` dinaikkan ke 1900 agar Job panjang tidak dianggap kedaluwarsa. Bila proses worker dibunuh host di tengah jalan, Job baru dianggap kedaluwarsa setelah ±32 menit lalu ditandai gagal (`tries=1`) dan pemicu mendapat `generate_failed`; bendahara mengulang manual (generator idempotent untuk tagihan yang sudah dibuat).
- Antrean hanya diproses bila cron `schedule:run` aktif (sudah disyaratkan instalasi, dicek `pondok:doctor`). Tanpa cron, Job menunggu di tabel `jobs` dan toast "diproses di latar belakang" tidak diikuti hasil.
- Notifikasi database Filament sendiri `ShouldQueue`: di produksi dikirim pada putaran worker yang sama; di test (`sync`) langsung. Test yang memakai `Queue::fake()` tidak bisa sekaligus memeriksa isi notifikasi.
- Folder baru `app/Jobs` (standar `make:job`), pemakai pertama Job di proyek.
- Worktree ini belum punya `vendor/`; orkestrator perlu `composer install` atau symlink sebelum test dijalankan.

## Riwayat revisi
- DRAFT 1 (21 Okt 2026): rencana awal.
- Revisi 1 (21 Okt 2026): menindaklanjuti Review #1; status APPROVED atas persetujuan orkestrator.

## Review #1
Verdict plan-reviewer: CHANGES_REQUESTED (0 blocker, 1 major, 6 minor).
1. Major — `$timeout` 1800 vs `retry_after` 90 → `retry_after` default dinaikkan ke 1900 di `config/queue.php`, assert di AC-10, klaim "aman" di Risiko dihapus.
2. Minor — `deleteWhenMissingModels = true` dan `failed()` aman bila user null → ditambahkan di bagian Action class.
3. Minor — klik ganda tanpa `ShouldBeUnique` → dicatat di bagian Action class.
4. Minor — antrean butuh cron `schedule:run` → di Risiko; body toast menyebut "Diproses di latar belakang".
5. Minor — abaikan berkas titik dan lanjut bila satu delete gagal → di Action class dan AC-12.
6–7. Minor — tanpa tindakan (AC sejenis boleh digabung saat menulis test).
