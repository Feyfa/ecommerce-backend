<?php

namespace App\Enums;

/**
 * Menentukan status penarikan saldo di Xendit.
 *
 * `processing` berarti saldo sudah ditahan dan hasil pencairan belum pasti. `completed` dan
 * `failed` adalah status akhir; `failed` selalu disertai pengembalian saldo.
 */
enum WithdrawalStatus: string
{
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
