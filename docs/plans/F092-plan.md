# F092 Palet perintah admin (Ctrl+K)

Issue: #111 · Jalur: cepat · Modul: inti · Lanjutan F090/F091

## Tujuan
Ganti dropdown pencarian global di header dengan palet perintah modal. Komponen kustom karena Filament tidak punya palet perintah; keputusan user.

## Perubahan
- `App\Livewire\Admin\CommandPalette` + `resources/views/filament/command-palette*.blade.php` (Livewire + Alpine, tanpa dependensi baru).
- `AdminPanelProvider`: `globalSearch(false)`; hook `USER_MENU_BEFORE` (tombol Cari) dan `BODY_END` (palet), hanya untuk pengguna login.
- Provider F090/F091 tetap menjadi sumber item (permission dan saklar modul); kata kunci kosong menampilkan semua aksi dan menu, hasil data mulai 2 huruf.
- `lang/id/pondok.php`: `pondok.command_palette.*`.

## Kriteria penerimaan
- Ctrl+K / Cmd+K atau tombol Cari membuka palet; Esc atau klik latar menutup.
- Sebelum mengetik tampil Aksi dan Menu; mengetik menyaring, hasil data (santri dsb.) ikut mulai 2 huruf.
- Panah atas/bawah memindah fokus antar hasil, Enter membuka.
- Item di luar permission atau modul mati tidak tampil.
- Palet tidak dirender di halaman login.

## Batasan
Tanpa ikon per item dan pintasan per item (YAGNI). Tampilan belum diverifikasi visual di sesi ini.
