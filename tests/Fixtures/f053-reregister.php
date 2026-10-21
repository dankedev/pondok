<?php

/**
 * Proses anak uji konkurensi F053 (`ReRegisterConcurrencyMysqlTest`): menjalankan satu daftar ulang di proses PHP
 * terpisah dengan koneksi database dari env yang diteruskan proses induk.
 *
 * Argumen: <applicant_id> <user_id> <start_at (unix float)> <hold_ms> <now (ISO 8601)>. `now` = waktu beku proses
 * induk (`travelTo`). Menunggu sampai `start_at` agar dua proses mulai bersamaan. `hold_ms` > 0 menahan transaksi
 * (sebelum berkas disalin) agar proses lain masuk saat transaksi ini masih terbuka. Disk `private` diarahkan ke `F053_PRIVATE_ROOT`. Keluaran: satu baris JSON, termasuk `elapsed_ms`
 * sejak `start_at` (bukti proses menunggu kunci proses lain).
 */

use App\Actions\Psb\CopyApplicantDocuments;
use App\Actions\Psb\ReRegisterApplicant;
use App\Models\Applicant;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $applicantId, $userId, $startAt, $holdMs, $now] = $argv;

Date::setTestNow(Carbon::parse($now));

config(['filesystems.disks.private.root' => getenv('F053_PRIVATE_ROOT')]);

if ((int) $holdMs > 0) {
    $app->instance(CopyApplicantDocuments::class, new class(app(CopyApplicantDocuments::class), (int) $holdMs) extends CopyApplicantDocuments
    {
        public function __construct(private CopyApplicantDocuments $real, private int $holdMs) {}

        public function handle(Applicant $applicant, Student $student, Guardian $guardian, User $by, array &$copiedPaths): array
        {
            usleep($this->holdMs * 1000);

            return $this->real->handle($applicant, $student, $guardian, $by, $copiedPaths);
        }
    });
}

$applicant = Applicant::withoutGlobalScopes()->findOrFail((int) $applicantId);
$user = User::query()->findOrFail((int) $userId);

while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

try {
    $result = app(ReRegisterApplicant::class)->handle($applicant, $user);
    $output = ['ok' => true, 'student_id' => $result['student']->id, 'nis' => $result['student']->nis];
} catch (ValidationException $exception) {
    $output = ['ok' => false, 'error' => ValidationException::class, 'errors' => $exception->errors()];
} catch (Throwable $exception) {
    $output = ['ok' => false, 'error' => $exception::class, 'message' => $exception->getMessage()];
}

$output['elapsed_ms'] = (int) round((microtime(true) - (float) $startAt) * 1000);

echo json_encode($output), PHP_EOL;
