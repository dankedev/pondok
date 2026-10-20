# F041 keuangan-tagihan-tahunan — Generator tagihan tahunan

Issue: #16
Status rencana: APPROVED (setelah revisi #1)

## Ringkasan & tujuan
Jenis biaya `frequency = tahunan` (mis. Daftar Ulang) ditagih sekali per santri per tahun ajaran, pada bulan yang dipilih admin. Logikanya sama dengan generator bulanan (tarif, keringanan, mukim/nonmukim, gabungan/terpisah, log santri tanpa tarif), dijalankan otomatis oleh scheduler dan manual lewat command serta tombol panel. Keputusan user 21 Okt 2026 (bulan global, period, manual, santri) diikuti apa adanya.

## Ruang lingkup
- Termasuk: setting `keuangan.annual_billing_month` di halaman FinanceNumbering; Action `GenerateAnnualInvoices`; parameter frekuensi di inti `GenerateMonthlyInvoices`; command `keuangan:generate-annual-invoices`; jadwal; tombol "Buat tagihan tahunan" di daftar Tagihan; teks `lang/id`; setting bulan di data demo.
- Tidak termasuk: bulan per jenis biaya atau per jenjang; tagihan tahunan untuk pendaftar PSB; prorata santri yang masuk di tengah tahun; mengganti judul halaman FinanceNumbering; migration; perubahan generator bulanan di luar parameter frekuensi.

## Model data
Tanpa migration. Setting baru `keuangan.annual_billing_month` (tabel `settings`), integer 1–12. **Default kosong = tidak terjadwal.** Alasannya: instalasi yang sudah berjalan bisa punya jenis biaya tahunan beserta tarif. Default otomatis (mis. bulan mulai tahun ajaran) akan langsung membuat tagihan pada tanggal 1 berikutnya tanpa keputusan bendahara. Selama kosong, scheduler tidak membuat apa pun, dan jalur manual menolak dengan pesan untuk mengatur bulannya dulu. Nilai tersimpan yang tidak valid dianggap kosong.
Tagihan memakai kolom yang ada: `period` = tanggal 1 bulan setting di dalam tahun ajaran, `academic_year_id` = tahun ajaran yang dipilih, `due_on` = `keuangan.due_day` di bulan itu (sama dengan bulanan). Unique (`student_id`, `period`, `billing_group`, `active_marker`) tetap berlaku.

## Action class
**Pilihan desain (DRY tanpa base class baru):** inti generator bulanan diberi satu parameter frekuensi, dan `GenerateAnnualInvoices` adalah Action tipis yang memanggilnya. Base class abstrak tidak dipilih karena signature `handle()` kedua Action berbeda dan karena itu memindahkan ±400 baris. Kode yang disalin juga tidak dipilih.

1. `app/Actions/Keuangan/GenerateMonthlyInvoices` (diubah):
   - Method publik baru `generateFor(CarbonImmutable $period, AcademicYear $academicYear, string $frequency, ?User $generatedBy): array`. `handle()` tetap menjadi `academicYearFor()` + `generateFor(..., FREQUENCY_MONTHLY)`, dan perilaku bulanan tidak berubah.
   - Yang bergantung pada `$frequency`: (a) filter `fee_types.frequency`. (b) Cek "sudah ditagih". Bulanan tetap memakai item di tagihan aktif periode itu. Tahunan memakai item jenis biaya tahunan di tagihan aktif (bukan `batal`, bukan `SALDO_AWAL`) santri dengan `academic_year_id` yang sama, **periode apa pun**, sehingga mengganti bulan di tengah tahun tidak menggandakan tagihan. Target penggabungan per grup tetap tagihan aktif periode itu. (c) Label deskripsi item: bulanan memakai `F Y`, tahunan memakai `academic_years.name`. Keduanya lewat key `pondok.invoice.item_description` yang sudah ada (`:fee_type :period`), mis. "Daftar ulang tahunan 2026/2027". (d) Teks activity: `pondok.invoice.activity_generated` atau key baru `activity_generated_annual`. Properti tambahan `frequency` dan `academic_year_id` **hanya pada jalur tahunan**; properti activity bulanan tidak berubah (`GenerateMonthlyInvoicesTest` AC-34 tetap hijau tanpa diubah).
   - Tetap sama untuk kedua frekuensi: lock `keuangan:generate-invoices:Y-m` (bulanan dan tahunan pada periode yang sama saling mengunci sehingga tidak bentrok menulis tagihan grup yang sama), santri aktif dengan `entered_on` kosong atau ≤ akhir bulan tagihan, `applies_to` dari kamar aktif, `ResolveFeeRate`/`ResolveFeeOverride`/`ApplyFeeOverride` (override yang overlap dengan bulan tagihan), penomoran per periode, `missing_rate` dicatat (`Log::warning`) lalu dilewati, dan `invoice_locked` bila tagihan grup itu sudah ada pembayaran (`paid_total > 0`): item dilewati dan tercatat di `skipped`. Cara ini KISS dan konsisten dengan bulanan; risikonya ada di bawah.
   - **Query "sudah ditagih" tahunan (satu query tambahan per chunk):** `Invoice::withoutUnitScope()->whereIn('student_id', $studentIds)->where('academic_year_id', $year->id)->where('status', '<>', batal)->where('billing_group', '<>', SALDO_AWAL)->whereHas('items', fn ($q) => $q->whereIn('fee_type_id', $annualFeeTypeIds))->with(['items' => fn ($q) => $q->whereIn('fee_type_id', $annualFeeTypeIds)->select('id', 'invoice_id', 'fee_type_id')])`. Tagihan bulanan yang tidak berisi biaya tahunan tidak dimuat, dan item non-tahunan tidak ikut dimuat.
   - **Pemakaian hasilnya di `processStudent`:** tagihan periode+grup (query `$existing` yang ada) tetap menjadi `$byGroup`, yaitu tagihan tujuan penggabungan. `$billedIn[fee_type_id] = invoice_id` diisi dari item tagihan periode itu **ditambah** item hasil query tahunan di atas (periode apa pun). Jenis biaya yang ada di `$billedIn` dilewati dan `invoice_id`-nya masuk `$relevant`, sehingga tagihan Juli dihitung `existing_invoices` = 1 saat dijalankan untuk September, dan tidak ada item yang ditambahkan ke tagihan September. Untuk bulanan, query tambahan tidak dijalankan sehingga perilakunya sama persis.
2. `app/Actions/Keuangan/GenerateAnnualInvoices` (baru):
   - `const MONTH_SETTING_KEY = 'keuangan.annual_billing_month'`. `billingMonth(): ?int` membaca setting dan mengembalikan null bila kosong atau di luar 1–12.
   - `handle(AcademicYear $academicYear, ?User $generatedBy = null): array` (bentuk hasil sama dengan bulanan). Bulan null memicu `ValidationException` `academic_year_id` → `pondok.invoice.errors.annual_month_not_set`. Period dicari sebagai bulan pertama berangka = setting dalam rentang `startOfMonth(starts_on)`..`ends_on`; bila tidak ada, `annual_month_outside_year`. Hasilnya diteruskan ke `generateFor(..., FREQUENCY_YEARLY)`. `ValidationException` dari inti (`already_running`) dilempar ulang dengan key `academic_year_id` agar tampil di form panel.
3. `app/Console/Commands/GenerateAnnualInvoicesCommand` (baru): `keuangan:generate-annual-invoices {--academic-year= : nama tahun ajaran, mis. 2026/2027}`.
   - Modul `keuangan` mati: info, SUCCESS, tanpa data.
   - Tanpa opsi (mode scheduler): setting kosong → info "belum diatur", SUCCESS. Bulan berjalan (Asia/Jakarta) ≠ setting → info "bukan bulan tagihan tahunan", SUCCESS. Selain itu tahun ajaran yang memuat hari ini dipakai; bila tidak ada, error dan FAILURE.
   - Dengan opsi: nama tidak ditemukan → FAILURE. Pengecekan bulan berjalan dilewati, tetapi setting tetap wajib (lewat Action).
   - Ringkasan memakai `pondok.invoice.command.summary`. `set_time_limit(0)` seperti bulanan.
4. `routes/console.php`: `Schedule::command('keuangan:generate-annual-invoices')->monthlyOn(1, '01:00')->timezone('Asia/Jakarta')->withoutOverlapping()`, didaftarkan **setelah** command bulanan. `schedule:run` menjalankan event jatuh tempo secara berurutan, sehingga tagihan bulanan sudah ada (belum dibayar) saat item tahunan digabung.

## UI (Filament / Blade)
- `InvoiceResource::generateAnnualAction()` (baru) dipasang di `ListInvoices::getHeaderActions()` setelah `generateAction()`. Label "Buat tagihan tahunan", `requiresConfirmation`, `authorize(static::can('generate'))` (permission `generate:invoice`, sama dengan bulanan). Form berisi `Select academic_year_id` (semua tahun ajaran, urut `starts_on` desc, default `AcademicYear::current()`, wajib). Deskripsi modal menyebut bulan setting ("Tagihan dibuat dengan periode :month."), atau peringatan bahwa bulan belum diatur di Penomoran Kuitansi & Tagihan. Bila berhasil, notifikasi `pondok.invoice.generated` (period = nama tahun ajaran) dengan body `generated_skipped_hint` bila ada yang dilewati, dengan penanganan `number_conflict` dan `runShowingFormErrors` seperti bulanan.
- `FinanceNumbering`: section baru "Tagihan tahunan" (deskripsi singkat), `Select annual_billing_month` dengan opsi 1–12 berlabel nama bulan Indonesia, `placeholder` "Tidak terjadwal", nullable, helper "Tagihan biaya tahunan dibuat otomatis tanggal 1 bulan ini. Kosongkan untuk membuatnya manual saja." `fillData` mengisi dari `billingMonth()`. `persist` memvalidasi null atau integer 1–12 (selain itu `ValidationException` field `annual_billing_month`), menyimpan atau mengosongkan setting di transaksi yang sama, dan menambahkan `annual_billing_month` ke properti `old`/`new` activity `numbering_updated`.
- Semua teks lewat `lang/id/pondok.php` (`invoice.annual_*`, `invoice.errors.annual_*`, `invoice.command.annual_*`, `numbering.finance.annual_*`). Ikuti design-guide §bahasa (kalimat, tanpa istilah teknis) dan checklist §0. Panggil `/anti-ui-slop` saat implementasi UI.

## Permission & cakupan
Tanpa permission baru. Tombol memakai `generate:invoice`, dan setting memakai `view:finance-numbering` (bendahara, super-admin). Generator tanpa cakupan jenjang (`withoutUnitScope`, sama dengan bulanan). Saklar `modules.keuangan.enabled` dicek di command, sedangkan panel (resource dan page) sudah tergating modul.

## Kriteria penerimaan (bisa dites)
- **AC-1** Setting 7, tahun ajaran 2026/2027 (Jul 2026–Jun 2027), santri aktif, jenis biaya tahunan grup `DAFTAR_ULANG` dengan tarif → `GenerateAnnualInvoices::handle($year)` membuat 1 tagihan `period` 2026-07-01, `academic_year_id` tahun itu, `due_on` hari `due_day` Juli, item `base/discount/amount` snapshot dan deskripsi "<nama biaya> 2026/2027". Jenis biaya bulanan/manual/PSB tidak ikut.
- **AC-2** Idempotent: menjalankan dua kali → jumlah tagihan dan item tidak bertambah, `existing_invoices` = 1 pada jalan kedua.
- **AC-3** Ganti bulan: setelah tagihan Juli dibuat, setting diubah ke 9 lalu dijalankan lagi → tidak ada tagihan atau item tahunan baru (periode September). Bila tagihan Juli itu `batal`, jalan berikutnya membuat tagihan baru (nomor baru) di periode September.
- **AC-4** Gabungan: jenis biaya tahunan dengan `billing_group` sama dengan SPP (`BULANAN`) dan tagihan bulanan Juli yang belum dibayar → item tahunan ditambahkan ke tagihan itu dan `total` dihitung ulang (`added_items` 1). Bila tagihan itu sudah ada pembayaran, item dilewati dengan alasan `invoice_locked`, dan tagihan tidak berubah. Grup beda → tagihan terpisah pada periode yang sama.
- **AC-5** Generator bulanan dijalankan setelah tahunan pada periode yang sama dan grup sama → SPP ditambahkan ke tagihan yang sama tanpa menggandakan item tahunan. Jalan ulang `GenerateMonthlyInvoices` tidak menambah, mengubah, atau menghapus item tahunan (`base_amount`/`amount`/`description` tetap).
- **AC-6** `applies_to`: biaya tahunan `mukim` hanya untuk santri dengan kamar aktif, `nonmukim` hanya untuk yang tanpa kamar.
- **AC-7** Santri `entered_on` setelah akhir bulan tagihan, atau tidak aktif, tidak ditagih. `entered_on` kosong atau ≤ 31 Juli ditagih.
- **AC-8** Keringanan: mode `nominal`/`potongan`/`persen` yang overlap dengan bulan tagihan diterapkan (`discount_amount` terisi). Tanpa tarif dan tanpa keringanan → tidak ada item, `skipped` berisi `missing_rate`, `Log::warning` tercatat, dan santri lain tetap diproses.
- **AC-9** Validasi Action: setting kosong atau tidak valid (0, 13, "abc") → `ValidationException` `academic_year_id` (`annual_month_not_set`). Bulan setting di luar rentang tahun ajaran (tahun ajaran Jul–Des, setting 3) → `annual_month_outside_year`. Lock periode sedang dipegang (`keuangan:generate-invoices:2026-07`) → error `academic_year_id`, tanpa data.
- **AC-10** Activity log: satu activity `keuangan`/`generated` per jalan, berisi properti `frequency = tahunan` dan `academic_year_id`, dengan causer user bila dari panel. Invoice `generated_by` terisi user panel.
- **AC-11** Command: tanpa opsi, modul mati, setting kosong, atau bulan berjalan ≠ setting → SUCCESS tanpa tagihan (`travelTo`). Bulan berjalan = setting → tagihan dibuat dan ringkasan tercetak. Tidak ada tahun ajaran yang memuat hari ini → FAILURE. `--academic-year=2026/2027` di luar bulan setting → tagihan dibuat. Nama tak dikenal → FAILURE tanpa data. `ValidationException` dari Action (`already_running`, `annual_month_not_set`) → pesan dicetak, FAILURE.
- **AC-12** Tahun ajaran baru: tagihan tahunan 2026/2027 sudah ada (aktif) → dijalankan untuk 2027/2028 (setting 7) → tagihan baru `period` 2027-07-01, `academic_year_id` 2027/2028, sehingga santri yang sama punya 2 tagihan tahunan. Tagihan 2026/2027 tidak berubah.
- **AC-13** Scheduler: event `keuangan:generate-annual-invoices` terdaftar dengan cron `0 1 1 * *`, zona Asia/Jakarta, dan berada setelah event `keuangan:generate-invoices` dalam urutan event.
- **AC-14** Panel: bendahara (punya `generate:invoice`) melihat tombol "Buat tagihan tahunan" di daftar Tagihan dan menjalankannya dengan tahun ajaran terpilih (tagihan dibuat, notifikasi sukses). Peran tanpa `generate:invoice` tidak melihat tombol. Setting kosong → error tampil di field `academic_year_id` tanpa data. Ada santri tanpa tarif → notifikasi sukses memuat `generated_skipped_hint`.
- **AC-15** FinanceNumbering: bendahara menyimpan bulan 7 → setting `7` dan activity memuat `annual_billing_month`. Mengosongkan → setting null ("Tidak terjadwal"). Nilai 13 → error validasi di `annual_billing_month` dan setting tidak berubah. Form terisi dari setting yang tersimpan.

## Rencana test
- `tests/Feature/Actions/Keuangan/GenerateAnnualInvoicesTest.php`: AC-1 – AC-10 dan AC-12 (dataset untuk mode override, nilai setting tidak valid, dan applies_to).
- `tests/Feature/Console/GenerateAnnualInvoicesCommandTest.php`: AC-11, AC-13.
- `tests/Feature/Filament/Resources/Invoices/InvoiceAnnualGenerateTest.php`: AC-14.
- `tests/Feature/Filament/Pages/FinanceAnnualBillingMonthTest.php`: AC-15.
- Regresi (tanpa mengubah test yang ada): `GenerateMonthlyInvoicesTest`, `GenerateMonthlyInvoicesRerunTest`, `GenerateMonthlyInvoicesCommandTest`, `InvoiceResourceTest`, `FinanceNumberingSignerTest`, `DocumentNumberingTest` tetap hijau.

## Urutan implementasi
Paket 1 (data + Action), hijau: `GenerateAnnualInvoicesTest` + regresi generator bulanan.
1. Parameter frekuensi + `generateFor()` di `GenerateMonthlyInvoices`, `handle()` tetap.
2. `GenerateAnnualInvoices` + teks error/activity di `lang/id`.

Paket 2 (command + jadwal), hijau: `GenerateAnnualInvoicesCommandTest` + `GenerateMonthlyInvoicesCommandTest`.
3. Command + teks command. 4. Jadwal di `routes/console.php`.

Paket 3 (panel), hijau: `InvoiceAnnualGenerateTest`, `FinanceAnnualBillingMonthTest`, `InvoiceResourceTest`, `FinanceNumberingSignerTest`.
5. Section bulan di `FinanceNumbering` + teks. 6. `generateAnnualAction()` + `ListInvoices`. 7. Data demo: `DemoFinance` mengisi `keuangan.annual_billing_month` = bulan mulai tahun ajaran demo, hanya bila belum ada (biaya `DAFTAR_ULANG` dan tarifnya sudah ada di `DemoData`). 8. ERD: satu butir aturan bisnis "Tagihan tahunan" di bagian keuangan.

## Risiko & pertanyaan terbuka
- `invoice_locked`: bila biaya tahunan digabung ke grup bulanan dan SPP periode itu sudah dicicil sebelum generator tahunan jalan (mis. dijalankan manual di pertengahan bulan), item tahunan dilewati dan hanya tercatat di hasil/log. Tidak bisa dibuat tagihan terpisah karena unique per grup. Mitigasinya adalah urutan scheduler (tahunan berjalan di tanggal 1 setelah bulanan) dan saran di helper/ERD agar biaya tahunan memakai grup sendiri. Tidak ada perubahan perilaku tambahan (KISS).
- Jadwal hanya sekali (tanggal 1). Bila gagal (lock dipegang, tidak ada tahun ajaran), tidak ada percobaan ulang otomatis; bendahara menjalankan tombol manual. Sama dengan bulanan.
- Item tahunan di tagihan `SALDO_AWAL` tidak dihitung sebagai sudah ditagih (konsisten dengan bulanan). Saldo awal dimaksudkan untuk tunggakan lama.
- Lock per periode, bukan per tahun ajaran: bila bulan setting diganti saat generator tahunan sedang berjalan, dua jalan untuk tahun yang sama bisa memegang lock periode berbeda dan sama-sama menambah item. Kemungkinannya kecil (butuh dua jalan bersamaan dan perubahan setting di tengahnya), jadi tidak ditambah lock kedua (KISS). Bila terjadi, bendahara membatalkan tagihan ganda dengan `CancelInvoice`.
- Pertanyaan (tidak memblokir): judul halaman "Penomoran Kuitansi & Tagihan" kini juga memuat jadwal tagihan tahunan. Usulan: biarkan dulu; ganti judul bisa jadi issue terpisah bila user mau.

## Riwayat revisi
- DRAFT 1 (21 Okt 2026): rencana awal.
- Revisi #1 (21 Okt 2026): menindaklanjuti Review #1 (tabel di bawah).

## Review #1 — 2026-10-21
Verdict: CHANGES_REQUESTED (0 blocker, 2 major, 3 minor).

| # | Tingkat | Temuan | Tindak lanjut |
|---|---|---|---|
| 1 | major | Tidak ada AC untuk tahun ajaran berikutnya; regresi bulanan atas item tahunan kurang tegas | AC-12 baru (2027/2028 membuat tagihan baru); AC-12 lama digabung ke AC-11; AC-5 menegaskan generator bulanan tidak mengubah item tahunan |
| 2 | major | Query "sudah ditagih" tahunan belum rinci | Bagian Action 1: query dengan `whereHas`/eager-load item berfilter `fee_type_id` tahunan; penjelasan `$billedIn`/`$relevant`/`$byGroup` |
| 3 | minor | Command harus menangkap `ValidationException` | Masuk AC-11 |
| 4 | minor | Properti activity tambahan hanya untuk jalur tahunan | Ditegaskan di Action 1 (d); test bulanan tidak diubah |
| 5 | minor | Risiko lock beda periode; hint skip di notifikasi panel | Dicatat di Risiko; `generated_skipped_hint` di UI dan AC-14 |
