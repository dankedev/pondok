<?php

use App\Actions\Core\RecordSchedulerHeartbeat;
use App\Enums\Module;
use App\Models\Setting;
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
| Generator tagihan bulanan setiap tanggal 1 pukul 01:00 WIB. Event jadwal
| modul dilewati saat modulnya mati (`->when`, F066); command tetap mengecek
| saklar untuk jalankan manual.
*/
Schedule::command('keuangan:generate-invoices')
    ->when(fn (): bool => Module::Keuangan->isEnabled())
    ->monthlyOn(1, '01:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

/*
| Generator tagihan tahunan, didaftarkan setelah bulanan agar item tahunan
| bisa digabung ke tagihan bulanan yang baru dibuat. Command hanya membuat
| tagihan pada bulan `keuangan.annual_billing_month`.
*/
Schedule::command('keuangan:generate-annual-invoices')
    ->when(fn (): bool => Module::Keuangan->isEnabled())
    ->monthlyOn(1, '01:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

/*
| Pembersih berkas bukti transfer yang tidak dirujuk pembayaran (unggahan
| yang pembayarannya tidak jadi dicatat), setiap hari pukul 02:30 WIB.
*/
Schedule::command('keuangan:prune-payment-proofs')
    ->when(fn (): bool => Module::Keuangan->isEnabled())
    ->dailyAt('02:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

/*
| Pembersih CSV laporan keuangan dari antrean ekspor (F068). Berkas berlaku
| 24 jam; dijalankan setiap jam agar tidak tersimpan jauh melewati itu.
*/
Schedule::command('keuangan:prune-finance-exports')
    ->when(fn (): bool => Module::Keuangan->isEnabled())
    ->hourly()
    ->withoutOverlapping();

/*
| Backup harian inti (F042) pukul 01:30 WIB, hanya bila diaktifkan di
| Pengaturan karena kuota shared hosting kecil. Manual: `pondok:backup`.
*/
Schedule::command('pondok:backup')
    ->when(fn (): bool => (bool) Setting::get('backup.enabled', false))
    ->dailyAt('01:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(120);
