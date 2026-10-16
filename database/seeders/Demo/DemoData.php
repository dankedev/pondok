<?php

namespace Database\Seeders\Demo;

/**
 * Data kurasi demo Ma'had Al-Khair Belitang (F034). Semua nama orang fiktif;
 * alamat memakai nama wilayah sekitar Belitang, Kab. OKU Timur. Tidak ada
 * tahun absolut: tahun masuk santri disimpan sebagai offset terhadap tahun
 * ajaran aktif (lihat `DemoCalendar`).
 */
final class DemoData
{
    public const SCHOOL = [
        'name' => "Ma'had Al-Khair Belitang",
        'tagline' => "Mendidik generasi Qur'ani yang berakhlak dan mandiri",
        'address' => 'Jl. Pesantren No. 17, Desa Sumber Agung, Kec. Belitang, Kab. Ogan Komering Ulu Timur (OKU Timur), Sumatera Selatan 32382',
        'phone' => '0735 000 0170',
        'whatsapp' => '0812 0000 0170',
        'email' => 'info@alkhair-belitang.test',
    ];

    public const PLACES = [
        'Belitang', 'Gumawang', 'Belitang II', 'Belitang III', 'Martapura', 'Buay Madang', 'Semendawai Suku III', 'Belitang Madang Raya',
    ];

    public const VILLAGES = [
        'Desa Sumber Agung, Kec. Belitang', 'Desa Gumawang, Kec. Belitang', 'Desa Sidogede, Kec. Belitang', 'Desa Tegal Rejo, Kec. Belitang II',
        'Desa Karang Kemiri, Kec. Belitang II', 'Desa Sukarami, Kec. Belitang III', 'Desa Bangsa Negara, Kec. Belitang III', 'Desa Pujorahayu, Kec. Buay Madang',
        'Desa Tanjung Kemala, Kec. Martapura', 'Desa Sumber Jaya, Kec. Semendawai Suku III',
    ];

    public const OCCUPATIONS = ['Petani', 'Pedagang', 'Wiraswasta', 'Guru', 'Pegawai swasta', 'Buruh tani', 'Peternak', 'Sopir', 'Penjahit', 'Perangkat desa'];

    /** Nama depan dan belakang ayah; digabung berpasangan (13 x 7 kombinasi unik). */
    private const FATHER_FIRST = ['Ahmad', 'Muhammad', 'Abdul', 'Sutrisno', 'Slamet', 'Hasan', 'Mahmud', 'Sulaiman', 'Ismail', 'Ridwan', 'Sugeng', 'Zulkifli', 'Hendra'];

    private const FATHER_LAST = ['Syahputra', 'Hidayat', 'Wibowo', 'Firmansyah', 'Nurdin', 'Santoso', 'Hamdani'];

    /**
     * Keluarga dengan lebih dari satu anak, atau wali bukan ayah (santri yatim).
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
        'KG' => ['name' => 'Kusnadi Rahman', 'relation' => 'ayah', 'occupation' => 'Petani'],
        'KH' => ['name' => 'Wagiman Saputro', 'relation' => 'ayah', 'occupation' => 'Sopir'],
        'KI' => ['name' => 'Siti Aminah', 'relation' => 'ibu', 'occupation' => 'Penjahit'],
        'KJ' => ['name' => 'Rukmini Lestari', 'relation' => 'ibu', 'occupation' => 'Pedagang'],
    ];

    /**
     * Rombel per jenjang (sama di tahun lalu dan tahun aktif). Kunci = nama rombel.
     *
     * @var array<string, list<array{grade: int, name: string, gender: string}>>
     */
    public const CLASSROOMS = [
        'SD' => [
            ['grade' => 4, 'name' => '4A', 'gender' => 'campur'],
            ['grade' => 5, 'name' => '5A', 'gender' => 'campur'],
            ['grade' => 6, 'name' => '6A', 'gender' => 'campur'],
        ],
        'SLTP' => [
            ['grade' => 7, 'name' => '7A', 'gender' => 'L'],
            ['grade' => 8, 'name' => '8A', 'gender' => 'L'],
            ['grade' => 9, 'name' => '9A', 'gender' => 'L'],
            ['grade' => 7, 'name' => '7B', 'gender' => 'P'],
            ['grade' => 8, 'name' => '8B', 'gender' => 'P'],
            ['grade' => 9, 'name' => '9B', 'gender' => 'P'],
        ],
        'SLTA' => [
            ['grade' => 10, 'name' => '10A', 'gender' => 'L'],
            ['grade' => 11, 'name' => '11A', 'gender' => 'L'],
            ['grade' => 12, 'name' => '12A', 'gender' => 'L'],
            ['grade' => 10, 'name' => '10B', 'gender' => 'P'],
            ['grade' => 11, 'name' => '11B', 'gender' => 'P'],
            ['grade' => 12, 'name' => '12B', 'gender' => 'P'],
        ],
    ];

    /**
     * Staff. `homeroom` = nama rombel yang diwalikan; `account` = peran akun demo yang ditautkan.
     *
     * @var list<array{nip: string, name: string, gender: string, position: string, homeroom?: string, account?: string}>
     */
    public const STAFF = [
        ['nip' => 'MAK-2009-001', 'name' => 'Abdul Hamid Syukri', 'gender' => 'L', 'position' => 'Mudir'],
        ['nip' => 'MAK-2011-002', 'name' => 'Ahmad Baihaqi', 'gender' => 'L', 'position' => 'ustadz', 'homeroom' => 'SD:4A'],
        ['nip' => 'MAK-2012-003', 'name' => 'Siti Maimunah', 'gender' => 'P', 'position' => 'ustadz', 'homeroom' => 'SD:5A'],
        ['nip' => 'MAK-2013-004', 'name' => 'Nur Azizah Hanum', 'gender' => 'P', 'position' => 'ustadz', 'homeroom' => 'SD:6A'],
        ['nip' => 'MAK-2012-005', 'name' => 'Fathur Rohman', 'gender' => 'L', 'position' => 'ustadz', 'homeroom' => 'SLTP:7A'],
        ['nip' => 'MAK-2014-006', 'name' => 'Zainal Abidin Rasyid', 'gender' => 'L', 'position' => 'ustadz', 'homeroom' => 'SLTP:8A'],
        ['nip' => 'MAK-2015-007', 'name' => 'Hasanuddin Malik', 'gender' => 'L', 'position' => 'ustadz', 'homeroom' => 'SLTP:9A'],
        ['nip' => 'MAK-2015-008', 'name' => 'Rofiqoh Hasanah', 'gender' => 'P', 'position' => 'ustadz', 'homeroom' => 'SLTP:7B'],
        ['nip' => 'MAK-2016-009', 'name' => 'Umi Kalsum Aryani', 'gender' => 'P', 'position' => 'ustadz', 'homeroom' => 'SLTP:8B'],
        ['nip' => 'MAK-2016-010', 'name' => 'Khoirun Nisa Latifah', 'gender' => 'P', 'position' => 'ustadz', 'homeroom' => 'SLTP:9B'],
        ['nip' => 'MAK-2017-011', 'name' => 'Mukhlisin Hadi', 'gender' => 'L', 'position' => 'ustadz', 'homeroom' => 'SLTA:10A'],
        ['nip' => 'MAK-2017-012', 'name' => 'Abdullah Fikri Ramadhan', 'gender' => 'L', 'position' => 'ustadz', 'homeroom' => 'SLTA:11A', 'account' => 'ustadz-tahfidz'],
        ['nip' => 'MAK-2018-013', 'name' => 'Saiful Anwar Hakim', 'gender' => 'L', 'position' => 'ustadz', 'homeroom' => 'SLTA:12A'],
        ['nip' => 'MAK-2018-014', 'name' => 'Nurhayati Sholihah', 'gender' => 'P', 'position' => 'ustadz', 'homeroom' => 'SLTA:10B'],
        ['nip' => 'MAK-2019-015', 'name' => 'Anisa Rahmawati Dewi', 'gender' => 'P', 'position' => 'ustadz', 'homeroom' => 'SLTA:11B'],
        ['nip' => 'MAK-2020-016', 'name' => 'Ifah Muflihah', 'gender' => 'P', 'position' => 'ustadz', 'homeroom' => 'SLTA:12B'],
        ['nip' => 'MAK-2016-017', 'name' => 'Rahmat Hidayat Saleh', 'gender' => 'L', 'position' => 'musyrif', 'account' => 'musyrif'],
        ['nip' => 'MAK-2019-018', 'name' => 'Ahmad Junaidi', 'gender' => 'L', 'position' => 'musyrif'],
        ['nip' => 'MAK-2017-019', 'name' => 'Siti Rohmah', 'gender' => 'P', 'position' => 'musyrif'],
        ['nip' => 'MAK-2021-020', 'name' => 'Nur Fadhilah Ulfa', 'gender' => 'P', 'position' => 'musyrif'],
        ['nip' => 'MAK-2014-021', 'name' => 'Dedi Kurniawan', 'gender' => 'L', 'position' => 'TU', 'account' => 'bendahara'],
        ['nip' => 'MAK-2020-022', 'name' => 'Eka Puspita Sari', 'gender' => 'P', 'position' => 'TU'],
    ];

    /**
     * Akun demo: peran => [nama, kode jenjang cakupan]. Nama diganti nama staff bila tertaut.
     *
     * @var array<string, array{name: string, units: list<string>}>
     */
    public const ACCOUNTS = [
        'super-admin' => ['name' => 'Admin Utama Demo', 'units' => []],
        'bendahara' => ['name' => 'Dedi Kurniawan', 'units' => []],
        'admin-sekolah' => ['name' => 'Admin Sekolah Demo', 'units' => ['SLTP']],
        'admin-psb' => ['name' => 'Panitia PSB Demo', 'units' => []],
        'admin-konten' => ['name' => 'Admin Konten Demo', 'units' => []],
        'kesantrian' => ['name' => 'Bagian Kesantrian Demo', 'units' => ['SLTP', 'SLTA']],
        'musyrif' => ['name' => 'Rahmat Hidayat Saleh', 'units' => []],
        'ustadz-tahfidz' => ['name' => 'Abdullah Fikri Ramadhan', 'units' => []],
        'petugas-pos' => ['name' => 'Petugas Pos Demo', 'units' => []],
    ];

    /**
     * Kelompok santri menurut riwayat. `p` = [jenjang, tingkat] tahun lalu (null = santri baru),
     * `a` = [jenjang, tingkat] tahun aktif (null = tidak lanjut), `status` akhir.
     * Baris santri: [nama, L/P, tanggal lahir MM-DD, indeks tempat lahir, kode keluarga|null, penanda].
     * Penanda: `pulang` (nonmukim), `yatim`, `tahfidz`, `ustadz`, `saudara` (keringanan SPP).
     */
    private const GROUPS = [
        ['p' => null, 'a' => ['SD', 4], 'status' => 'aktif', 'students' => [
            ['Muhammad Rizky Ramadhan', 'L', '03-14', 0, null, []],
            ['Ahmad Fadhil Hakim', 'L', '08-02', 1, null, []],
            ['Aisyah Nur Rahmah', 'P', '05-21', 2, 'KD', []],
            ['Zahra Salsabila', 'P', '11-09', 0, 'KJ', ['yatim']],
        ]],
        ['p' => ['SD', 4], 'a' => ['SD', 5], 'status' => 'aktif', 'students' => [
            ['Muhammad Hafiz Alfarizi', 'L', '01-30', 3, null, []],
            ['Rafi Ananda Putra', 'L', '06-17', 0, 'KE', []],
            ['Nabila Khairunnisa', 'P', '09-05', 1, 'KB', ['ustadz']],
            ['Hana Fitri Azzahra', 'P', '12-22', 4, null, []],
        ]],
        ['p' => ['SD', 5], 'a' => ['SD', 6], 'status' => 'aktif', 'students' => [
            ['Ilham Maulana Yusuf', 'L', '02-11', 0, 'KA', []],
            ['Daffa Arkan Saputra', 'L', '07-25', 5, 'KH', []],
            ['Kayla Putri Amalia', 'P', '04-03', 1, 'KC', ['saudara']],
            ['Syifa Aulia Rahmawati', 'P', '10-19', 6, null, []],
        ]],
        ['p' => ['SD', 6], 'a' => ['SLTP', 7], 'status' => 'aktif', 'students' => [
            ['Fikri Abdillah', 'L', '03-27', 1, 'KC', []],
            ['Rayhan Akbar Pratama', 'L', '09-12', 0, null, ['pulang']],
            ['Annisa Mutmainnah', 'P', '06-08', 2, null, []],
            ['Fathimah Az-Zahra', 'P', '01-16', 3, null, ['pulang']],
        ]],
        ['p' => ['SD', 4], 'a' => null, 'status' => 'pindah', 'students' => [
            ['Arkan Naufal Hidayat', 'L', '05-04', 0, null, []],
        ]],
        ['p' => null, 'a' => ['SLTP', 7], 'status' => 'aktif', 'students' => [
            ['Ahmad Zaki Mubarak', 'L', '02-19', 0, null, []],
            ['Muhammad Iqbal Firdaus', 'L', '08-30', 4, null, []],
            ['Faris Al-Ghifari', 'L', '11-11', 1, null, ['pulang']],
            ['Umar Faruq Ramadhan', 'L', '04-25', 7, null, []],
            ['Khadijah Nur Aini', 'P', '07-07', 0, null, []],
            ['Maryam Husna', 'P', '12-03', 5, null, ['pulang']],
            ['Salma Nurul Izzah', 'P', '03-09', 2, 'KF', []],
            ['Rahma Dewi Lestari', 'P', '10-28', 1, null, []],
        ]],
        ['p' => ['SLTP', 7], 'a' => ['SLTP', 8], 'status' => 'aktif', 'students' => [
            ['Abdurrahman Hakim', 'L', '01-05', 0, null, []],
            ['Muhammad Fauzan Akbar', 'L', '06-22', 0, 'KA', []],
            ['Haikal Fadhlurrahman', 'L', '09-14', 2, 'KD', ['pulang']],
            ['Rizal Maulana Ishaq', 'L', '03-31', 3, null, []],
            ['Yusuf Alfian Syah', 'L', '11-20', 1, null, ['ustadz']],
            ['Bilal Habibullah', 'L', '05-13', 6, 'KI', ['yatim', 'pulang']],
            ['Aulia Rahma Putri', 'P', '02-26', 0, null, []],
            ['Siti Zulaikha', 'P', '08-08', 4, null, ['pulang']],
            ['Nur Laila Fitriani', 'P', '12-15', 1, null, []],
            ['Hafizah Azkia', 'P', '04-18', 2, null, ['tahfidz']],
            ['Dinda Ayu Lestari', 'P', '07-29', 7, null, ['pulang']],
            ['Ummu Kultsum Rahayu', 'P', '10-02', 0, null, []],
        ]],
        ['p' => ['SLTP', 8], 'a' => ['SLTP', 9], 'status' => 'aktif', 'students' => [
            ['Ahmad Rifa\'i', 'L', '01-23', 1, null, []],
            ['Muhammad Irfan Hakiki', 'L', '06-04', 0, null, []],
            ['Fadli Ramadhan', 'L', '09-27', 3, null, ['pulang']],
            ['Taufik Hidayatullah', 'L', '03-06', 5, 'KG', []],
            ['Hamzah Alfarisi', 'L', '11-29', 0, null, ['tahfidz']],
            ['Nabil Assidiqi', 'L', '05-18', 2, null, []],
            ['Nurul Hidayah', 'P', '02-09', 0, null, []],
            ['Laila Maghfiroh', 'P', '08-21', 1, 'KB', []],
            ['Zainab Husnul Khotimah', 'P', '12-01', 6, null, ['pulang']],
            ['Alya Shafira', 'P', '04-12', 4, null, []],
            ['Wardah Nurjannah', 'P', '07-19', 0, null, []],
            ['Atikah Rahmani', 'P', '10-24', 2, null, []],
        ]],
        ['p' => ['SLTP', 8], 'a' => null, 'status' => 'keluar', 'students' => [
            ['Dimas Arya Pratama', 'L', '06-13', 1, null, []],
        ]],
        ['p' => ['SLTP', 9], 'a' => ['SLTA', 10], 'status' => 'aktif', 'students' => [
            ['Muhammad Syahrul Ramadhan', 'L', '01-28', 0, null, []],
            ['Ikhsan Nur Fauzi', 'L', '07-02', 3, null, ['pulang']],
            ['Fitri Handayani Putri', 'P', '03-17', 1, null, []],
            ['Rahmatul Aini', 'P', '09-23', 4, null, ['pulang']],
        ]],
        ['p' => ['SLTP', 9], 'a' => null, 'status' => 'lulus', 'students' => [
            ['Wahyu Setiawan Nugroho', 'L', '05-30', 0, null, []],
            ['Lutfiah Azzahra', 'P', '11-14', 2, null, []],
        ]],
        ['p' => null, 'a' => ['SLTA', 10], 'status' => 'aktif', 'students' => [
            ['Abdul Malik Firdaus', 'L', '02-04', 0, null, []],
            ['Hasbi Ramadhani', 'L', '08-16', 5, null, ['pulang']],
            ['Furqon Alamsyah', 'L', '12-27', 1, null, []],
            ['Nadia Ulya Rahmah', 'P', '04-08', 0, null, []],
            ['Qonita Zahrani', 'P', '06-29', 7, null, ['pulang']],
            ['Hilya Nafisah', 'P', '10-10', 2, null, []],
        ]],
        ['p' => ['SLTA', 10], 'a' => ['SLTA', 11], 'status' => 'aktif', 'students' => [
            ['Muhammad Alif Furqon', 'L', '01-12', 0, 'KE', ['tahfidz']],
            ['Zaidan Ar-Rasyid', 'L', '05-26', 3, null, []],
            ['Ridho Kamaluddin', 'L', '09-03', 1, null, ['pulang']],
            ['Ammar Fauzi Hakim', 'L', '03-21', 4, null, []],
            ['Habib Nurrohman', 'L', '11-07', 0, null, []],
            ['Asma Nadhifa', 'P', '02-17', 2, null, []],
            ['Rifda Khoirunnisa', 'P', '07-11', 0, null, []],
            ['Ulfa Maulidia', 'P', '12-19', 6, 'KI', ['yatim']],
            ['Najwa Humaira', 'P', '04-30', 5, 'KH', ['pulang']],
            ['Sarah Aqilah', 'P', '08-25', 1, null, []],
        ]],
        ['p' => ['SLTA', 11], 'a' => ['SLTA', 12], 'status' => 'aktif', 'students' => [
            ['Ahmad Mujahid', 'L', '01-09', 0, 'KA', []],
            ['Imam Fadhilah Rahman', 'L', '06-15', 2, null, []],
            ['Luqman Hakim Saputra', 'L', '10-05', 1, null, []],
            ['Rafiq Hidayatullah', 'L', '03-24', 3, null, []],
            ['Yahya Mukhlis', 'L', '08-11', 0, null, []],
            ['Fauziah Nur Azizah', 'P', '02-28', 4, null, []],
            ['Hanifah Rahmawati', 'P', '05-08', 0, null, []],
            ['Kamila Shalihah', 'P', '09-18', 1, 'KF', []],
            ['Mutiara Insani', 'P', '12-06', 7, null, []],
            ['Rania Husnaini', 'P', '07-15', 2, null, []],
        ]],
        ['p' => ['SLTA', 11], 'a' => null, 'status' => 'keluar', 'students' => [
            ['Putri Ayu Wulandari', 'P', '04-22', 0, null, []],
        ]],
        ['p' => ['SLTA', 12], 'a' => null, 'status' => 'lulus', 'students' => [
            ['Fajar Shiddiq', 'L', '03-03', 1, 'KG', []],
            ['Ikhwan Mubarok', 'L', '07-27', 0, null, []],
            ['Nurhadi Saputra', 'L', '11-24', 4, null, []],
            ['Riska Amelia', 'P', '01-19', 2, null, []],
            ['Tsabita Zahra', 'P', '06-06', 0, null, []],
        ]],
    ];

    /** Tingkat pertama tiap jenjang dan umur saat masuk tingkat itu. */
    public const FIRST_GRADE = ['SD' => 1, 'SLTP' => 7, 'SLTA' => 10];

    public const ENTRY_AGE = ['SD' => 7, 'SLTP' => 12, 'SLTA' => 15];

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

    /** Dormitori dan kamar. */
    public const DORMITORIES = [
        ['code' => 'ABK', 'name' => 'Asrama Abu Bakar Ash-Shiddiq', 'gender' => 'L', 'location' => 'Kompleks putra, sisi timur masjid', 'rooms' => ['Kamar 101', 'Kamar 102', 'Kamar 103', 'Kamar 104'], 'floor' => 1],
        ['code' => 'KHD', 'name' => 'Asrama Khadijah', 'gender' => 'P', 'location' => 'Kompleks putri, sisi barat madrasah', 'rooms' => ['Kamar 201', 'Kamar 202', 'Kamar 203', 'Kamar 204'], 'floor' => 2],
    ];

    public const ROOM_CAPACITY = 8;

    /** @var list<array{code: string, name: string, frequency: string, applies_to: string, billing_group: string, priority: int}> */
    public const FEE_TYPES = [
        ['code' => 'SPP', 'name' => 'SPP', 'frequency' => 'bulanan', 'applies_to' => 'semua', 'billing_group' => 'BULANAN', 'priority' => 30],
        ['code' => 'ASRAMA', 'name' => 'Biaya asrama', 'frequency' => 'bulanan', 'applies_to' => 'mukim', 'billing_group' => 'BULANAN', 'priority' => 20],
        ['code' => 'MAKAN', 'name' => 'Uang makan', 'frequency' => 'bulanan', 'applies_to' => 'mukim', 'billing_group' => 'BULANAN', 'priority' => 10],
        ['code' => 'FORMULIR', 'name' => 'Formulir pendaftaran', 'frequency' => 'saat_daftar', 'applies_to' => 'semua', 'billing_group' => 'PSB_DAFTAR', 'priority' => 10],
        ['code' => 'UANG_PANGKAL', 'name' => 'Uang pangkal', 'frequency' => 'saat_diterima', 'applies_to' => 'semua', 'billing_group' => 'PSB_TERIMA', 'priority' => 30],
        ['code' => 'SERAGAM', 'name' => 'Seragam', 'frequency' => 'saat_diterima', 'applies_to' => 'semua', 'billing_group' => 'PSB_TERIMA', 'priority' => 20],
        ['code' => 'KITAB', 'name' => 'Kitab dan buku', 'frequency' => 'saat_diterima', 'applies_to' => 'semua', 'billing_group' => 'PSB_TERIMA', 'priority' => 10],
    ];

    /**
     * Tarif dasar per jenis biaya dan jenjang (berlaku mulai tahun lalu).
     *
     * @var array<string, array<string, int>>
     */
    public const BASE_RATES = [
        'SPP' => ['SD' => 150000, 'SLTP' => 250000, 'SLTA' => 300000],
        'ASRAMA' => ['SLTP' => 200000, 'SLTA' => 200000],
        'MAKAN' => ['SLTP' => 450000, 'SLTA' => 450000],
        'FORMULIR' => ['SD' => 100000, 'SLTP' => 150000, 'SLTA' => 150000],
        'UANG_PANGKAL' => ['SD' => 2000000, 'SLTP' => 3500000, 'SLTA' => 4000000],
        'SERAGAM' => ['SD' => 850000, 'SLTP' => 850000, 'SLTA' => 850000],
        'KITAB' => ['SD' => 400000, 'SLTP' => 400000, 'SLTA' => 400000],
    ];

    /** SPP angkatan baru SLTA (berlaku mulai tahun aktif). */
    public const COHORT_RATE = ['fee' => 'SPP', 'unit' => 'SLTA', 'amount' => 325000];

    /** Biaya asrama putri per jenjang. */
    public const DORMITORY_RATE = ['fee' => 'ASRAMA', 'dormitory' => 'KHD', 'units' => ['SLTP', 'SLTA'], 'amount' => 225000];

    public const SPONSOR = 'Donatur Yayasan Al-Khair Belitang';

    /** @var array<string, array{name: string, mode: string, default_value: int}> */
    public const SCHOLARSHIPS = [
        'yatim' => ['name' => 'Beasiswa Yatim', 'mode' => 'nominal', 'default_value' => 0],
        'tahfidz' => ['name' => 'Beasiswa Tahfidz', 'mode' => 'persen', 'default_value' => 50],
        'ustadz' => ['name' => 'Keringanan Anak Ustadz', 'mode' => 'potongan', 'default_value' => 100000],
    ];

    public const SIBLING_DISCOUNT = ['mode' => 'potongan', 'value' => 50000, 'reason' => 'Keringanan saudara kandung'];

    /** Jalur PSB bawaan yang diberi beasiswa: kode jalur => [program, jenis biaya]. */
    public const TRACK_SCHOLARSHIPS = [
        'TAHFIDZ' => ['scholarship' => 'tahfidz', 'fees' => ['UANG_PANGKAL']],
        'YATIM' => ['scholarship' => 'yatim', 'fees' => ['UANG_PANGKAL', 'SPP']],
    ];

    /**
     * Pendaftar PSB gelombang tahun depan. `flow` menentukan status akhir;
     * `family` = kode keluarga santri aktif yang walinya dipakai ulang.
     *
     * @var list<array{name: string, gender: string, unit: string, track: string, birth_md: string, place: int, school: string, guardian: string, phone: string, flow: string, family?: string}>
     */
    public const APPLICANTS = [
        ['name' => 'Ghina Rahmadhani', 'gender' => 'P', 'unit' => 'SLTP', 'track' => 'REGULER', 'birth_md' => '03-12', 'place' => 0, 'school' => 'SD di Kecamatan Belitang', 'guardian' => 'Suparman Hadi', 'phone' => '081300000101', 'flow' => 'draft'],
        ['name' => 'Muhammad Fathan Mubina', 'gender' => 'L', 'unit' => 'SLTP', 'track' => 'REGULER', 'birth_md' => '07-21', 'place' => 1, 'school' => 'SD di Kecamatan Belitang II', 'guardian' => 'Rohmat Santoso', 'phone' => '081300000102', 'flow' => 'submitted'],
        ['name' => 'Azka Ramadhani', 'gender' => 'L', 'unit' => 'SD', 'track' => 'REGULER', 'birth_md' => '10-05', 'place' => 0, 'school' => 'TK di Kecamatan Belitang', 'guardian' => 'Hendri Gunawan', 'phone' => '081300000103', 'flow' => 'submitted'],
        ['name' => 'Naura Khairiyah', 'gender' => 'P', 'unit' => 'SLTA', 'track' => 'PRESTASI', 'birth_md' => '02-18', 'place' => 4, 'school' => 'SMP di Kecamatan Buay Madang', 'guardian' => 'Mulyadi Sasmita', 'phone' => '081300000104', 'flow' => 'submitted_doc_pending'],
        ['name' => 'Rizqi Aditya Nugraha', 'gender' => 'L', 'unit' => 'SLTP', 'track' => 'REGULER', 'birth_md' => '05-09', 'place' => 2, 'school' => 'MI di Kecamatan Belitang III', 'guardian' => 'Edi Suhendra', 'phone' => '081300000105', 'flow' => 'submitted_doc_rejected'],
        ['name' => 'Aqila Zahratunnisa', 'gender' => 'P', 'unit' => 'SLTP', 'track' => 'REGULER', 'birth_md' => '09-30', 'place' => 5, 'school' => 'SD di Kecamatan Martapura', 'guardian' => 'Sarifuddin Lubis', 'phone' => '081300000106', 'flow' => 'verified'],
        ['name' => 'Hanan Arrasyid', 'gender' => 'L', 'unit' => 'SLTA', 'track' => 'REGULER', 'birth_md' => '12-14', 'place' => 0, 'school' => 'SMP di Kecamatan Belitang', 'guardian' => 'Nasrullah Jamil', 'phone' => '081300000107', 'flow' => 'verified'],
        ['name' => 'Salwa Nabila Huda', 'gender' => 'P', 'unit' => 'SLTA', 'track' => 'PRESTASI', 'birth_md' => '04-27', 'place' => 3, 'school' => 'MTs di Kecamatan Belitang II', 'guardian' => 'Maryanto Widodo', 'phone' => '081300000108', 'flow' => 'reserved'],
        ['name' => 'Fauzan Hilmi', 'gender' => 'L', 'unit' => 'SLTP', 'track' => 'REGULER', 'birth_md' => '08-08', 'place' => 6, 'school' => 'SD di Kecamatan Semendawai Suku III', 'guardian' => 'Taufiqurrahman', 'phone' => '081300000109', 'flow' => 'rejected'],
        ['name' => 'Muhammad Hanif Al-Hafizh', 'gender' => 'L', 'unit' => 'SLTP', 'track' => 'TAHFIDZ', 'birth_md' => '01-25', 'place' => 0, 'school' => 'MI di Kecamatan Belitang', 'guardian' => 'Abdul Rozak', 'phone' => '081300000110', 'flow' => 'accepted'],
        ['name' => 'Nayla Putri Ramadhani', 'gender' => 'P', 'unit' => 'SLTA', 'track' => 'REGULER', 'birth_md' => '06-11', 'place' => 1, 'school' => 'SMP di Kecamatan Gumawang', 'guardian' => 'Hariyanto Saleh', 'phone' => '081300000111', 'flow' => 'accepted_partial'],
        ['name' => 'Raihanah Syakira', 'gender' => 'P', 'unit' => 'SLTP', 'track' => 'YATIM', 'birth_md' => '11-02', 'place' => 2, 'school' => 'SD di Kecamatan Belitang III', 'guardian' => 'Sri Wahyuni', 'phone' => '081300000112', 'flow' => 'reregistered_full', 'relation' => 'ibu'],
        ['name' => 'Akbar Maulana Yusuf', 'gender' => 'L', 'unit' => 'SD', 'track' => 'REGULER', 'birth_md' => '03-29', 'place' => 0, 'school' => 'TK di Kecamatan Belitang', 'guardian' => 'Joko Prasetyo', 'phone' => '081200000004', 'flow' => 'reregistered_partial', 'family' => 'KD', 'relation' => 'ayah'],
        ['name' => 'Dzakira Amalia', 'gender' => 'P', 'unit' => 'SLTA', 'track' => 'REGULER', 'birth_md' => '07-13', 'place' => 4, 'school' => 'MTs di Kecamatan Buay Madang', 'guardian' => 'Budiman Hasibuan', 'phone' => '081300000114', 'flow' => 'reregistered_partial', 'relation' => 'ayah'],
    ];

    /**
     * Prestasi. `offset` = hari sebelum hari ini; `participants` = [nama santri, peringkat, peran].
     *
     * @var list<array{title: string, category: string, level: string, kind: string, offset: int, verified: bool, public: bool, event: string, organizer: string, participants: list<array{0: int, 1: string, 2: string|null}>, post?: bool, certificate?: bool}>
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
        ['title' => 'Juara 1 Hafalan Hadits Arbain Beregu Putri', 'category' => 'keagamaan', 'level' => 'kecamatan', 'kind' => 'beregu', 'offset' => 120, 'verified' => true, 'public' => true,
            'event' => 'Musabaqah hafalan hadits Arbain beregu tingkat kecamatan', 'organizer' => 'Panitia musabaqah hadits tingkat kecamatan', 'participants' => [
                ['Asma Nadhifa', 'Juara 1', 'Ketua tim'], ['Rifda Khoirunnisa', 'Juara 1', null], ['Sarah Aqilah', 'Juara 1', null],
                ['Fauziah Nur Azizah', 'Juara 1', null], ['Hanifah Rahmawati', 'Juara 1', null], ['Nurul Hidayah', 'Juara 1', null],
            ]],
        ['title' => 'Juara 1 Cerdas Cermat Matematika', 'category' => 'akademik', 'level' => 'kecamatan', 'kind' => 'individu', 'offset' => 200, 'verified' => false, 'public' => false,
            'event' => 'Lomba cerdas cermat matematika tingkat kecamatan', 'organizer' => 'Kelompok kerja guru matematika kecamatan', 'participants' => [['Muhammad Irfan Hakiki', 'Juara 1', null]]],
        ['title' => 'Juara 1 Kaligrafi Musabaqah Internal', 'category' => 'seni', 'level' => 'pondok', 'kind' => 'individu', 'offset' => 260, 'verified' => false, 'public' => false,
            'event' => 'Musabaqah internal santri', 'organizer' => "Bagian kesantrian Ma'had Al-Khair Belitang", 'participants' => [['Alya Shafira', 'Juara 1', null]]],
    ];

    /** @var list<string> */
    public const POST_CATEGORIES = ['Kegiatan Santri', 'Pengumuman', 'Tahfidz'];

    /**
     * Berita. `{N}` dan `{A}` diganti nama tahun ajaran. `offset` = hari sebelum hari ini (null = draft).
     *
     * @var list<array{slug: string, title: string, type: string, category: string|null, unit?: string, offset: int|null, cover: bool, excerpt: string, body: string}>
     */
    public const POSTS = [
        [
            'slug' => 'penerimaan-santri-baru-dibuka', 'title' => 'Penerimaan Santri Baru Tahun Ajaran {N} Dibuka', 'type' => 'berita', 'category' => 'Pengumuman', 'offset' => 2, 'cover' => true,
            'excerpt' => 'Pendaftaran santri baru jenjang SD, SLTP, dan SLTA sudah dibuka melalui laman PSB.',
            'body' => "<p>Ma'had Al-Khair Belitang membuka penerimaan santri baru untuk tahun ajaran {N}. Pendaftaran terbuka bagi calon santri jenjang SD, SLTP, dan SLTA, baik putra maupun putri.</p><p>Pendaftaran dilakukan melalui laman PSB. Setelah mengisi formulir, calon santri akan menerima nomor pendaftaran dan kode akses untuk memantau status serta mengunggah berkas.</p><h2>Berkas yang disiapkan</h2><ul><li>Kartu Keluarga</li><li>Akta kelahiran</li><li>Pas foto terbaru</li></ul><p>Informasi lebih lanjut dapat ditanyakan ke bagian tata usaha pada jam layanan.</p>",
        ],
        [
            'slug' => 'pembukaan-tahun-ajaran-dan-masa-taaruf', 'title' => "Pembukaan Tahun Ajaran {A} dan Masa Ta'aruf Santri", 'type' => 'kegiatan', 'category' => 'Kegiatan Santri', 'offset' => 6, 'cover' => true,
            'excerpt' => "Santri baru mengikuti masa ta'aruf selama tiga hari untuk mengenal lingkungan pondok.",
            'body' => "<p>Tahun ajaran {A} dibuka dengan apel bersama seluruh santri, asatidz, dan wali santri di halaman masjid pondok.</p><p>Selama tiga hari, santri baru mengikuti masa ta'aruf: pengenalan tata tertib asrama, jadwal harian, kegiatan tahfidz, dan lingkungan sekitar pondok. Kakak kelas mendampingi santri baru di setiap kelompok.</p><p>Mudir berpesan agar santri menjaga adab, kebersihan, dan semangat belajar sejak hari pertama.</p>",
        ],
        [
            'slug' => 'kerja-bakti-santri-bersama-warga-desa', 'title' => 'Kerja Bakti Santri Bersama Warga Desa', 'type' => 'kegiatan', 'category' => 'Kegiatan Santri', 'offset' => 10, 'cover' => true,
            'excerpt' => 'Santri SLTP dan SLTA membersihkan saluran air dan jalan desa bersama warga.',
            'body' => '<p>Pada Ahad pagi, santri SLTP dan SLTA bergabung dengan warga Desa Sumber Agung membersihkan saluran air dan bahu jalan menjelang musim hujan.</p><p>Kegiatan ini menjadi bagian dari program pengabdian masyarakat yang rutin dilaksanakan setiap bulan. Warga menyiapkan konsumsi sederhana, sementara santri membawa peralatan dari pondok.</p>',
        ],
        [
            'slug' => 'wisuda-tahfidz-santri', 'title' => "Wisuda Tahfidz Santri Ma'had Al-Khair Belitang", 'type' => 'kegiatan', 'category' => 'Tahfidz', 'offset' => 14, 'cover' => true,
            'excerpt' => 'Dua puluh santri diwisuda setelah menyelesaikan hafalan 5, 10, dan 30 juz.',
            'body' => '<p>Sebanyak dua puluh santri mengikuti wisuda tahfidz setelah lulus ujian hafalan di hadapan dewan penguji. Para wisudawan menyelesaikan hafalan 5 juz, 10 juz, dan 30 juz.</p><p>Wali santri hadir menyaksikan anak-anak mereka membacakan hafalan secara bergantian. Acara ditutup dengan doa bersama dan pemberian sanad bacaan.</p><p>Program tahfidz terbuka untuk semua jenjang dengan target hafalan yang disesuaikan kemampuan santri.</p>',
        ],
        [
            'slug' => 'jadwal-libur-akhir-semester-ganjil', 'title' => 'Jadwal Libur Akhir Semester Ganjil', 'type' => 'berita', 'category' => 'Pengumuman', 'offset' => 18, 'cover' => false,
            'excerpt' => 'Santri dapat dijemput wali setelah pembagian rapor semester ganjil.',
            'body' => '<p>Libur akhir semester ganjil dimulai setelah pembagian rapor. Santri mukim dapat dijemput wali sesuai jadwal penjemputan per asrama.</p><p>Wali santri diminta membawa kartu santri saat penjemputan. Santri kembali ke pondok paling lambat satu hari sebelum kegiatan belajar dimulai.</p>',
        ],
        [
            'slug' => 'kajian-kitab-bulanan-bersama-wali-santri', 'title' => 'Kajian Kitab Bulanan Bersama Wali Santri', 'type' => 'kegiatan', 'category' => 'Kegiatan Santri', 'offset' => 22, 'cover' => false,
            'excerpt' => 'Wali santri dan warga sekitar mengikuti kajian kitab Riyadhus Shalihin di masjid pondok.',
            'body' => '<p>Kajian kitab bulanan kembali digelar di masjid pondok setelah shalat Subuh pada hari Ahad. Kajian kali ini membahas bab adab menuntut ilmu dari kitab Riyadhus Shalihin.</p><p>Wali santri dan warga sekitar hadir bersama santri mukim. Mudir mengajak wali santri ikut menjaga hafalan dan adab anak selama liburan di rumah, lalu kajian ditutup dengan sesi tanya jawab.</p>',
        ],
        [
            'slug' => 'santri-slta-belajar-di-perpustakaan-daerah', 'title' => 'Santri SLTA Belajar di Perpustakaan Daerah', 'type' => 'kegiatan', 'category' => 'Kegiatan Santri', 'unit' => 'SLTA', 'offset' => 26, 'cover' => false,
            'excerpt' => 'Kunjungan belajar santri SLTA untuk melatih kebiasaan membaca dan menulis.',
            'body' => '<p>Santri kelas 11 mengikuti kunjungan belajar ke perpustakaan daerah di Martapura. Mereka belajar mencari rujukan, membuat ringkasan bacaan, dan menyusun tulisan pendek.</p><p>Kegiatan ini mendukung program literasi pondok yang mewajibkan setiap santri membaca satu buku setiap bulan.</p>',
        ],
        [
            'slug' => 'rencana-pembangunan-asrama-putri-tahap-ii', 'title' => 'Rencana Pembangunan Asrama Putri Tahap II', 'type' => 'berita', 'category' => 'Pengumuman', 'offset' => null, 'cover' => false,
            'excerpt' => 'Pembangunan tambahan empat kamar untuk asrama putri direncanakan tahun depan.',
            'body' => '<p>Yayasan merencanakan pembangunan tahap II Asrama Khadijah berupa empat kamar baru dan ruang belajar bersama.</p><p>Rincian anggaran dan jadwal pembangunan akan diumumkan setelah rapat pengurus yayasan.</p>',
        ],
    ];

    /** @var list<array{slug: string, title: string, category: string, description: string, captions: list<string>}> */
    public const ALBUMS = [
        ['slug' => 'asrama-dan-masjid', 'title' => 'Asrama dan Masjid', 'category' => 'Fasilitas', 'description' => 'Suasana asrama putra, asrama putri, dan masjid pondok.',
            'captions' => ['Masjid pondok tampak depan', 'Asrama Abu Bakar Ash-Shiddiq', 'Asrama Khadijah', 'Ruang belajar bersama']],
        ['slug' => 'masa-taaruf-santri-baru', 'title' => "Masa Ta'aruf Santri Baru", 'category' => 'Kegiatan', 'description' => "Dokumentasi masa ta'aruf santri baru di awal tahun ajaran.",
            'captions' => ['Apel pembukaan', 'Pengenalan tata tertib asrama', 'Kelompok diskusi santri baru', 'Kegiatan tahsin bersama', 'Foto bersama pendamping']],
        ['slug' => 'wisuda-tahfidz', 'title' => 'Wisuda Tahfidz', 'category' => 'Kegiatan', 'description' => 'Wisuda santri yang menyelesaikan target hafalan.',
            'captions' => ['Wisudawan memasuki masjid', 'Pembacaan hafalan', 'Penyerahan sanad', 'Doa bersama wali santri']],
    ];

    /** ID YouTube rekaan (embed kosong); ganti dengan video lembaga sendiri. */
    public const VIDEOS = [
        ['youtube' => 'DmAlKhr0001', 'title' => "Profil Ma'had Al-Khair Belitang", 'featured' => true, 'description' => 'Video profil singkat pondok, asrama, dan kegiatan santri.'],
        ['youtube' => 'DmAlKhr0002', 'title' => 'Wisuda Tahfidz Santri', 'featured' => false, 'description' => 'Rangkaian acara wisuda tahfidz santri.'],
        ['youtube' => 'DmAlKhr0003', 'title' => 'Kegiatan Pagi di Asrama', 'featured' => false, 'description' => 'Rutinitas santri mukim dari subuh hingga berangkat sekolah.'],
    ];

    /** @var array<string, array{title: string, body: string}> */
    public const PAGES = [
        'profil' => ['title' => 'Profil Pondok', 'body' => "<p>Ma'had Al-Khair Belitang adalah pondok pesantren yang berdiri di Kecamatan Belitang, Kabupaten Ogan Komering Ulu Timur, Sumatera Selatan. Pondok bermula dari pengajian Al-Qur'an untuk anak-anak desa, lalu berkembang menjadi lembaga pendidikan berasrama.</p><h2>Jenjang pendidikan</h2><ul><li>SD untuk kelas 4 sampai 6</li><li>SLTP putra dan putri</li><li>SLTA putra dan putri</li></ul><h2>Asrama</h2><p>Santri SLTP dan SLTA dapat tinggal di asrama: Asrama Abu Bakar Ash-Shiddiq untuk putra dan Asrama Khadijah untuk putri. Setiap asrama didampingi musyrif dan musyrifah.</p>"],
        'visi-misi' => ['title' => 'Visi dan Misi', 'body' => "<h2>Visi</h2><p>Menjadi pondok pesantren yang melahirkan generasi Qur'ani, berakhlak mulia, berilmu, dan mandiri.</p><h2>Misi</h2><ul><li>Menyelenggarakan pendidikan Al-Qur'an dan tahfidz yang tertib dan bertahap.</li><li>Membiasakan adab dan ibadah dalam kehidupan sehari-hari di asrama.</li><li>Memadukan pelajaran agama dan pelajaran umum secara seimbang.</li><li>Melatih kemandirian, kepemimpinan, dan kepedulian kepada masyarakat.</li><li>Menjalin kerja sama yang baik dengan wali santri dan warga sekitar.</li></ul>"],
        'kontak' => ['title' => 'Kontak', 'body' => '<p>Jl. Pesantren No. 17, Desa Sumber Agung, Kecamatan Belitang, Kabupaten OKU Timur, Sumatera Selatan.</p><ul><li>Telepon: 0735 000 0170</li><li>WhatsApp: 0812 0000 0170</li><li>Email: info@alkhair-belitang.test</li></ul><h2>Jam layanan tata usaha</h2><p>Senin sampai Kamis dan Sabtu pukul 08.00 sampai 14.00. Jumat pukul 08.00 sampai 11.00.</p>'],
    ];
}
