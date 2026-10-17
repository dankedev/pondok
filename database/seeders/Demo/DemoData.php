<?php

namespace Database\Seeders\Demo;

use App\Models\StaticPage;

/**
 * Data kurasi demo Ma'had Al-Khair Belitang (F034, revisi brosur PSB):
 * khusus putra, jenjang MTW/SMP (kode SLTP) dan TSN/SMA (kode SLTA).
 * Nama lembaga, alamat, tagline, program, dan nominal biaya mengikuti
 * brosur; semua nama orang, nomor telepon, dan email fiktif. Tidak ada
 * tahun absolut: tahun masuk santri disimpan sebagai offset terhadap tahun
 * ajaran aktif (lihat `DemoCalendar`).
 */
final class DemoData
{
    public const SCHOOL = [
        'name' => "Ma'had Al-Khair Belitang",
        'tagline' => "Generasi Beradab Bersama Al-Qur'an",
        'address' => 'Jl. Raya Belitang–Martapura, Mojosari, Belitang, OKU Timur, Sumatera Selatan',
        'phone' => '0735 000 0170',
        'whatsapp' => '0812 0000 0170',
        'email' => 'info@alkhair-belitang.test',
    ];

    /**
     * Profil lembaga demo (F036): contoh isian admin, bukan teks bawaan aplikasi.
     * Nomor dan tautan fiktif; nomor asli brosur tidak dipakai.
     */
    public const INSTITUTION = [
        'short_name' => 'Al-Khair',
        'arabic_name' => 'معهد الخير',
        'contacts' => [
            ['label' => 'Panitia PSB', 'name' => null, 'phone' => '081200000171', 'is_whatsapp' => true],
            ['label' => 'Sekretariat', 'name' => null, 'phone' => '081200000170', 'is_whatsapp' => true],
        ],
        'social_links' => [
            ['platform' => 'instagram', 'label' => null, 'url' => 'https://instagram.contoh.test/alkhair.belitang'],
            ['platform' => 'youtube', 'label' => null, 'url' => 'https://youtube.contoh.test/@alkhair.belitang'],
            ['platform' => 'telegram', 'label' => null, 'url' => 'https://telegram.contoh.test/alkhair.belitang'],
        ],
        'home' => [
            'hero_greeting' => 'Bismillahirrahmanirrahim',
            'hero_title' => "Ahlan wa Sahlan di Ma'had Al-Khair Belitang",
            'hero_subtitle' => "Tahfidzul Qur'an, Bahasa Arab, dan Studi Islam Intensif untuk putra jenjang MTW/SMP dan TSN/SMA.",
            'highlights_heading' => "Mengapa memilih Ma'had Al-Khair",
            'highlights' => [
                ['title' => 'Kurikulum terintegrasi', 'description' => "Ilmu syar'i, tahfizh Al-Qur'an, bahasa Arab, pendidikan umum, dan pembentukan karakter dalam Jurnal Adab dan Akhlak."],
                ['title' => 'Pengajar berkompeten', 'description' => 'Ustaz berlatar pendidikan Islam dari dalam dan luar negeri.'],
                ['title' => "Al-Qur'an & matan bersanad", 'description' => 'Pembinaan tahsin dan tahfizh serta hafalan matan-matan ilmiah bersanad.'],
                ['title' => 'Proyeksi studi lanjut', 'description' => 'Ke perguruan tinggi Islam dalam dan luar negeri.'],
                ['title' => 'CCTV full area', 'description' => 'Keamanan asrama dan lingkungan belajar dipantau sepanjang hari.'],
            ],
        ],
        'hero_album_slug' => 'fasilitas-mahad',
        'footer' => [
            'footer_quote_arabic' => 'وَلْتَكُن مِّنكُمْ أُمَّةٌ يَدْعُونَ إِلَى الْخَيْرِ وَيَأْمُرُونَ بِالْمَعْرُوفِ وَيَنْهَوْنَ عَنِ الْمُنكَرِ ۚ وَأُولَٰئِكَ هُمُ الْمُفْلِحُونَ',
            'footer_quote_translation' => 'Dan hendaklah di antara kamu ada segolongan orang yang menyeru kepada kebajikan, menyuruh kepada yang makruf, dan mencegah dari yang mungkar. Mereka itulah orang-orang yang beruntung.',
            'footer_quote_source' => "QS. Ali 'Imran: 104",
            'footer_description' => "Ma'had Al-Khair Belitang: pendidikan Islam berasrama khusus putra dengan program tahfidz, bahasa Arab, dan studi Islam intensif.",
            'footer_links' => [
                ['label' => 'Berita', 'url' => '/berita'],
                ['label' => 'Galeri', 'url' => '/galeri'],
            ],
        ],
        /** Logo merek dari `docs/brand/` (dilewati bila berkas tidak ada). */
        'logos' => [
            'logo' => 'docs/brand/logo-mahad-simbol.png',
            'logo_desktop' => 'docs/brand/logo-mahad-horizontal.png',
            'logo_mobile' => 'docs/brand/logo-mahad-simbol.png',
            'favicon' => 'docs/brand/logo-mahad-simbol.png',
        ],
    ];

    /** Nama jenjang demo; diganti hanya bila nama unit masih bawaan `UnitSeeder`. */
    public const UNIT_NAMES = ['SLTP' => 'MTW/SMP', 'SLTA' => 'TSN/SMA'];

    /**
     * Istilah perayaan dan amalan yang tidak dipakai di konten demo (manhaj
     * pondok: hanya Idul Fitri dan Idul Adha). Dicari tanpa membedakan huruf besar.
     */
    public const FORBIDDEN_CONTENT_TERMS = [
        'maulid', 'isra mi', "isra' mi", 'haul', 'nisfu', 'tahun baru', 'hadrah', 'shalawatan', 'sholawatan', 'tahlilan',
    ];

    public const PLACES = [
        'Belitang', 'Mojosari', 'Gumawang', 'Belitang II', 'Belitang III', 'Martapura', 'Buay Madang', 'Semendawai Suku III',
    ];

    public const VILLAGES = [
        'Desa Mojosari, Kec. Belitang', 'Desa Gumawang, Kec. Belitang', 'Desa Sidogede, Kec. Belitang', 'Desa Tegal Rejo, Kec. Belitang II',
        'Desa Karang Kemiri, Kec. Belitang II', 'Desa Sukarami, Kec. Belitang III', 'Desa Bangsa Negara, Kec. Belitang III', 'Desa Pujorahayu, Kec. Buay Madang',
        'Desa Tanjung Kemala, Kec. Martapura', 'Desa Sumber Jaya, Kec. Semendawai Suku III',
    ];

    public const OCCUPATIONS = ['Petani', 'Pedagang', 'Wiraswasta', 'Guru', 'Pegawai swasta', 'Buruh tani', 'Peternak', 'Sopir', 'Penjahit', 'Perangkat desa'];

    /** Nama depan dan belakang ayah; digabung berpasangan (13 x 7 kombinasi unik). */
    private const FATHER_FIRST = ['Ahmad', 'Muhammad', 'Abdul', 'Sutrisno', 'Slamet', 'Hasan', 'Mahmud', 'Sulaiman', 'Ismail', 'Ridwan', 'Sugeng', 'Zulkifli', 'Hendra'];

    private const FATHER_LAST = ['Syahputra', 'Hidayat', 'Wibowo', 'Firmansyah', 'Nurdin', 'Santoso', 'Hamdani'];

    /**
     * Keluarga dengan lebih dari satu anak, atau wali bukan ayah (santri yatim).
     * Urutan menentukan nomor HP wali (KD = 081200000004, dipakai ulang pendaftar PSB).
     *
     * @var array<string, array{name: string, relation: string, occupation: string}>
     */
    public const FAMILIES = [
        'KA' => ['name' => 'Sutarno Hadi Wijoyo', 'relation' => 'ayah', 'occupation' => 'Petani'],
        'KB' => ['name' => 'Mustofa Kamal', 'relation' => 'ayah', 'occupation' => 'Guru'],
        'KC' => ['name' => 'Bambang Suryadi', 'relation' => 'ayah', 'occupation' => 'Pedagang'],
        'KD' => ['name' => 'Joko Prasetyo', 'relation' => 'ayah', 'occupation' => 'Wiraswasta'],
        'KE' => ['name' => 'Rusdi Hartono', 'relation' => 'ayah', 'occupation' => 'Peternak'],
        'KF' => ['name' => 'Yusuf Effendi', 'relation' => 'ayah', 'occupation' => 'Pegawai swasta'],
        'KI' => ['name' => 'Siti Aminah', 'relation' => 'ibu', 'occupation' => 'Penjahit'],
    ];

    /**
     * Rombel per jenjang (sama di tahun lalu dan tahun aktif), semuanya putra.
     *
     * @var array<string, list<array{grade: int, name: string, gender: string}>>
     */
    public const CLASSROOMS = [
        'SLTP' => [
            ['grade' => 7, 'name' => '7A', 'gender' => 'L'],
            ['grade' => 8, 'name' => '8A', 'gender' => 'L'],
            ['grade' => 9, 'name' => '9A', 'gender' => 'L'],
        ],
        'SLTA' => [
            ['grade' => 10, 'name' => '10A', 'gender' => 'L'],
            ['grade' => 11, 'name' => '11A', 'gender' => 'L'],
            ['grade' => 12, 'name' => '12A', 'gender' => 'L'],
        ],
    ];

    /**
     * Staff. `homeroom` = "JENJANG:rombel" yang diwalikan; `head` = kode asrama yang dikepalai;
     * `room` = "KODE:kamar" yang didampingi musyrif; `account` = peran akun demo yang ditautkan.
     *
     * @var list<array{nip: string, name: string, gender: string, position: string, homeroom?: string, head?: string, room?: string, account?: string}>
     */
    public const STAFF = [
        ['nip' => 'MAK-2015-001', 'name' => 'Abdul Hamid Syukri', 'gender' => 'L', 'position' => 'Mudir'],
        ['nip' => 'MAK-2016-002', 'name' => 'Fathur Rohman', 'gender' => 'L', 'position' => 'ustadz', 'homeroom' => 'SLTP:7A'],
        ['nip' => 'MAK-2016-003', 'name' => 'Zainal Abidin Rasyid', 'gender' => 'L', 'position' => 'ustadz', 'homeroom' => 'SLTP:8A'],
        ['nip' => 'MAK-2017-004', 'name' => 'Hasanuddin Malik', 'gender' => 'L', 'position' => 'ustadz', 'homeroom' => 'SLTP:9A'],
        ['nip' => 'MAK-2017-005', 'name' => 'Mukhlisin Hadi', 'gender' => 'L', 'position' => 'ustadz', 'homeroom' => 'SLTA:10A'],
        ['nip' => 'MAK-2018-006', 'name' => 'Abdullah Fikri Ramadhan', 'gender' => 'L', 'position' => 'ustadz', 'homeroom' => 'SLTA:11A', 'account' => 'ustadz-tahfidz'],
        ['nip' => 'MAK-2018-007', 'name' => 'Saiful Anwar Hakim', 'gender' => 'L', 'position' => 'ustadz', 'homeroom' => 'SLTA:12A'],
        ['nip' => 'MAK-2016-008', 'name' => 'Ahmad Baihaqi', 'gender' => 'L', 'position' => 'ustadz', 'head' => 'ABK'],
        ['nip' => 'MAK-2017-009', 'name' => 'Mahfudz Arifin', 'gender' => 'L', 'position' => 'ustadz', 'head' => 'UMR'],
        ['nip' => 'MAK-2019-010', 'name' => 'Nasruddin Hakim', 'gender' => 'L', 'position' => 'ustadz'],
        ['nip' => 'MAK-2019-011', 'name' => 'Rahmat Hidayat Saleh', 'gender' => 'L', 'position' => 'musyrif', 'room' => 'ABK:Kamar 101', 'account' => 'musyrif'],
        ['nip' => 'MAK-2020-012', 'name' => 'Ahmad Junaidi', 'gender' => 'L', 'position' => 'musyrif', 'room' => 'ABK:Kamar 102'],
        ['nip' => 'MAK-2020-013', 'name' => 'Sholeh Mubarok', 'gender' => 'L', 'position' => 'musyrif', 'room' => 'ABK:Kamar 103'],
        ['nip' => 'MAK-2021-014', 'name' => 'Faizal Amri', 'gender' => 'L', 'position' => 'musyrif', 'room' => 'ABK:Kamar 104'],
        ['nip' => 'MAK-2021-015', 'name' => 'Hilman Fauzi', 'gender' => 'L', 'position' => 'musyrif', 'room' => 'UMR:Kamar 201'],
        ['nip' => 'MAK-2022-016', 'name' => 'Syarif Maulana', 'gender' => 'L', 'position' => 'musyrif', 'room' => 'UMR:Kamar 202'],
        ['nip' => 'MAK-2022-017', 'name' => 'Irfan Mubarak', 'gender' => 'L', 'position' => 'musyrif', 'room' => 'UMR:Kamar 203'],
        ['nip' => 'MAK-2023-018', 'name' => 'Rosyad Hanafi', 'gender' => 'L', 'position' => 'musyrif', 'room' => 'UMR:Kamar 204'],
        ['nip' => 'MAK-2016-019', 'name' => 'Dedi Kurniawan', 'gender' => 'L', 'position' => 'TU', 'account' => 'bendahara'],
        ['nip' => 'MAK-2020-020', 'name' => 'Eka Puspita Sari', 'gender' => 'P', 'position' => 'TU'],
    ];

    /** Pengurus yayasan untuk halaman profil (fiktif). */
    public const BOARD = [
        'Ketua Yayasan' => 'Sulaiman Effendi',
        'Sekretaris Yayasan' => 'Muhammad Nur Ikhsan',
        'Bendahara Yayasan' => 'Dedi Kurniawan',
        'Mudir Ma\'had' => 'Abdul Hamid Syukri',
    ];

    public const FOUNDATION = 'Yayasan Islam Al-Khair Belitang';

    /**
     * Akun demo: peran => [nama, kode jenjang cakupan].
     *
     * @var array<string, array{name: string, units: list<string>}>
     */
    public const ACCOUNTS = [
        'super-admin' => ['name' => 'Admin Utama Demo', 'units' => []],
        'bendahara' => ['name' => 'Dedi Kurniawan', 'units' => []],
        'admin-sekolah' => ['name' => 'Admin Sekolah Demo', 'units' => ['SLTP']],
        'admin-psb' => ['name' => 'Panitia PSB Demo', 'units' => []],
        'admin-konten' => ['name' => 'Admin Konten Demo', 'units' => []],
        'kesantrian' => ['name' => 'Bagian Kesantrian Demo', 'units' => ['SLTA']],
        'musyrif' => ['name' => 'Rahmat Hidayat Saleh', 'units' => []],
        'ustadz-tahfidz' => ['name' => 'Abdullah Fikri Ramadhan', 'units' => []],
        'petugas-pos' => ['name' => 'Petugas Pos Demo', 'units' => []],
    ];

    /**
     * Kelompok santri menurut riwayat. `p` = [jenjang, tingkat] tahun lalu (null = santri baru),
     * `a` = [jenjang, tingkat] tahun aktif (null = tidak lanjut), `status` akhir.
     * Baris santri: [nama, L, tanggal lahir MM-DD, indeks tempat lahir, kode keluarga|null, penanda].
     * Penanda keringanan SPP: `yatim`, `tahfidz`, `wawancara`.
     */
    private const GROUPS = [
        ['p' => null, 'a' => ['SLTP', 7], 'status' => 'aktif', 'students' => [
            ['Ahmad Zaki Mubarak', 'L', '02-19', 0, null, []],
            ['Muhammad Iqbal Firdaus', 'L', '08-30', 4, null, []],
            ['Faris Al-Ghifari', 'L', '11-11', 1, null, []],
            ['Umar Faruq Ramadhan', 'L', '04-25', 7, null, []],
            ['Muhammad Rizky Ramadhan', 'L', '03-14', 0, null, []],
            ['Ahmad Fadhil Hakim', 'L', '08-02', 1, 'KD', []],
            ['Muhammad Hafiz Alfarizi', 'L', '01-30', 3, null, []],
            ['Rafi Ananda Putra', 'L', '06-17', 0, 'KE', []],
            ['Ilham Maulana Yusuf', 'L', '02-11', 0, 'KA', []],
            ['Daffa Arkan Saputra', 'L', '07-25', 5, null, []],
            ['Rayhan Akbar Pratama', 'L', '09-12', 0, null, []],
            ['Fikri Abdillah', 'L', '03-27', 1, 'KC', []],
        ]],
        ['p' => ['SLTP', 7], 'a' => ['SLTP', 8], 'status' => 'aktif', 'students' => [
            ['Abdurrahman Hakim', 'L', '01-05', 0, null, []],
            ['Muhammad Fauzan Akbar', 'L', '06-22', 0, 'KA', []],
            ['Haikal Fadhlurrahman', 'L', '09-14', 2, 'KD', []],
            ['Rizal Maulana Ishaq', 'L', '03-31', 3, null, []],
            ['Yusuf Alfian Syah', 'L', '11-20', 1, null, []],
            ['Bilal Habibullah', 'L', '05-13', 6, 'KI', ['yatim']],
            ['Arkan Naufal Hidayat', 'L', '05-04', 0, null, []],
            ['Nabil Ahsan Maulana', 'L', '02-26', 4, null, []],
            ['Zidan Alfarizqi', 'L', '08-08', 1, null, []],
            ['Hafizh Azka Ramadhan', 'L', '04-18', 2, null, ['tahfidz']],
            ['Raihan Fathurrahman', 'L', '07-29', 7, null, []],
            ['Kholid Abdul Aziz', 'L', '10-02', 0, null, []],
        ]],
        ['p' => ['SLTP', 8], 'a' => ['SLTP', 9], 'status' => 'aktif', 'students' => [
            ['Ahmad Rifa\'i', 'L', '01-23', 1, null, []],
            ['Muhammad Irfan Hakiki', 'L', '06-04', 0, null, []],
            ['Fadli Ramadhan', 'L', '09-27', 3, null, []],
            ['Taufik Hidayatullah', 'L', '03-06', 5, 'KF', []],
            ['Hamzah Alfarisi', 'L', '11-29', 0, null, []],
            ['Nabil Assidiqi', 'L', '05-18', 2, null, []],
            ['Salman Hakim Nur', 'L', '02-09', 0, null, []],
            ['Usamah Ridwan', 'L', '08-21', 1, null, []],
            ['Thoriq Abdillah', 'L', '12-01', 6, null, []],
            ['Mushab Fadhil', 'L', '04-12', 4, null, []],
            ['Hudzaifah Ramli', 'L', '07-19', 0, null, []],
            ['Zubair Hanafi', 'L', '10-24', 2, null, ['wawancara']],
        ]],
        ['p' => ['SLTP', 7], 'a' => null, 'status' => 'pindah', 'students' => [
            ['Dimas Arya Pratama', 'L', '06-13', 1, null, []],
        ]],
        ['p' => ['SLTP', 8], 'a' => null, 'status' => 'keluar', 'students' => [
            ['Rangga Wiratama', 'L', '09-09', 3, null, []],
        ]],
        ['p' => ['SLTP', 9], 'a' => ['SLTA', 10], 'status' => 'aktif', 'students' => [
            ['Muhammad Syahrul Ramadhan', 'L', '01-28', 0, null, []],
            ['Ikhsan Nur Fauzi', 'L', '07-02', 3, null, []],
            ['Fahri Hamdani', 'L', '03-17', 1, null, []],
            ['Rizki Aulia Rahman', 'L', '09-23', 4, null, []],
        ]],
        ['p' => ['SLTP', 9], 'a' => null, 'status' => 'lulus', 'students' => [
            ['Wahyu Setiawan Nugroho', 'L', '05-30', 0, null, []],
            ['Lutfi Azzam Hakim', 'L', '11-14', 2, null, []],
        ]],
        ['p' => null, 'a' => ['SLTA', 10], 'status' => 'aktif', 'students' => [
            ['Abdul Malik Firdaus', 'L', '02-04', 0, null, []],
            ['Hasbi Ramadhani', 'L', '08-16', 5, null, []],
            ['Furqon Alamsyah', 'L', '12-27', 1, null, []],
            ['Naufal Ulya Rahman', 'L', '04-08', 0, null, []],
            ['Qois Zahran', 'L', '06-29', 7, null, []],
            ['Hilmi Nafis Syauqi', 'L', '10-10', 2, null, []],
        ]],
        ['p' => ['SLTA', 10], 'a' => ['SLTA', 11], 'status' => 'aktif', 'students' => [
            ['Muhammad Alif Furqon', 'L', '01-12', 0, 'KE', []],
            ['Zaidan Ar-Rasyid', 'L', '05-26', 3, null, []],
            ['Ridho Kamaluddin', 'L', '09-03', 1, null, []],
            ['Ammar Fauzi Hakim', 'L', '03-21', 4, null, []],
            ['Habib Nurrohman', 'L', '11-07', 0, null, []],
            ['Asyraf Nadhif', 'L', '02-17', 2, null, []],
            ['Rifqi Khoirul Anam', 'L', '07-11', 0, null, []],
            ['Ulil Albab Maulana', 'L', '12-19', 6, null, []],
            ['Najmi Humaidi', 'L', '04-30', 5, 'KB', []],
            ['Syauqi Aqil', 'L', '08-25', 1, null, []],
        ]],
        ['p' => ['SLTA', 11], 'a' => ['SLTA', 12], 'status' => 'aktif', 'students' => [
            ['Ahmad Mujahid', 'L', '01-09', 0, 'KA', []],
            ['Imam Fadhilah Rahman', 'L', '06-15', 2, null, []],
            ['Luqman Hakim Saputra', 'L', '10-05', 1, null, []],
            ['Rafiq Hidayatullah', 'L', '03-24', 3, null, []],
            ['Yahya Mukhlis', 'L', '08-11', 0, null, []],
            ['Fauzi Nur Aziz', 'L', '02-28', 4, null, []],
            ['Hanif Rahmatullah', 'L', '05-08', 0, null, []],
            ['Kamil Shalih', 'L', '09-18', 1, 'KF', []],
            ['Mutawakkil Insan', 'L', '12-06', 7, null, []],
            ['Rayyan Husnaini', 'L', '07-15', 2, 'KC', []],
        ]],
        ['p' => ['SLTA', 11], 'a' => null, 'status' => 'keluar', 'students' => [
            ['Putra Ayub Wicaksono', 'L', '04-22', 0, null, []],
        ]],
        ['p' => ['SLTA', 12], 'a' => null, 'status' => 'lulus', 'students' => [
            ['Fajar Shiddiq', 'L', '03-03', 1, 'KB', []],
            ['Ikhwan Mubarok', 'L', '07-27', 0, null, []],
            ['Nurhadi Saputra', 'L', '11-24', 4, null, []],
            ['Riski Amrullah', 'L', '01-19', 2, null, []],
            ['Tsabit Zahran', 'L', '06-06', 0, null, []],
        ]],
    ];

    /** Tingkat pertama tiap jenjang dan umur saat masuk tingkat itu. */
    public const FIRST_GRADE = ['SLTP' => 7, 'SLTA' => 10];

    public const ENTRY_AGE = ['SLTP' => 12, 'SLTA' => 15];

    /**
     * Daftar santri non-PSB yang sudah diratakan, dengan offset tahun masuk relatif
     * tahun ajaran aktif (`entry_offset`) dan nomor urut per tahun masuk (`seq`).
     *
     * @return list<array{name: string, gender: string, birth_md: string, birth_place: string, family: string, flags: list<string>, p: array{0: string, 1: int}|null, a: array{0: string, 1: int}|null, status: string, origin_unit: string, entry_offset: int, seq: int}>
     */
    public static function students(): array
    {
        $rows = [];
        $sequences = [];
        $autoFamily = 0;

        foreach (self::GROUPS as $group) {
            [$originUnit, $originGrade] = $group['p'] ?? $group['a'];
            $entryOffset = -($originGrade - self::FIRST_GRADE[$originUnit]) - ($group['p'] === null ? 0 : 1);

            foreach ($group['students'] as [$name, $gender, $birthMd, $place, $family, $flags]) {
                $sequences[$entryOffset] = ($sequences[$entryOffset] ?? 0) + 1;

                $rows[] = [
                    'name' => $name,
                    'gender' => $gender,
                    'birth_md' => $birthMd,
                    'birth_place' => self::PLACES[$place],
                    'family' => $family ?? 'G'.str_pad((string) ++$autoFamily, 2, '0', STR_PAD_LEFT),
                    'flags' => $flags,
                    'p' => $group['p'],
                    'a' => $group['a'],
                    'status' => $group['status'],
                    'origin_unit' => $originUnit,
                    'entry_offset' => $entryOffset,
                    'seq' => $sequences[$entryOffset],
                ];
            }
        }

        return $rows;
    }

    /**
     * Wali per kode keluarga: keluarga kurasi, atau ayah dengan nama gabungan untuk kode `Gnn`.
     *
     * @return array{name: string, relation: string, occupation: string, phone: string, address: string}
     */
    public static function guardian(string $family): array
    {
        $keys = array_keys(self::FAMILIES);
        $index = in_array($family, $keys, true)
            ? array_search($family, $keys, true)
            : count($keys) + (int) substr($family, 1) - 1;

        $base = self::FAMILIES[$family] ?? [
            'name' => self::FATHER_FIRST[$index % count(self::FATHER_FIRST)].' '.self::FATHER_LAST[$index % count(self::FATHER_LAST)],
            'relation' => 'ayah',
            'occupation' => self::OCCUPATIONS[$index % count(self::OCCUPATIONS)],
        ];

        return [
            ...$base,
            'phone' => '08120000'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
            'address' => 'RT 0'.(($index % 5) + 1).', '.self::VILLAGES[$index % count(self::VILLAGES)].', Kab. OKU Timur, Sumatera Selatan',
        ];
    }

    /** Asrama putra: MTW/SMP di ABK, TSN/SMA di UMR. */
    public const DORMITORIES = [
        ['code' => 'ABK', 'name' => 'Asrama Abu Bakar Ash-Shiddiq', 'unit' => 'SLTP', 'location' => 'Kompleks asrama, sisi timur masjid', 'rooms' => ['Kamar 101', 'Kamar 102', 'Kamar 103', 'Kamar 104'], 'floor' => 1, 'capacity' => 10],
        ['code' => 'UMR', 'name' => 'Asrama Umar bin Khattab', 'unit' => 'SLTA', 'location' => 'Kompleks asrama, sisi barat ruang kelas', 'rooms' => ['Kamar 201', 'Kamar 202', 'Kamar 203', 'Kamar 204'], 'floor' => 2, 'capacity' => 8],
    ];

    /** @var list<array{code: string, name: string, frequency: string, applies_to: string, billing_group: string, priority: int}> */
    public const FEE_TYPES = [
        ['code' => 'SPP', 'name' => 'SPP (termasuk makan 3x sehari, laundry, operasional)', 'frequency' => 'bulanan', 'applies_to' => 'semua', 'billing_group' => 'BULANAN', 'priority' => 10],
        ['code' => 'FORMULIR', 'name' => 'Biaya pendaftaran', 'frequency' => 'saat_daftar', 'applies_to' => 'semua', 'billing_group' => 'PSB_DAFTAR', 'priority' => 10],
        ['code' => 'UANG_MASUK', 'name' => 'Uang masuk', 'frequency' => 'saat_diterima', 'applies_to' => 'semua', 'billing_group' => 'PSB_TERIMA', 'priority' => 10],
        ['code' => 'DAFTAR_ULANG', 'name' => 'Daftar ulang tahunan', 'frequency' => 'tahunan', 'applies_to' => 'semua', 'billing_group' => 'DAFTAR_ULANG', 'priority' => 20],
    ];

    /**
     * Tarif dasar (berlaku mulai tahun lalu) untuk MTW/SMP dan TSN/SMA.
     * DAFTAR_ULANG adalah nominal contoh (brosur tidak menyebut angka).
     *
     * @var array<string, int>
     */
    public const BASE_RATES = ['SPP' => 1600000, 'FORMULIR' => 300000, 'UANG_MASUK' => 10000000, 'DAFTAR_ULANG' => 1500000];

    /** Tarif SPP lama yang terkunci untuk angkatan TSN/SMA dua tahun lalu (kelas 12). */
    public const COHORT_RATE = ['fee' => 'SPP', 'unit' => 'SLTA', 'entry_offset' => -2, 'amount' => 1500000];

    public const SPONSOR = 'Donatur Yayasan Islam Al-Khair Belitang';

    /** @var array<string, array{name: string, mode: string, default_value: int}> */
    public const SCHOLARSHIPS = [
        'yatim' => ['name' => 'Subsidi Yatim', 'mode' => 'nominal', 'default_value' => 800000],
        'tahfidz' => ['name' => 'Beasiswa Tahfidz', 'mode' => 'persen', 'default_value' => 50],
        'uang_masuk' => ['name' => 'Subsidi Uang Masuk', 'mode' => 'potongan', 'default_value' => 5000000],
    ];

    /** Keringanan SPP non-beasiswa hasil wawancara biaya. */
    public const INTERVIEW_DISCOUNT = ['mode' => 'potongan', 'value' => 300000, 'reason' => 'Kesepakatan wawancara biaya'];

    /** Jalur PSB berbeasiswa yang dibuat demo. */
    public const SUBSIDY_TRACK = ['code' => 'SUBSIDI', 'name' => 'Subsidi/Beasiswa', 'scholarship' => 'uang_masuk', 'fees' => ['UANG_MASUK'],
        'terms' => 'Terbatas untuk 3 santri dengan seleksi ketat, termasuk wawancara biaya bersama orang tua/wali.'];

    /** Jalur bawaan yang tetap aktif; jalur bawaan lain dinonaktifkan. */
    public const ACTIVE_TRACKS = ['REGULER', 'SUBSIDI'];

    /** Syarat berkas semua jenjang: kode jenis dokumen => [nama, wajib]. */
    public const REQUIREMENTS = [
        'KK' => ['Kartu Keluarga', true],
        'AKTA' => ['Akta kelahiran', true],
        'RAPOR' => ['Rapor semester terakhir', true],
        'FOTO' => ['Pas foto 3x4 latar biru', true],
        'IJAZAH' => ['Ijazah terakhir (legalisir, diserahkan saat serah terima)', false],
    ];

    /**
     * Pendaftar PSB pada gelombang yang sedang dibuka. `flow` menentukan status akhir;
     * `family` = kode keluarga santri aktif yang walinya dipakai ulang.
     *
     * @var list<array{name: string, unit: string, track: string, birth_md: string, place: int, school: string, guardian: string, phone: string, flow: string, relation?: string, family?: string}>
     */
    public const APPLICANTS = [
        ['name' => 'Ghazi Rahmadhan', 'unit' => 'SLTP', 'track' => 'REGULER', 'birth_md' => '03-12', 'place' => 0, 'school' => 'SD di Kecamatan Belitang', 'guardian' => 'Suparman Hadi', 'phone' => '081300000101', 'flow' => 'draft'],
        ['name' => 'Muhammad Fathan Mubina', 'unit' => 'SLTP', 'track' => 'REGULER', 'birth_md' => '07-21', 'place' => 3, 'school' => 'SD di Kecamatan Belitang II', 'guardian' => 'Rohmat Santoso', 'phone' => '081300000102', 'flow' => 'submitted'],
        ['name' => 'Azka Ramadhani', 'unit' => 'SLTP', 'track' => 'REGULER', 'birth_md' => '10-05', 'place' => 1, 'school' => 'MI di Kecamatan Belitang', 'guardian' => 'Hendri Gunawan', 'phone' => '081300000103', 'flow' => 'submitted'],
        ['name' => 'Naufal Khairi', 'unit' => 'SLTA', 'track' => 'SUBSIDI', 'birth_md' => '02-18', 'place' => 6, 'school' => 'SMP di Kecamatan Buay Madang', 'guardian' => 'Mulyadi Sasmita', 'phone' => '081300000104', 'flow' => 'submitted_doc_pending'],
        ['name' => 'Rizqi Aditya Nugraha', 'unit' => 'SLTP', 'track' => 'REGULER', 'birth_md' => '05-09', 'place' => 4, 'school' => 'MI di Kecamatan Belitang III', 'guardian' => 'Edi Suhendra', 'phone' => '081300000105', 'flow' => 'submitted_doc_rejected'],
        ['name' => 'Aqil Zahran', 'unit' => 'SLTP', 'track' => 'REGULER', 'birth_md' => '09-30', 'place' => 5, 'school' => 'SD di Kecamatan Martapura', 'guardian' => 'Sarifuddin Lubis', 'phone' => '081300000106', 'flow' => 'verified'],
        ['name' => 'Hanan Arrasyid', 'unit' => 'SLTA', 'track' => 'REGULER', 'birth_md' => '12-14', 'place' => 0, 'school' => 'SMP di Kecamatan Belitang', 'guardian' => 'Nasrullah Jamil', 'phone' => '081300000107', 'flow' => 'verified'],
        ['name' => 'Salwan Nabil Huda', 'unit' => 'SLTA', 'track' => 'REGULER', 'birth_md' => '04-27', 'place' => 3, 'school' => 'MTs di Kecamatan Belitang II', 'guardian' => 'Maryanto Widodo', 'phone' => '081300000108', 'flow' => 'reserved'],
        ['name' => 'Fauzan Hilmi', 'unit' => 'SLTP', 'track' => 'REGULER', 'birth_md' => '08-08', 'place' => 7, 'school' => 'SD di Kecamatan Semendawai Suku III', 'guardian' => 'Taufiqurrahman', 'phone' => '081300000109', 'flow' => 'rejected'],
        ['name' => 'Muhammad Hanif Al-Hafizh', 'unit' => 'SLTP', 'track' => 'REGULER', 'birth_md' => '01-25', 'place' => 0, 'school' => 'MI di Kecamatan Belitang', 'guardian' => 'Abdul Rozak', 'phone' => '081300000110', 'flow' => 'accepted'],
        ['name' => 'Nashrullah Ramadhani', 'unit' => 'SLTA', 'track' => 'REGULER', 'birth_md' => '06-11', 'place' => 2, 'school' => 'SMP di Kecamatan Belitang', 'guardian' => 'Hariyanto Saleh', 'phone' => '081300000111', 'flow' => 'accepted_partial'],
        ['name' => 'Raihan Syakir', 'unit' => 'SLTP', 'track' => 'SUBSIDI', 'birth_md' => '11-02', 'place' => 4, 'school' => 'SD di Kecamatan Belitang III', 'guardian' => 'Sri Wahyuni', 'phone' => '081300000112', 'flow' => 'reregistered_full', 'relation' => 'ibu'],
        ['name' => 'Akbar Maulana Yusuf', 'unit' => 'SLTP', 'track' => 'REGULER', 'birth_md' => '03-29', 'place' => 0, 'school' => 'SD di Kecamatan Belitang', 'guardian' => 'Joko Prasetyo', 'phone' => '081200000004', 'flow' => 'reregistered_partial', 'family' => 'KD', 'relation' => 'ayah'],
        ['name' => 'Dzakir Amali', 'unit' => 'SLTA', 'track' => 'REGULER', 'birth_md' => '07-13', 'place' => 6, 'school' => 'MTs di Kecamatan Buay Madang', 'guardian' => 'Budiman Hasibuan', 'phone' => '081300000114', 'flow' => 'reregistered_partial', 'relation' => 'ayah'],
    ];

    /**
     * Prestasi. `offset` = hari sebelum hari ini; `participants` = [nama santri, peringkat, peran].
     *
     * @var list<array{title: string, category: string, level: string, kind: string, offset: int, verified: bool, public: bool, event: string, organizer: string, participants: list<array{0: string, 1: string, 2: string|null}>, post?: bool, certificate?: bool}>
     */
    public const ACHIEVEMENTS = [
        ['title' => "Juara 1 MTQ Cabang Hifzhil Qur'an 10 Juz", 'category' => 'tahfidz', 'level' => 'kabupaten', 'kind' => 'individu', 'offset' => 20, 'verified' => true, 'public' => true, 'post' => true, 'certificate' => true,
            'event' => "Musabaqah Tilawatil Qur'an tingkat kabupaten", 'organizer' => 'Panitia MTQ tingkat kabupaten', 'participants' => [['Hamzah Alfarisi', 'Juara 1', null]]],
        ['title' => 'Juara 2 Futsal Antar Pondok se-OKU Timur', 'category' => 'olahraga', 'level' => 'kabupaten', 'kind' => 'beregu', 'offset' => 45, 'verified' => true, 'public' => true,
            'event' => 'Turnamen futsal antarpondok se-OKU Timur', 'organizer' => 'Forum komunikasi pondok pesantren kabupaten', 'participants' => [
                ['Zaidan Ar-Rasyid', 'Juara 2', 'Ketua tim'], ['Ammar Fauzi Hakim', 'Juara 2', null], ['Habib Nurrohman', 'Juara 2', null],
                ['Imam Fadhilah Rahman', 'Juara 2', null], ['Yahya Mukhlis', 'Juara 2', null],
            ]],
        ['title' => 'Juara 3 Pidato Bahasa Arab', 'category' => 'keagamaan', 'level' => 'provinsi', 'kind' => 'individu', 'offset' => 80, 'verified' => true, 'public' => false,
            'event' => 'Lomba pidato bahasa Arab tingkat provinsi', 'organizer' => 'Panitia lomba bahasa Arab tingkat provinsi', 'participants' => [['Luqman Hakim Saputra', 'Juara 3', null]]],
        ['title' => "Juara 1 Hafalan Hadits Arba'in Beregu", 'category' => 'keagamaan', 'level' => 'kecamatan', 'kind' => 'beregu', 'offset' => 120, 'verified' => true, 'public' => true,
            'event' => "Lomba hafalan hadits Arba'in antarpondok tingkat kecamatan", 'organizer' => 'Panitia lomba keagamaan tingkat kecamatan', 'participants' => [
                ['Asyraf Nadhif', 'Juara 1', 'Ketua tim'], ['Rifqi Khoirul Anam', 'Juara 1', null], ['Syauqi Aqil', 'Juara 1', null],
                ['Fauzi Nur Aziz', 'Juara 1', null], ['Hanif Rahmatullah', 'Juara 1', null], ['Ahmad Rifa\'i', 'Juara 1', null],
            ]],
        ['title' => 'Juara 1 Cerdas Cermat Matematika', 'category' => 'akademik', 'level' => 'kecamatan', 'kind' => 'individu', 'offset' => 200, 'verified' => false, 'public' => false,
            'event' => 'Lomba cerdas cermat matematika tingkat kecamatan', 'organizer' => 'Kelompok kerja guru matematika kecamatan', 'participants' => [['Muhammad Irfan Hakiki', 'Juara 1', null]]],
        ['title' => 'Juara 1 Kaligrafi Musabaqah Internal', 'category' => 'seni', 'level' => 'pondok', 'kind' => 'individu', 'offset' => 260, 'verified' => false, 'public' => false,
            'event' => 'Musabaqah internal santri', 'organizer' => "Bagian kesantrian Ma'had Al-Khair Belitang", 'participants' => [['Abdurrahman Hakim', 'Juara 1', null]]],
    ];

    /** Kategori berita sesuai brief. */
    public const POST_CATEGORIES = ['Kegiatan Al-Khair', 'Prestasi Al-Khair', 'Info Kajian', 'Akidah & Manhaj', 'Tafsir & Hadits', 'Fiqh', 'Umum'];

    public const ACHIEVEMENT_CATEGORY = 'Prestasi Al-Khair';

    /**
     * Berita. `{S}` = tahun ajaran tujuan PSB. `offset` = hari sebelum hari ini (null = draft).
     *
     * @var list<array{slug: string, title: string, type: string, category: string, offset: int|null, cover: bool, excerpt: string, body: string}>
     */
    public const POSTS = [
        [
            'slug' => 'penerimaan-santri-baru-dibuka', 'title' => 'Penerimaan Santri Baru Tahun Ajaran {S} Dibuka', 'type' => 'berita', 'category' => 'Umum', 'offset' => 2, 'cover' => true,
            'excerpt' => 'Pendaftaran santri baru MTW/SMP dan TSN/SMA khusus putra dibuka sepanjang tahun selama kuota tersedia.',
            'body' => "<p>Ma'had Al-Khair Belitang menerima santri baru untuk tahun ajaran {S} pada jenjang MTW/SMP dan TSN/SMA, khusus putra. Pendaftaran dibuka sepanjang tahun selama kuota tersedia, terbagi dalam Gelombang I dan Gelombang II.</p><p>Program utama ma'had adalah tahfidzul Qur'an, bahasa Arab, dan studi Islam intensif, dipadukan dengan pendidikan umum.</p><h2>Berkas yang disiapkan</h2><ul><li>Kartu Keluarga</li><li>Akta kelahiran</li><li>Rapor semester terakhir</li><li>Pas foto 3x4 latar biru</li></ul><p>Pendaftaran dilakukan melalui laman PSB. Calon santri akan dihubungi panitia untuk tes seleksi bersama orang tua atau wali.</p>",
        ],
        [
            'slug' => 'masa-taaruf-santri-baru', 'title' => "Masa Ta'aruf Santri Baru", 'type' => 'kegiatan', 'category' => 'Kegiatan Al-Khair', 'offset' => 6, 'cover' => true,
            'excerpt' => "Santri baru mengikuti masa ta'aruf untuk mengenal adab, jadwal harian, dan lingkungan ma'had.",
            'body' => "<p>Santri baru mengikuti masa ta'aruf selama lima hari setelah serah terima. Kegiatan meliputi pengenalan tata tertib asrama, jadwal harian, halaqah tahfidz, dan pembiasaan Jurnal Adab.</p><p>Setiap kamar didampingi seorang musyrif yang membimbing santri sejak bangun tidur hingga istirahat malam.</p>",
        ],
        [
            'slug' => 'santri-ikut-kerja-bakti-bersama-warga-mojosari', 'title' => 'Santri Ikut Kerja Bakti Bersama Warga Mojosari', 'type' => 'kegiatan', 'category' => 'Kegiatan Al-Khair', 'offset' => 10, 'cover' => true,
            'excerpt' => 'Santri TSN/SMA membersihkan saluran air dan jalan desa bersama warga.',
            'body' => '<p>Pada Ahad pagi, santri TSN/SMA bergabung dengan warga Mojosari membersihkan saluran air dan bahu jalan menjelang musim hujan.</p><p>Kegiatan ini melatih kepedulian santri kepada lingkungan dan masyarakat sekitar ma\'had.</p>',
        ],
        [
            'slug' => 'jadwal-kajian-kitab-pekanan-wali-santri', 'title' => 'Jadwal Kajian Kitab Pekanan untuk Wali Santri', 'type' => 'berita', 'category' => 'Info Kajian', 'offset' => 14, 'cover' => true,
            'excerpt' => 'Kajian kitab untuk wali santri diadakan setiap Ahad pagi di masjid ma\'had.',
            'body' => "<p>Ma'had Al-Khair Belitang mengadakan kajian kitab pekanan untuk wali santri dan masyarakat umum setiap Ahad pukul 08.00 sampai 09.30 di masjid ma'had.</p><p>Kajian membahas akidah, adab, dan fiqih ibadah sehari-hari secara berurutan. Peserta dipersilakan membawa catatan; kitab dapat dipinjam di perpustakaan.</p>",
        ],
        [
            'slug' => 'ringkasan-kajian-menuntut-ilmu-sebelum-beramal', 'title' => 'Ringkasan Kajian: Pentingnya Menuntut Ilmu Sebelum Beramal', 'type' => 'berita', 'category' => 'Akidah & Manhaj', 'offset' => 18, 'cover' => false,
            'excerpt' => 'Ringkasan kajian pekanan tentang adab menuntut ilmu dan mengamalkannya.',
            'body' => '<p>Kajian pekan ini membahas pentingnya menuntut ilmu sebelum berkata dan beramal, agar ibadah dikerjakan di atas pemahaman yang benar.</p><p>Pemateri mengingatkan wali santri untuk mendampingi anak mengulang pelajaran, menjaga adab kepada guru, dan bersabar dalam proses belajar.</p>',
        ],
        [
            'slug' => 'program-hafalan-matan-dan-hadits-arbain', 'title' => "Program Hafalan Matan dan Hadits Arba'in Santri", 'type' => 'kegiatan', 'category' => 'Tafsir & Hadits', 'offset' => 22, 'cover' => false,
            'excerpt' => "Santri menghafal matan ilmiah dan hadits Arba'in secara bertahap dengan setoran harian.",
            'body' => "<p>Selain hafalan Al-Qur'an, santri mengikuti program hafalan matan ilmiah dan hadits Arba'in. Setoran dilakukan setiap hari kepada ustadz pembimbing, lalu diuji setiap akhir bulan.</p><p>Santri yang memenuhi syarat dapat melanjutkan ke program hafalan bersanad.</p>",
        ],
        [
            'slug' => 'praktik-penyembelihan-hewan-kurban-idul-adha', 'title' => 'Santri Belajar Praktik Penyembelihan Hewan Kurban saat Idul Adha', 'type' => 'kegiatan', 'category' => 'Fiqh', 'offset' => 26, 'cover' => false,
            'excerpt' => 'Pelajaran fiqih kurban dipraktikkan langsung saat penyembelihan hewan kurban di ma\'had.',
            'body' => '<p>Saat Idul Adha, santri mempraktikkan pelajaran fiqih kurban: memilih hewan yang memenuhi syarat, adab menyembelih, hingga membagikan daging kepada warga.</p><p>Kegiatan didampingi ustadz dan petugas penyembelihan yang berpengalaman.</p>',
        ],
        [
            'slug' => 'rencana-pembangunan-ruang-kelas-tahap-ii', 'title' => 'Rencana Pembangunan Ruang Kelas Tahap II', 'type' => 'berita', 'category' => 'Umum', 'offset' => null, 'cover' => false,
            'excerpt' => 'Pembangunan tambahan ruang kelas direncanakan tahun depan.',
            'body' => '<p>Yayasan merencanakan pembangunan tahap II berupa empat ruang kelas baru dan ruang belajar bersama.</p><p>Rincian anggaran dan jadwal pembangunan akan diumumkan setelah rapat pengurus yayasan.</p>',
        ],
    ];

    /** @var list<array{slug: string, title: string, category: string, description: string, captions: list<string>}> */
    public const ALBUMS = [
        ['slug' => 'fasilitas-mahad', 'title' => "Fasilitas Ma'had", 'category' => 'Fasilitas', 'description' => "Masjid, asrama, ruang kelas, dan kantor Ma'had Al-Khair Belitang.",
            'captions' => ['Bangunan luar masjid', 'Bagian dalam masjid', 'Asrama santri', 'Ruang kelas ber-AC', 'Ruang rapat', 'Kantor yayasan']],
        ['slug' => 'masa-taaruf-santri-baru', 'title' => "Masa Ta'aruf Santri Baru", 'category' => 'Kegiatan', 'description' => "Dokumentasi masa ta'aruf santri baru di awal tahun ajaran.",
            'captions' => ['Pembukaan masa ta\'aruf', 'Pengenalan tata tertib asrama', 'Halaqah tahfidz perdana', 'Pengisian Jurnal Adab', 'Foto bersama musyrif']],
        ['slug' => 'ekstrakurikuler-santri', 'title' => 'Ekstrakurikuler Santri', 'category' => 'Kegiatan', 'description' => 'Kegiatan ekstrakurikuler santri di luar jam belajar.',
            'captions' => ['Futsal', 'Karate', 'Tenis meja', 'Berenang']],
    ];

    /**
     * Halaman statis demo (F037): kunci = slug. Halaman sistem (`profil`, `visi-misi`,
     * `kontak`) diisi bila masih kosong; halaman lain dibuat bila belum ada. Semua terbit.
     *
     * @return array<string, array{title: string, body: string, status: string}>
     */
    public static function pages(): array
    {
        $board = implode('', array_map(
            fn (string $role, string $name): string => "<li>{$role}: {$name}</li>",
            array_keys(self::BOARD),
            self::BOARD,
        ));

        $pages = [
            'profil' => ['title' => 'Tentang Yayasan', 'body' => '<p>'.self::FOUNDATION." menaungi Ma'had Al-Khair Belitang di Mojosari, Kecamatan Belitang, Kabupaten OKU Timur. Yayasan berawal dari pengajian Al-Qur'an warga sekitar, lalu berkembang menjadi lembaga pendidikan berasrama.</p><p>Kenali lebih jauh lewat halaman Selayang Pandang dan Pengurus Al-Khair.</p>"],
            'pengurus' => ['title' => 'Pengurus Al-Khair', 'body' => '<p>Pengurus '.self::FOUNDATION." dan pimpinan ma'had:</p><ul>{$board}</ul>"],
            'selayang-pandang' => ['title' => 'Selayang Pandang', 'body' => "<p>Ma'had Al-Khair Belitang berfokus pada tahfidzul Qur'an, bahasa Arab, dan studi Islam intensif, dipadukan dengan pendidikan umum dan pembinaan adab. Jenjang yang dibuka adalah MTW/SMP dan TSN/SMA, khusus putra.</p><ul><li>Masjid, asrama, dan ruang belajar ber-AC</li><li>Makan 3 kali sehari dan laundry setiap hari</li><li>Pendampingan musyrif di setiap kamar</li><li>Perpustakaan dan fasilitas olahraga</li></ul>"],
            'visi-misi' => ['title' => 'Visi dan Misi', 'body' => "<h2>Visi</h2><p>Menjadi ma'had yang melahirkan generasi beradab bersama Al-Qur'an, berilmu syar'i, dan siap melanjutkan studi.</p><h2>Misi</h2><ul><li>Menyelenggarakan pembinaan tahsin dan tahfidz Al-Qur'an yang tertib dan bertahap.</li><li>Membiasakan bahasa Arab aktif dalam kegiatan harian.</li><li>Mengajarkan ilmu syar'i berdasarkan Al-Qur'an dan Sunnah dengan pemahaman yang lurus.</li><li>Membina adab dan akhlak santri melalui kehidupan berasrama.</li><li>Memadukan pendidikan agama dan pendidikan umum secara seimbang.</li></ul>"],
            'kontak' => ['title' => 'Kontak', 'body' => '<p>'.self::SCHOOL['address'].'</p><ul><li>Telepon: '.self::SCHOOL['phone'].'</li><li>WhatsApp: '.self::SCHOOL['whatsapp'].'</li><li>Email: '.self::SCHOOL['email'].'</li></ul><h2>Jam layanan tata usaha</h2><p>Senin sampai Kamis dan Sabtu pukul 08.00 sampai 14.00. Jumat pukul 08.00 sampai 11.00.</p>'],
        ];

        return array_map(fn (array $page): array => [...$page, 'status' => StaticPage::STATUS_PUBLISHED], $pages);
    }

    /**
     * Menu header demo (brief): Beranda, Tentang Yayasan, Pengurus Al-Khair, Selayang
     * Pandang, Info PSB (tampil setelah F038), Kontak. Berita, Galeri, dan Video tetap
     * terjangkau dari beranda dan footer. Kunci `page:` = slug halaman.
     *
     * @var list<array{0: string, 1: bool}>
     */
    public const MENU = [
        ['home', true], ['page:profil', true], ['page:pengurus', true], ['page:selayang-pandang', true],
        ['psb_info', true], ['page:kontak', true], ['news', false], ['gallery', false], ['videos', false],
    ];
}
