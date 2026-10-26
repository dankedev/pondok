<?php

namespace App\Enums;

/**
 * Jenis mutasi uang saku santri (F028). `setor` dan `koreksi_tambah` menambah
 * saldo, `tarik` dan `koreksi_kurang` mengurangi.
 */
enum WalletTransactionType: string
{
    case Setor = 'setor';
    case Tarik = 'tarik';
    case KoreksiTambah = 'koreksi_tambah';
    case KoreksiKurang = 'koreksi_kurang';

    public function isCredit(): bool
    {
        return $this === self::Setor || $this === self::KoreksiTambah;
    }

    public function isCorrection(): bool
    {
        return $this === self::KoreksiTambah || $this === self::KoreksiKurang;
    }

    public function label(): string
    {
        return __("pondok.wallet.types.{$this->value}");
    }
}
