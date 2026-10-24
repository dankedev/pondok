# F088 inti-avatar-lokal — Avatar lokal panel admin tanpa ui-avatars.com

Issue: #101
Status rencana: jalur cepat (tanpa review rencana)
Jalur: cepat. Tanpa migration, tanpa perubahan data, uang, PSB, atau hak akses. Lanjutan temuan K6 di F086.

## Tujuan
Avatar bawaan Filament (`UiAvatarsProvider`) memuat gambar dari `https://ui-avatars.com`, sehingga inisial nama setiap
pengguna admin terkirim ke pihak ketiga dan tercatat sebagai pelanggaran `img-src` di CSP report-only F086. Avatar diganti
dengan inisial yang dibuat di server, tanpa permintaan jaringan keluar.

## Keputusan
- **K1 Penyedia avatar sendiri** `App\Filament\AvatarProviders\InitialsAvatarProvider` (kontrak
  `Filament\AvatarProviders\Contracts\AvatarProvider`), didaftarkan lewat `->defaultAvatarProvider()` di panel admin.
  Semua tempat yang memakai avatar Filament (menu pengguna, widget Akun) ikut berubah.
- **K2 SVG data URI** (`data:image/svg+xml;base64,...`): lolos `img-src 'self' data: blob:`, tanpa route, file, atau cache
  baru (KISS). Tanpa dependensi baru.
- **K3 Inisial**: huruf pertama dari maksimal dua kata, spasi berlebih dirapikan, tanda baca di awal kata dilewati
  (sama seperti bawaan Filament), huruf besar dengan `mb_*` agar aman untuk nama non-ASCII. Nama tanpa huruf/angka → `?`.
  Teks di-escape sebelum masuk SVG.
- **K4 Warna** sama dengan bawaan Filament: latar `gray-950` semantik panel (`FilamentColor::getColor('gray')`), teks
  putih. Panel admin mengikuti Filament (design guide §2); kontras jauh di atas 4.5:1. Avatar tetap gelap di tema gelap,
  seperti sebelumnya.

## File yang diubah
- `app/Filament/AvatarProviders/InitialsAvatarProvider.php` — baru.
- `app/Providers/Filament/AdminPanelProvider.php` — daftarkan penyedia avatar.
- `tests/Feature/Filament/AdminAvatarTest.php` — baru.
- `feature_list.json` — entri F088.

## Kriteria penerimaan
1. HTML dashboard admin tidak memuat `ui-avatars.com`; URL avatar pengguna berupa `data:image/svg+xml`.
2. Inisial benar untuk dua kata, tiga kata, satu kata, nama non-ASCII (Latin beraksen, Arab), tanda baca di awal, dan
   spasi berlebih; nama tanpa huruf menghasilkan `?`.
3. SVG tidak memuat markup dari nama dan kontras teks terhadap latar minimal 4.5:1.
4. Menu pengguna tampil benar di 360px, 1024px, dan desktop, tema terang dan gelap.
5. Console browser tidak lagi mencatat pelanggaran CSP `img-src` untuk avatar.
