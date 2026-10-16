# Pertanyaan untuk Ma'had Al-Khair Belitang

Daftar keputusan yang perlu dijawab pihak pondok sebelum aplikasi Pondok dipakai penuh. Setiap pertanyaan menyertakan nilai yang dipakai aplikasi sekarang, jadi bila jawabannya "pakai yang sekarang saja", tidak ada yang perlu diubah.

Sumber: PRD §7, catatan di `feature_list.json`, dan usulan dari mockup portal wali.

## A. Perlu dijawab sebelum dipakai (Fase 1)

### 1. Format nomor induk santri (NIS)

- **Sekarang:** `{YYYY}{NNNN}`, misalnya `20260001` (tahun masuk + nomor urut 4 digit).
- **Bisa diatur** lewat setting `students.nis_format` dengan token `{YYYY}`, `{YY}`, `{UNIT}` (kode jenjang), dan nomor urut `{NNN}`–`{NNNNNN}` di akhir.
- **Pertanyaan:** format NIS apa yang dipakai pondok saat ini? Apakah nomor urut berlanjut lintas jenjang atau dimulai ulang per jenjang? Apakah santri lama tetap memakai NIS lamanya?

### 2. Format nomor kuitansi

- **Sekarang:** `KW-{tahun bulan}-NNNNN`, misalnya `KW-202610-00001`, dengan nomor urut per bulan.
- **Pertanyaan:** apakah ada format kuitansi yang sudah berlaku di bendahara? Apakah kuitansi dibedakan per jenjang atau per jenis biaya?

### 3. Format nomor tagihan

- **Sekarang:** `INV-{tahun bulan}-NNNNN`, misalnya `INV-202610-00123`.
- **Pertanyaan:** apakah format ini cukup, atau perlu mengikuti format yang sudah dipakai?

### 4. Format nomor surat keluar

- **Sekarang:** `{nomor}/{kode lembaga}/{kode jenis}/{bulan romawi}/{tahun}`, misalnya `045/MAK/SKA/X/2026`. Kode lembaga bawaan masih `PONDOK`.
- **Pertanyaan:**
  - Apa kode resmi lembaga (misalnya `MAK`)?
  - Apa kode tiap jenis surat (keterangan aktif, pindah, rekomendasi, syahadah, kartu santri)?
  - Apakah nomor urut dimulai ulang tiap tahun?
  - Siapa yang menandatangani tiap jenis surat?

### 5. Syarat pembayaran untuk daftar ulang PSB

- **Sekarang:** minimal 50% dari tagihan saat diterima (setting `psb.min_paid_percent`).
- **Pertanyaan:** berapa persen minimal yang harus dibayar sebelum pendaftar resmi menjadi santri? Apakah harus lunas?

### 6. Tagihan digabung atau dipisah

- **Pilihan:** SPP, asrama, dan makan bisa digabung dalam satu tagihan bulanan, atau dipisah per kelompok.
- **Pertanyaan:** bagaimana tagihan biasanya diberikan ke wali, satu lembar untuk semua atau terpisah? Bila ada cicilan, biaya mana yang dilunasi lebih dulu (misalnya makan, lalu asrama, lalu SPP)?

### 7. Masa simpan data surat (UU PDP)

- **Sekarang:** salinan data santri di surat keluar (nama, NISN, tempat/tanggal lahir) disimpan tanpa batas waktu, untuk verifikasi QR.
- **Pertanyaan:** berapa lama data itu perlu disimpan? Bagaimana bila santri atau wali meminta datanya dihapus?

### 8. Identitas lembaga untuk aplikasi dan cetakan

- **Yang dibutuhkan:**
  - logo resmi (file berkualitas baik);
  - nama resmi lengkap dan penulisan Arabnya;
  - alamat, telepon, email, dan media sosial;
  - warna resmi lembaga, bila ada;
  - beberapa foto asli pondok (gedung, masjid, kegiatan) untuk portal.

## B. Menentukan Fase 2

### 9. Pembayaran online

- **Pertanyaan:** bank apa yang dipakai pondok? Apakah ingin virtual account (wali transfer ke nomor unik dan otomatis tercatat)? Bila ya, lewat penyedia mana (Midtrans, Xendit, Tripay, atau langsung bank syariah)? Siapa yang menanggung biaya per transaksi?

### 10. Notifikasi WhatsApp

- **Pilihan:**
  - **WhatsApp resmi (Business API):** aman dari blokir, berbayar per percakapan.
  - **Gateway tidak resmi:** murah, tapi nomor bisa diblokir WhatsApp.
- **Pertanyaan:** mana yang dipilih? Pesan apa saja yang perlu dikirim (tagihan baru, pembayaran diterima, izin disetujui, santri keluar/kembali)?

### 11. Portal wali (dari mockup)

- **Pertanyaan:**
  - **Cara masuk:** nomor HP + kata sandi, atau kode OTP lewat WhatsApp?
  - **Pengajuan izin:** apakah wali boleh mengajukan izin pulang dari portal, atau hanya melihat?
  - **Unggah dokumen:** apakah wali boleh mengunggah dokumen santri (KK, akta), lalu diverifikasi admin?
  - **Beberapa anak:** apakah wali yang punya beberapa anak di pondok cukup memakai satu akun?
  - **Sambangan:** apakah jadwal sambangan perlu ditampilkan?

## C. Menentukan Fase 3

### 12. Tahfidz

- **Pertanyaan:** mushaf standar apa yang dipakai untuk menghitung halaman (bawaan: 15 baris, Madinah)? Berapa target hafalan per jenjang dan per tingkat?
