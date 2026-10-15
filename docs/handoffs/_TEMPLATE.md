# Handoff F000 — <nama fitur>

Salin file ini menjadi `docs/handoffs/<ID>-<YYYYMMDD>-<HHMM>.md`, contoh `F003-20261015-1830.md`.

- **Fitur:** F000 `<key>` (lihat `feature_list.json`)
- **Dari:** <agent/sesi asal>
- **Ke:** <agent/sesi tujuan, atau "siapa saja">
- **Tanggal:** YYYY-MM-DD HH:MM
- **Branch / commit terakhir:** `<branch>` @ `<hash>`
- **Ada perubahan belum di-commit?** ya/tidak (sebutkan file)

## Tujuan

Apa yang harus tercapai agar fitur ini `done` (salin `acceptance` dari `feature_list.json` dan tambahkan bila perlu).

## Sudah selesai

- [x] ...

## Belum selesai

- [ ] ...

## Bukti verifikasi

| Cek | Perintah | Hasil |
|---|---|---|
| Test | `php artisan test --compact tests/Feature/...` | lulus / gagal (sebutkan) |
| Pint | `vendor/bin/pint --dirty --format agent` | |

## File yang diubah

- `app/...`

## Keputusan yang diambil

- ... (beserta alasannya)

## Blocker / risiko

- ...

## Langkah pertama untuk agent penerima

1. Baca `CLAUDE.md`, entri fitur ini di `feature_list.json`, dan handoff ini.
2. Cek `git status` dan `git log --oneline -5` cocok dengan kondisi di atas.
3. Jalankan test di tabel bukti verifikasi sebelum mengubah kode.
4. Ubah `owner` di `feature_list.json` ke nama agent penerima dan status ke `in-progress`.
5. Lanjutkan dari: ...
