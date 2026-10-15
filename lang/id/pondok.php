<?php

return [

    'navigation' => [
        'master' => 'Data Induk',
        'access' => 'Pengguna & Akses',
    ],

    'common' => [
        'created_at' => 'Dibuat',
        'updated_at' => 'Diperbarui',
        'is_active' => 'Aktif',
        'name' => 'Nama',
        'gender' => 'Jenis kelamin',
        'genders' => [
            'L' => 'Laki-laki',
            'P' => 'Perempuan',
        ],
    ],

    'unit' => [
        'label' => 'Jenjang',
        'plural' => 'Jenjang',
        'code' => 'Kode',
        'name' => 'Nama jenjang',
        'sort_order' => 'Urutan',
    ],

    'academic_year' => [
        'label' => 'Tahun Ajaran',
        'plural' => 'Tahun Ajaran',
        'name' => 'Tahun ajaran',
        'name_helper' => 'Contoh: 2026/2027',
        'starts_on' => 'Mulai',
        'ends_on' => 'Selesai',
        'is_active' => 'Aktif',
        'activate' => 'Jadikan aktif',
        'activated' => 'Tahun ajaran :name sekarang aktif.',
        'cannot_delete_active' => 'Tahun ajaran yang aktif tidak bisa dihapus.',
        'ends_after_start' => 'Tanggal selesai harus setelah tanggal mulai.',
    ],

    'staff' => [
        'label' => 'Staff',
        'plural' => 'Staff',
        'nip' => 'NIP',
        'phone' => 'Telepon',
        'position' => 'Jabatan',
        'positions' => ['Ustadz', 'Musyrif', 'Wali kelas', 'TU'],
        'user' => 'Akun login',
        'user_helper' => 'Kosongkan jika staff tidak perlu login.',
    ],

    'user' => [
        'label' => 'Pengguna',
        'plural' => 'Pengguna',
        'email' => 'Email',
        'password' => 'Kata sandi',
        'password_helper' => 'Kosongkan jika tidak ingin mengubah kata sandi.',
        'is_active' => 'Aktif',
        'is_active_helper' => 'Pengguna nonaktif tidak bisa masuk panel.',
        'last_login_at' => 'Terakhir masuk',
        'roles' => 'Peran',
        'units' => 'Cakupan jenjang',
        'units_helper' => 'Kosongkan agar pengguna bisa melihat data semua jenjang.',
        'all_units' => 'Semua jenjang',
        'section_account' => 'Akun',
        'section_access' => 'Hak akses',
    ],

];
