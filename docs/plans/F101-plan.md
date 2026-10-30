# F101 Dukungan R2/S3 untuk disk private dan public

Issue: #131. Jalur penuh.

## Tujuan
Lembaga dengan disk server kecil dapat menyimpan berkas di Cloudflare R2/S3 tanpa mengubah kode. Opsional; default tetap lokal.

## Desain (KISS)
- `config/filesystems.php`: disk `private` dan `public` dibangun sebagai `s3` bila `PRIVATE_DISK_DRIVER`/`PUBLIC_DISK_DRIVER=s3`; kunci/endpoint/path-style dipakai bersama. Bucket terpisah: `AWS_BUCKET` (privat), `AWS_PUBLIC_BUCKET` + `AWS_PUBLIC_URL` (publik). `visibility` tidak diisi (R2 tanpa ACL).
- Kode tidak berubah: sudah lewat nama disk; unduhan memakai `download()`/`response()` yang mengalir dari S3.
- `CreateBackup`: menolak dengan pesan jelas bila disk `private` bukan lokal (arsip memakai path lokal).
- `CheckSystemHealth` dan `docker/entrypoint.sh`: cek volume dilewati hanya bila kedua disk s3; cek public/private lokal dilewati untuk s3.
- Dependensi `league/flysystem-aws-s3-v3` (disetujui user) menambah syarat `ext-simplexml`; masuk daftar ekstensi doctor dan docs/instalasi.md.

## Kriteria penerimaan
1. Paket ada di composer.json.
2. Config default lokal; env s3 menghasilkan driver s3, bucket terpisah, endpoint, url publik.
3. Disk private s3 tanpa `visibility`/url publik.
4. Doctor: persistent_storage ok hanya bila kedua disk s3; public_storage/private_disk ok untuk s3.
5. Backup menolak bila private non-lokal.
6. Dokumen: panduan R2 (bucket, token, endpoint, CORS, migrasi berkas lama, backup).
7. Test unduhan/penghapusan memakai disk fake (tidak berubah), suite penuh lulus.
