<?php

namespace App\Http\Controllers\Dokumen;

use App\Actions\Dokumen\FindLetterByVerificationCode;
use App\Http\Controllers\Controller;
use App\Models\IssuedLetter;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Halaman publik verifikasi keaslian surat (tanpa login, tanpa cakupan
 * jenjang). Menampilkan data minimal: nama santri termasker, nomor, jenis,
 * tanggal, penandatangan, dan nama lembaga; tanpa NIS, tanggal lahir,
 * keperluan, maupun alasan batal.
 */
class LetterVerificationController extends Controller
{
    public const MAX_INPUT_LENGTH = 64;

    public function form(Request $request): Response|RedirectResponse
    {
        if ($request->has('kode')) {
            $input = $request->query('kode');
            $code = is_string($input)
                ? IssuedLetter::normalizeVerificationCode(mb_substr($input, 0, self::MAX_INPUT_LENGTH))
                : '';

            if ($code === '') {
                return $this->page(['error' => __('pondok.letter_verification.code_required')]);
            }

            return redirect()->route('dokumen.letters.verify', ['code' => $code]);
        }

        return $this->page();
    }

    public function show(string $code, FindLetterByVerificationCode $findLetter): Response
    {
        $letter = $findLetter->handle(mb_substr($code, 0, self::MAX_INPUT_LENGTH));

        if ($letter === null) {
            return $this->page(['notFound' => true], 404);
        }

        return $this->page(['letter' => $letter]);
    }

    /**
     * Jawaban untuk URL verifikasi berisi byte UTF-8 rusak (ditolak sebelum routing): halaman 404 yang
     * sama beserta header privasi, selama modul aktif. Path lain memakai penanganan bawaan (null).
     */
    public static function malformedUrlResponse(Request $request): ?Response
    {
        if (! str_starts_with(ltrim($request->getPathInfo(), '/'), 'verifikasi-surat/') || ! Setting::moduleEnabled('dokumen')) {
            return null;
        }

        return (new self)->page(['notFound' => true], 404)->withHeaders([
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function page(array $data = [], int $status = 200): Response
    {
        return response()->view('dokumen.verify', [
            'letter' => null,
            'notFound' => false,
            'error' => null,
            ...$data,
        ], $status);
    }
}
