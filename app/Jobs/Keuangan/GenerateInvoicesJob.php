<?php

namespace App\Jobs\Keuangan;

use App\Actions\Keuangan\GenerateAnnualInvoices;
use App\Actions\Keuangan\GenerateMonthlyInvoices;
use App\Models\AcademicYear;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Generate tagihan manual dari panel di latar belakang, agar tidak terpotong
 * `max_execution_time` web. Hanya memanggil `GenerateMonthlyInvoices` atau
 * `GenerateAnnualInvoices`; hasilnya dikirim ke pemicu sebagai notifikasi
 * database Filament.
 *
 * Kegagalan yang bisa ditindaklanjuti (periode sedang diproses, nomor tagihan
 * bentrok) menjadi notifikasi `danger` dan Job selesai normal. Exception lain
 * dilempar ulang; `failed()` mengirim pesan generik tanpa detail exception.
 *
 * Tanpa `ShouldBeUnique`: klik ganda mengantrekan dua Job, Job kedua mendapat
 * `already_running` atau hitungan "sudah ada" karena generator idempotent.
 */
class GenerateInvoicesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = GenerateMonthlyInvoices::LOCK_SECONDS;

    public bool $deleteWhenMissingModels = true;

    private function __construct(
        public ?User $user,
        public ?CarbonImmutable $period = null,
        public ?AcademicYear $academicYear = null,
    ) {}

    public static function monthly(User $user, CarbonInterface $period): self
    {
        return new self($user, period: CarbonImmutable::parse($period->format('Y-m-d'), 'Asia/Jakarta'));
    }

    public static function annual(User $user, AcademicYear $academicYear): self
    {
        return new self($user, academicYear: $academicYear);
    }

    public function handle(): void
    {
        try {
            $result = $this->academicYear !== null
                ? app(GenerateAnnualInvoices::class)->handle($this->academicYear, $this->user)
                : app(GenerateMonthlyInvoices::class)->handle($this->period, $this->user);
        } catch (ValidationException $exception) {
            $this->notifyDanger(collect($exception->errors())->flatten()->first());

            return;
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== __('pondok.invoice.errors.number_conflict')) {
                throw $exception;
            }

            $this->notifyDanger($exception->getMessage());

            return;
        }

        $skipped = count($result['skipped']);

        $this->send(Notification::make()
            ->title(__('pondok.invoice.generated', [
                'period' => $this->label(),
                'created' => $result['created_invoices'],
                'existing' => $result['existing_invoices'],
                'skipped' => $skipped,
            ]))
            ->body($skipped > 0 ? __('pondok.invoice.generated_skipped_hint') : null)
            ->success());
    }

    public function failed(?Throwable $exception): void
    {
        $this->notifyDanger(__('pondok.invoice.generate_failed'));
    }

    private function notifyDanger(string $message): void
    {
        $this->send(Notification::make()->title($message)->danger());
    }

    /**
     * Pemicu bisa `null` saat `failed()` dipanggil setelah user dihapus.
     */
    private function send(Notification $notification): void
    {
        if ($this->user !== null) {
            $notification->sendToDatabase($this->user);
        }
    }

    private function label(): string
    {
        return $this->academicYear?->name ?? $this->period->translatedFormat('F Y');
    }
}
