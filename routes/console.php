<?php

use Illuminate\Support\Facades\Schedule;

/*
| Shared hosting tidak bisa menjalankan worker terus-menerus. Satu cron
| `schedule:run` setiap menit menjalankan worker yang berhenti saat antrean
| kosong. --max-time menjaga agar satu worker tidak melewati jendela cron.
*/
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();
