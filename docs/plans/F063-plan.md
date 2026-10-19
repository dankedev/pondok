# F063 dokumen-tanda-tangan-surat — Tanda tangan surat 1–3 orang per jenis surat

Issue: #32
Status rencana: APPROVED
Jalur: penuh (Action yang mengubah data surat, dokumen pribadi, pengaturan).

## Ringkasan & tujuan
Tiap jenis surat (keterangan_aktif, pindah, rekomendasi, syahadah, kartu_santri) punya 1–3 slot tanda tangan di pengaturan: label + sumber (staff tertentu ATAU wali kelas santri dari rombel aktif). Saat terbit slot terisi otomatis, bisa diganti per surat, lalu nama, jabatan, dan label dibekukan di snapshot. Tanda tangan = nama + jabatan tercetak (ttd basah); keaslian tetap lewat QR.

## Ruang lingkup
- Termasuk: setting `letters.signers`, Action `ResolveLetterSigners` dan `UpdateLetterSigners`, perubahan `IssueLetter`, snapshot `signers`, cetak surat/kartu/halaman verifikasi, modal pengaturan di `IssuedLetterResource`, `lang/id`, `docs/erd.md`.
- Tidak termasuk: editor template atau jenis surat baru, gambar ttd/stempel, persetujuan digital, permission baru, perubahan siapa yang boleh menerbitkan, migrasi/pengisian ulang surat lama, kode perbaikan data demo, urutan slot yang bisa diubah per surat (urutan = urutan di pengaturan).

## Model data
- **Tanpa migration.** Pengaturan paling sederhana (pola F062: `Setting::get/set`, tanpa tabel baru): satu key `letters.signers`, JSON `{ "<type>": [ {"label": "Wali Kelas", "source": "homeroom"}, {"label": "Mengetahui, Mudir", "source": "staff", "staff_id": 3} ] }`. Jenis yang tidak ada di setting memakai bawaan satu slot `{"label":"Penandatangan","source":"staff","staff_id":null}`. Dipilih manual saat terbit; label bawaan "Penandatangan" berbeda dari tampilan lama (jabatan sebagai label), diterima.
- Snapshot baru: `snapshot.signers` = list `[{label, name, position, nip}]` berurutan (1–3). Disimpan di JSON `issued_letters.snapshot` yang sudah ada.
- **Kolom `issued_letters.signed_by_staff_id` dipertahankan** (NOT NULL, FK `restrictOnDelete`) dan kini berisi staff slot pertama (penandatangan utama). Alasan: tanpa migration, surat lama tetap valid, `Staff::signedLetters` tetap menahan penghapusan staff penandatangan utama. Slot 2–3 hanya ada di snapshot (beku, tidak butuh FK).
- **Kompatibilitas snapshot lama** (data nyata): surat lama hanya punya `snapshot.signer` (tanpa `signers`). `IssuedLetter::signerList()` (accessor baru) mengembalikan `snapshot.signers` bila ada; bila tidak, satu entri `{label: null, ...snapshot.signer}`; bila keduanya tidak ada, list kosong. Semua tampilan (cetak, kartu, verifikasi, dan tiga pembaca `snapshot['signer']` di `IssuedLetterResource`, sekitar baris 130, 287, 465) memakai accessor ini; `grep -rn "snapshot.*signer" app resources` kosong di luar accessor. Snapshot lama tidak ditulis ulang. Untuk surat baru `snapshot.signer` tidak lagi ditulis.
- Surat tetap tidak diubah/dihapus; pembatalan lewat status `batal` (tidak berubah).

## Action class
- `app/Actions/Dokumen/ResolveLetterSigners` (baru): `handle(string $type, Student $student): list<array{label, source, staff_id: ?int}>`. Baca setting (atau bawaan). Slot `staff`: staff_id bila staff itu ada dan aktif, selain itu `null`. Slot `homeroom`: `student->currentEnrollment->classroom->homeroomStaff` bila aktif, selain itu `null`. Tidak menulis apa pun; dipakai `IssueLetter` dan form penerbitan (satu sumber aturan).
- `app/Actions/Dokumen/UpdateLetterSigners` (baru): `handle(array $signers, User $actor): void`; `$signers` = peta jenis → list slot. Validasi: tiap jenis di `IssuedLetter::TYPES` dengan 1–3 slot; label wajib (trim, maks. 60); source `staff` atau `homeroom`; source `staff` wajib staff aktif; staff yang sama di dua slot satu jenis ditolak (`ValidationException`, kunci `signers.<type>.<i>...`); jenis tak dikenal ditolak. Simpan `Setting::set('letters.signers', ...)` dalam transaksi. Nilai sama dengan setting sekarang: tidak menulis, tidak melog. Beda: `activity('dokumen')`, event `signers_updated`, `causedBy($actor)`, properti `old` dan `new` (isi setting, hanya label/source/staff_id).
- `app/Actions/Dokumen/IssueLetter` (diubah): input `signed_by_staff_id` dihapus; input baru opsional `signers` = list `staff_id` (?int) override per surat, dipetakan per indeks ke slot hasil `ResolveLetterSigners`. Jumlah harus sama dengan jumlah slot, selain itu `ValidationException`. Label SELALU dari slot, bukan input (tidak ada validasi label di `IssueLetter`). Tanpa `signers` → staff dari `ResolveLetterSigners`. Entri override menggantikan staff slot (termasuk slot homeroom yang tak terselesaikan); tiap staff final harus aktif, tidak boleh sama di dua slot, dan slot tanpa staff final → `ValidationException` pesan `signer_required`. Kunci error `signers.<i>.staff_id`. `signed_by_staff_id` = staff slot pertama. Snapshot `signers` dibangun dari staff terkini saat itu. Activity `issued` ditambah properti `signers` (list nama + label). Retry/nomor/kode tidak berubah.
- `CancelIssuedLetter`: tidak berubah (log `cancelled` sudah ada); hanya dites ulang.

## UI (Filament / Blade)
Baca `docs/design-guide.md` (cetak/dokumen, form, bahasa) dan jalankan `/anti-ui-slop` sebelum UI.
- `IssuedLetterResource` aksi terbit: Select `signed_by_staff_id` diganti `Repeater` `signers` (hanya `staff_id` per slot) (min 1, maks 3, tanpa tambah/hapus/urut ulang; jumlah slot mengikuti pengaturan jenis). Diisi ulang saat `type` atau santri berubah (`afterStateUpdated`) dari `ResolveLetterSigners`: label dikunci (placeholder/teks), Select staff aktif per slot (searchable, wajib). Slot wali kelas tak terselesaikan tampil kosong + helper "Wali kelas belum dapat ditentukan, pilih manual". Submit meneruskan `signers` ke Action.
- Header action baru "Penandatangan Surat" (di samping Penomoran Surat), memakai gate `configureNumbering` yang sama (tanpa permission baru). Modal: satu `Repeater` per jenis (min 1, maks 3; Select source; Select staff muncul bila source staff; label). Simpan lewat `UpdateLetterSigners`; `ValidationException` ditampilkan seperti aksi penomoran.
- Kolom/detail resource: penandatangan = nama-nama dari `signerList()` digabung (tanpa query staff).
- `letter.blade.php`: blok `.signature` menjadi baris 1–3 kolom (flex, CSS yang ada + satu kelas kolom); tiap kolom: label, ruang ttd, nama tebal, jabatan, NIP bila ada; tempat/tanggal sekali di atas. Entri lama (label null) tampil seperti tampilan sekarang (jabatan sebagai label). `student-card.blade.php`: daftar nama penandatangan. `verify.blade.php`: daftar "label: nama, jabatan" berurutan.
- `lang/id/pondok.php`: `letter_signers.*` (judul, label, sumber staff/homeroom, bantuan, error), `dokumen.errors.signer_required`, `signers_invalid`; tidak ada teks hard-coded.

## Permission & cakupan
Tanpa permission baru. Penerbitan tetap `create` IssuedLetter; pengaturan memakai `configureNumbering` (peran yang sama dengan penomoran). Cakupan `unit_user` tetap lewat global scope `Student` (wali kelas dibaca dari rombel santri, tanpa scope tambahan). Modul dokumen: saklar `modules.dokumen.enabled` mengikuti resource.

## Kriteria penerimaan (bisa dites)
- AC-1: `UpdateLetterSigners` dengan 1–3 slot per jenis (staff aktif atau homeroom) menyimpan setting dan menulis tepat satu activity `dokumen` `signers_updated` (causer, `old`, `new`); nilai sama → tanpa tulis/log.
- AC-2: Validasi pengaturan: 0 atau 4 slot, label kosong/>60, source tak dikenal, staff nonaktif/tidak ada pada slot staff, staff sama di dua slot, jenis tak dikenal → `ValidationException`, setting dan log tidak berubah.
- AC-3: `ResolveLetterSigners`: slot staff memberi staff itu (null bila nonaktif/hilang); slot homeroom memberi wali kelas rombel aktif santri; santri tanpa rombel aktif atau rombel tanpa wali kelas atau wali kelas nonaktif → `staff_id` null; jenis tanpa setting → satu slot bawaan "Penandatangan" dengan `staff_id` null.
- AC-4: `IssueLetter` tanpa `signers` dengan setting dua slot (homeroom + staff) menerbitkan surat dengan `snapshot.signers` dua entri berurutan (label, name, position, nip dari staff), `signed_by_staff_id` = staff slot 1, nomor/kode seperti biasa.
- AC-5: `IssueLetter` dengan `signers` override mengganti staff per slot; slot yang tak terselesaikan tanpa override → `ValidationException` (`signer_required`), tidak ada surat/nomor terpakai; override slot 1 homeroom tak terselesaikan → `signed_by_staff_id` = staff override; override dengan staff nonaktif, staff sama di dua slot, jumlah kurang atau lebih dari jumlah slot → `ValidationException` (kunci `signers.<i>.staff_id` untuk staff bermasalah), tanpa surat/nomor terpakai.
- AC-6: Beku: setelah terbit, ubah setting slot, ubah nama/jabatan staff, ganti wali kelas rombel → `snapshot.signers` dan hasil cetak surat lama tidak berubah; surat baru memakai yang terbaru.
- AC-7: Surat lama (snapshot hanya `signer`, `signed_by_staff_id` terisi) tetap tampil benar di cetak, kartu, halaman verifikasi, dan kolom/detail resource (satu penandatangan, tanpa error); `signerList()` mengembalikan list kosong; grep `snapshot.*signer` di app/resources kosong di luar accessor bila snapshot tanpa keduanya.
- AC-8: Cetak surat dengan 3 slot menampilkan label, nama, jabatan semua penandatangan sesuai urutan slot; kartu santri memuat semua nama; halaman verifikasi memuat semua penandatangan berurutan dan tetap tanpa data pribadi lain (nama santri termasker).
- AC-9: Activity: `issued` memuat `letter_no`, `type`, dan `signers` (label+nama); `cancelled` tetap tercatat dengan alasan (surat multi-penandatangan); snapshot tidak masuk log (hanya nama/label penandatangan).
- AC-10: Aksi terbit di panel: memilih jenis dan santri mengisi Repeater `signers` sesuai pengaturan (wali kelas terisi otomatis); slot tak terselesaikan kosong dan wajib dipilih; mengganti staff satu slot lalu submit memakai pilihan itu; surat tercetak dengan penandatangan yang dipilih.
- AC-11: Modal "Penandatangan Surat": peran dengan `configureNumbering` bisa membuka, mengubah, dan menyimpan (setting berubah, log tercatat); peran tanpa izin tidak melihat aksi dan pemanggilan paksa ditolak; pengaturan tersimpan terisi ulang saat dibuka.
- AC-12: Hak akses penerbitan tidak berubah: peran tanpa `create` IssuedLetter tetap tidak bisa menerbitkan; santri di luar cakupan `unit_user` tetap `student_not_found`.

## Rencana test
- `tests/Feature/Actions/Dokumen/UpdateLetterSignersTest.php`: AC-1, AC-2.
- `tests/Feature/Actions/Dokumen/ResolveLetterSignersTest.php`: AC-3.
- `tests/Feature/Actions/Dokumen/IssueLetterTest.php` (diubah: ganti `signed_by_staff_id` jadi `signers`/setting; tambah): AC-4, AC-5, AC-6, AC-9, AC-12 (sisi Action).
- `tests/Feature/Http/Dokumen/IssuedLetterPrintTest.php` (tambah) dan test verifikasi yang ada: AC-7, AC-8.
- `tests/Feature/Filament/Resources/IssuedLetters/IssuedLetterResourceTest.php` (tambah): AC-10, AC-11, AC-12.
- Update helper `suratSigner`/`tests/Pest.php` bila pembuat surat memakai `signed_by_staff_id` langsung (kolom tetap ada, jadi umumnya tidak perlu berubah); `DemoRecords` memanggil `IssueLetter` dengan `signers` eksplisit (mudir), lalu `DemoSeederTest` tetap hijau.

## Urutan implementasi
Paket 1 (data + Action): `IssuedLetter::signerList()`, `ResolveLetterSigners`, `UpdateLetterSigners`, ubah `IssueLetter`, `DemoRecords`, `lang/id` error, `docs/erd.md`. Hijau: `UpdateLetterSignersTest`, `ResolveLetterSignersTest`, `IssueLetterTest`, `DemoSeederTest`.
Paket 2 (cetak + verifikasi): `letter`, `student-card`, `verify` blade, kolom/detail resource memakai `signerList()`. Hijau: `IssuedLetterPrintTest`, test verifikasi. Cek cetak A4/A5 di 360px dan desktop.
Paket 3 (Filament): Repeater di aksi terbit, modal pengaturan, `lang/id`. Hijau: `IssuedLetterResourceTest`. Pint; suite penuh sekali di akhir oleh sesi utama.

## Perubahan docs/erd.md
Tabel `issued_letters`: `signed_by_staff_id` → komentar "staff, restrict; penandatangan utama (slot 1); slot 2–3 hanya di snapshot", `snapshot` → "santri, `signers[]` {label,name,position,nip}, profil lembaga; surat lama punya `signer` tunggal". Bagian Surat keluar: tambah butir setting `letters.signers` (JSON per jenis, 1–3 slot staff/homeroom, bawaan satu slot manual), log `signers_updated`, dan aturan kompatibilitas snapshot lama.

## Risiko & pertanyaan terbuka
- Keputusan user 1 (rekomendasi: setuju): pengaturan slot memakai gate `configureNumbering` yang sama (tanpa permission baru, sesuai "permission penerbit tidak berubah").
- Keputusan user 2 (rekomendasi: setuju): jenis tanpa pengaturan bawaan satu slot manual "Penandatangan"; admin harus mengatur sekali untuk otomatisasi wali kelas.
- Keputusan user 3 (rekomendasi: setuju): `signed_by_staff_id` dipertahankan sebagai staff slot 1 (tanpa migration), bukan dihapus/dibuat nullable.
- Kartu santri (kecil) mencetak daftar nama saja, tanpa jabatan; bila perlu jabatan, tangani di issue lanjutan.
- Risiko: input `signed_by_staff_id` dihapus dari `IssueLetter`, sehingga test/seeder lama yang memanggilnya harus disesuaikan (tercantum di Rencana test). Body issue #32 sudah sesuai; tidak perlu pembaruan.

## Catatan implementasi
- Eager load `currentEnrollment.classroom.homeroomStaff` saat meresolusi slot homeroom (hindari N+1).
- Label di form terbit hanya tampilan dari slot; tidak dikirim ke Action.

## Review #1 (plan-reviewer, ringkas)
0 blocker, 1 major, 5 minor. Verdict CHANGES_REQUESTED.
1. (major) Override per surat ambigu: kontrak `[{label, staff_id}]` tak jelas pemetaannya ke slot dan label bisa menyimpang dari pengaturan.
2. Label bawaan "Penandatangan" tidak sama dengan tampilan lama; klaim "sama seperti sekarang" salah.
3. `snapshot['signer']` dibaca di tiga tempat `IssuedLetterResource` (~130, ~287, ~465); harus disebut.
4. Nama accessor `signers()` membingungkan dengan setting/input; ganti.
5. Celah: (a) override slot homeroom tak terselesaikan untuk slot 1, (b) staff sama di dua slot, (c) N+1 wali kelas.
6. Kunci error `ValidationException` pada override belum ditetapkan.

## Riwayat revisi
- Revisi 1 (final): semua temuan diterapkan. Override = list `staff_id` per indeks slot, label dari slot (1); label bawaan diterima (2); tiga pembaca snapshot disebut + AC-7 grep (3); accessor `signerList()` (4); AC-5 diperluas, duplikat staff ditolak di pengaturan dan override, catatan eager load (5); kunci `signers.<i>.staff_id` (6). Status APPROVED.
