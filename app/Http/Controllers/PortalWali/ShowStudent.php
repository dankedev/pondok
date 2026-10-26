<?php

namespace App\Http\Controllers\PortalWali;

use App\Actions\Keuangan\WalletBalance;
use App\Enums\AttendanceStatus;
use App\Enums\Module;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Student;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Rincian santri portal wali (F079): ringkasan, tagihan non-batal berhalaman (Keuangan aktif),
 * uang saku (Keuangan aktif, F028), kehadiran bulan berjalan (Absensi aktif, F025), pelanggaran tahun ajaran aktif tanpa keterangan
 * (Kedisiplinan aktif, F026), dan dokumen terkini non-rahasia (Dokumen aktif).
 * Santri bukan milik wali → 404.
 */
class ShowStudent extends Controller
{
    public function __invoke(Request $request, int $student): View
    {
        $user = $request->user();
        $student = $user->portalStudentsWithSummary()->findOrFail($student);

        return view('portal-wali.student', [
            'student' => $student,
            'invoices' => Module::Keuangan->isEnabled()
                ? $user->portalInvoices($student)->orderByDesc('period')->orderByDesc('id')->paginate(10)
                : null,
            'wallet' => Module::Keuangan->isEnabled() ? $this->wallet($user, $student) : null,
            'attendance' => Module::Absensi->isEnabled() ? $this->attendance($user, $student) : null,
            'violations' => Module::Kedisiplinan->isEnabled()
                ? $user->portalViolations($student)->orderByDesc('occurred_on')->orderByDesc('id')->get()
                : null,
            'documents' => Module::Dokumen->isEnabled()
                ? $user->portalDocuments()->where('documentable_id', $student->id)->with('documentType')->latest('id')->get()
                : null,
        ]);
    }

    /**
     * Saldo uang saku dan 20 mutasi terakhir (F028).
     *
     * @return array{balance: int, transactions: Collection<int, WalletTransaction>}
     */
    private function wallet(User $user, Student $student): array
    {
        return [
            'balance' => WalletBalance::forStudent($student->id),
            'transactions' => $user->portalWalletTransactions($student)->orderByDesc('id')->limit(20)->get(),
        ];
    }

    /**
     * Kehadiran bulan berjalan: jumlah per status dan hari tidak hadir urut tanggal.
     *
     * @return array{month: CarbonImmutable, counts: array<string, int>, absences: Collection<int, AttendanceRecord>}
     */
    private function attendance(User $user, Student $student): array
    {
        $month = CarbonImmutable::today(config('app.timezone'))->startOfMonth();

        $records = $user->portalAttendanceRecords($student, $month)->get()
            ->sortBy(fn (AttendanceRecord $record): string => $record->session->held_on->toDateString())
            ->values();

        return [
            'month' => $month,
            'counts' => collect(AttendanceStatus::cases())
                ->mapWithKeys(fn (AttendanceStatus $status): array => [$status->value => $records->where('status', $status)->count()])
                ->all(),
            'absences' => $records->reject(fn (AttendanceRecord $record): bool => $record->status === AttendanceStatus::Present)->values(),
        ];
    }
}
