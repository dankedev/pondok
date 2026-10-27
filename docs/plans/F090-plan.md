# F090 Navigasi admin: grup terlipat dan pencarian menu

Issue: #107 · Jalur: cepat · Modul: inti

## Tujuan
Menu admin makin panjang (±60 resource dan page), terutama untuk super-admin. Kurangi panjang daftar tanpa mengubah struktur menu.

## Perubahan
1. `AdminPanelProvider`: `navigationGroups` memakai `NavigationGroup::make($label)->collapsed()`; urutan tetap dari `pondok.navigation`.
2. `app/Filament/GlobalSearch/NavigationAwareGlobalSearchProvider.php`: membungkus `DefaultGlobalSearchProvider`; menambah kategori "Menu" di atas hasil data, berisi item dari `Filament::getNavigation()` (sudah disaring permission dan modul) yang labelnya cocok dengan kata kunci. Dipasang lewat `->globalSearch(...)`.
3. `->globalSearchKeyBindings(['ctrl+k', 'command+k'])`.
4. `lang/id/pondok.php`: label kategori `pondok.navigation_search.category`.
5. Opsi 5 (matikan modul yang tidak dipakai) adalah tindakan konfigurasi di Pengaturan > Modul, tanpa kode.

## Kriteria penerimaan
- Grup terlipat secara default; grup dengan halaman aktif terbuka.
- Pencarian "bayar" oleh bendahara memuat menu terkait; item di luar permission atau modul mati tidak muncul.
- Ctrl+K dan Cmd+K membuka pencarian.
- Hasil data (mis. santri) tetap muncul seperti sebelumnya.
- Kata kunci kosong atau terlalu pendek tidak memuat kategori Menu.

## Pengujian
Satu file test untuk provider (izin per peran, modul mati, kata kunci). Cek tampilan 360px, 1024px, desktop.
