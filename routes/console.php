<?php

use App\Actions\Core\RecordSchedulerHeartbeat;
use Illuminate\Support\Facades\Schedule;

/*
| Tanda bahwa cron `schedule:run` berjalan, dibaca `pondok:doctor`. Harus
| event pertama agar tetap tercatat walau event lain gagal.
*/
Schedule::call(fn () => app(RecordSchedulerHeartbeat::class)->handle())
    ->everyMinute()
    ->name('pondok:scheduler-heartbeat');

/*
| Shared hosting tidak bisa menjalankan worker terus-menerus. Satu cron
| `schedule:run` setiap menit menjalankan worker yang berhenti saat antrean
| kosong. --max-time menjaga agar satu worker tidak melewati jendela cron.
*/
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();

/*
| Generator tagihan bulanan setiap tanggal 1 pukul 01:00 WIB. Command keluar
| tanpa membuat data bila modul keuangan mati.
*/
Schedule::command('keuangan:generate-invoices')
    ->monthlyOn(1, '01:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

/*
| Generator tagihan tahunan, didaftarkan setelah bulanan agar item tahunan
| bisa digabung ke tagihan bulanan yang baru dibuat. Command hanya membuat
| tagihan pada bulan `keuangan.annual_billing_month`.
*/
Schedule::command('keuangan:generate-annual-invoices')
    ->monthlyOn(1, '01:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();
