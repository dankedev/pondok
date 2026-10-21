# F051 inti-penyempurnaan-aman — Penyempurnaan risiko rendah (audit ISO 25010/23026 Grup 1)

Issue: #18
Jalur: cepat (tanpa migration, uang, PSB, hak akses; hanya ikon dan dokumen).

## Temuan awal (cek `main` 34c1ea3)
- Poin 1 (Pint di CI): sudah ada, langkah "Periksa gaya kode (Pint)" `vendor/bin/pint --test` di `.github/workflows/tests.yml` sebelum test. `pint --test` lolos di seluruh repo, jadi tidak perlu commit `style`.
- Poin 2 dan 4 audit (breadcrumb + alt sampul): sudah dikerjakan (`x-portal.breadcrumb` di berita, galeri, halaman statis, info PSB; `alt` = judul di `post-card` dan `album-card`) dan dites di `tests/Feature/Http/Portal/PortalBreadcrumbTest.php`.
- Poin 3 (ikon): tiga ikon yang disebut audit (`InvoiceResource`, `PaymentResource`, `FinanceReport`) sudah sesuai, tetapi masih ada ikon yang memakai ikon milik konsep lain di tabel §3, dan beberapa ikon belum tercatat di tabel.
- Pemecahan `lang/id` per domain: tidak dikerjakan, menunggu keputusan user.

## Perubahan
Ikon yang memakai ikon konsep lain (satu konsep satu ikon):

| Tempat | Sekarang (milik) | Menjadi |
|---|---|---|
| `Applicants/RelationManagers/DocumentsRelationManager` aksi tandai valid | `CheckCircle` (Terima) | `CheckBadge` |
| idem, empty state | `Document` | `DocumentText` |
| `InvoiceResource::recordPaymentAction` | `Banknotes` | `ReceiptPercent` |
| `InvoiceResource` empty state, `FinanceReport` kosong tagihan | `DocumentText` (Dokumen) | `Banknotes` |
| `ApplicantResource` aksi buka kembali (ditolak → diajukan) | `ArrowPath` (Pulihkan) | `ArrowUturnLeft` |
| `DocumentResource::replaceAction` | `ArrowPath` (Pulihkan) | `ArrowUpTray` |
| `FeeOverrideResource::endAction`, `RoomAssignmentResource::vacateAction` | `ArrowRightStartOnRectangle` (Perizinan) | `StopCircle` |
| `UserResource` navigasi | `Users` (Angkatan) | `UserCircle` |
| `Settings/Identity` navigasi | `Identification` (Staff) | `BuildingOffice2` |
| `AdmissionTrackResource` navigasi | `ArrowsRightLeft` (Pindah) | `Map` |
| `IssuedLetterResource` navigasi + empty state | `Envelope` (Email) | `EnvelopeOpen` |
| `ClassroomResource` navigasi | `BuildingLibrary` (sama dengan Jenjang) | `RectangleGroup` |

`docs/design-guide.md` §3: tambah baris untuk konsep baru di atas dan ikon yang sudah dipakai tetapi belum tercatat (jenjang, rombel, periode, pengguna, identitas lembaga, jalur PSB, surat, akhiri, ganti berkas, tambah, lihat, ubah, cetak, hapus, tautkan, kategori, halaman statis, beranda situs, kaki situs, penomoran, beasiswa/subsidi, buat dari prestasi, naik kelas, salin, buka, token akses).

## Kriteria penerimaan
- AC-1: `vendor/bin/pint --test` lolos di seluruh repo dan CI menjalankannya sebelum test (sudah ada, diverifikasi).
- AC-2: Breadcrumb dan `alt` sampul kartu tetap lulus di `PortalBreadcrumbTest` (sudah ada, diverifikasi).
- AC-3: Tidak ada ikon Filament yang memakai ikon milik konsep lain di tabel §3; semua ikon Filament dan Blade tercatat di tabel.
- AC-4: Semua ikon tetap `Heroicon::Outlined…` / `x-heroicon-o-…`.
- AC-5: Test resource/halaman yang terdampak tetap lulus; tidak ada test baru (hanya ikon berubah, tanpa perilaku baru).
