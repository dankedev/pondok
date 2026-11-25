# F116 Tautan Panduan untuk semua modul admin

Issue: #170 · Jalur cepat · Lanjutan F115

## Tujuan
Tautan "Panduan" di header halaman admin semua modul, sesuai daftar panduan pondok.org/panduan/.

## Perubahan
- `config/pondok.guides.pages`: kunci Resource berlaku untuk semua halamannya; entri halaman eksplisit didahulukan.
- `AdminPanelProvider::guidePages()` memperluas Resource ke halaman index/create/edit.

## Kriteria penerimaan
1. Halaman tiap modul menampilkan tautan ke slug yang sesuai.
2. Entri halaman eksplisit menang atas entri Resource (edit pengguna).
3. Slug polos tanpa anchor.

## Di luar lingkup
Info PSB, impor data, keamanan akun, panduan wali (tidak ada halaman admin yang cocok).
