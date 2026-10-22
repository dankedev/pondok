# F083 inti-hapus-jejak-lembaga — Hapus jejak lembaga pengguna pertama

Issue: #84 · Jalur cepat (dokumen, data demo, aset; tanpa migration dan tanpa perubahan uang/PSB/hak akses).

## Tujuan

Pondok adalah proyek open source yang generik. Repo masih memuat nama, logo, brosur, dan dokumen milik lembaga pengguna pertama. Semuanya dihapus atau diganti nama fiktif agar repo tidak lagi menyerupai aplikasi milik satu lembaga.

## Perubahan

- Hapus aset dan dokumen milik lembaga di `docs/`: logo (`docs/brand/logo-*`, salinan di `docs/`, dua gambar `IMG_*.PNG`), salinan brosur PSB, daftar pertanyaan untuk lembaga, dan brief awal.
- Data demo (`database/seeders/Demo/*`, `DemoSeeder`): lembaga fiktif "Pondok Pesantren Contoh", yayasan, alamat, tagline, email, tautan sosial, dan password bawaan fiktif. Demo tidak memuat logo; portal menampilkan nama dan admin bisa mengunggah logo sendiri.
- Test: fixture nama lembaga diganti nama fiktif ("Ma'had Al-Kitab" agar kode surat tetap `MAK` dan escape tanda petik tetap teruji). Pemeriksaan isi brosur di `DemoSeederTest` dihapus bersama brosurnya, karena memuat nama orang dan nomor asli.
- Dokumen (`CLAUDE.md`, `docs/prd.md`, `docs/design-guide.md`, `docs/ERD Pondok.html`, `docs/demo.md`, `docs/brand/referensi-gaya-coursex.md`, rencana, audit, `feature_list.json`): nama lembaga diganti nama fiktif atau "lembaga pengguna"; nomor telepon, rekening, dan nama orang dari brosur di rencana F034 disensor.
- Komentar warna di `resources/css/app.css` tanpa nama lembaga.

## Kriteria penerimaan

1. Pencarian case-insensitive nama lembaga (semua varian ejaan) di nama berkas dan isi berkas yang di-track git tidak menemukan apa pun.
2. `prd.md`, `erd.md`, dan `design-guide.md` tetap ada; hanya isinya yang dinetralkan.
3. `DemoSeeder` tetap berjalan tanpa logo; `DemoSeederTest` memeriksa demo tanpa logo.
4. Test terdampak dan suite penuh lulus; Pint.

## Di luar ruang lingkup

- Riwayat git publik tetap memuat berkas lama. Menghapusnya butuh penulisan ulang riwayat dan force push, yang hanya boleh diputuskan user.
- Geografi data demo (nama desa/kecamatan untuk tempat lahir dan alamat santri fiktif) dibiarkan karena bukan identitas lembaga.
