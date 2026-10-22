# F080 Perbaikan tampilan hasil cek visual F023/F055/F078/F079

Issue: #79

Jalur cepat: teks `lang/id`, Blade portal wali, susunan kolom Filament. Tanpa migration, uang, tagihan, PSB, atau hak akses.

## Tujuan

Merapikan bug kecil yang ditemukan saat cek tampilan (360px, 1024px, 1440px; mode terang) atas UI yang merge tanpa cek visual: F055 (#74), F078 (#73), F023 (#77), F079 (#78).

## Perubahan

- `lang/id/pondok.php`: nilai `tahfidz.fields` diawali huruf kapital (label kolom, filter, form Setoran/Ujian; tetap dipakai sebagai nama atribut validasi yang memakai `:Attribute`); kunci baru `tahfidz.save`, `portal_wali.forgot_sent`.
- `MemorizationRecordResource`, `TahfidzExamResource`, `MembersRelationManager`: `modalSubmitActionLabel` "Simpan" menggantikan bawaan "Kirim".
- `FinanceOverviewWidget`: kolom `['@md' => 2, '@4xl' => 4, '!@md' => 2, '!@xl' => 4]` agar nominal tidak terpotong di 1024px.
- `UserForm`: "Cakupan jenjang" satu kolom (kartu sempit memotong kata).
- `portal-wali/invoice.blade.php`: rincian item 2 kolom di mobile, nominal `whitespace-nowrap`.
- `portal-wali/partials/student-summary`, `portal-wali/student`: teks `sr-only` nama santri/dokumen pada "Lihat santri" dan "Unduh".
- `Livewire\PortalWali\ForgotPassword`: pesan `portal_wali.forgot_sent` (sama untuk semua kasus, tanpa "surel"/"kata sandi"/tanda seru).
- `lang/id/auth.php`, `lang/id/validation.php`: "Silahkan" → "Silakan".

## Kriteria penerimaan

1. Header kolom dan label form Setoran/Ujian diawali huruf kapital; pesan validasi tetap "Halaqah wajib diisi."
2. Modal Catat setoran, Catat ujian, dan Tambah anggota bertombol "Simpan".
3. Dasbor 1024px: nominal kartu keuangan tidak terpotong; 1440px tetap 4 kolom.
4. Form pengguna 1024px: nama jenjang tidak terpotong per suku kata.
5. Portal wali 360px: "Rp 10.000.000" tidak terpecah.
6. Pesan lupa password portal sama untuk email terdaftar dan tidak.
7. Tidak ada "Silahkan" di `lang/id`.
8. Test terdampak dan suite penuh lulus; Pint.

## Di luar ruang lingkup

- Email akun portal wali baru memakai teks reset password dan kedaluwarsa 60 menit: issue #80 (`ditunda`).
- Pintu masuk login wali (tautan "Masuk" header ke `/admin/login`): menunggu keputusan user.
- Teks sr-only vendor Filament yang belum diterjemahkan ("Select an option.", "Yes/No").
