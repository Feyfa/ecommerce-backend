<?php

namespace App\Models;

use App\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Menyimpan status pencairan Xendit untuk satu penarikan saldo beserta jadwal pengecekannya.
 *
 * Saldo sudah dipotong ketika row ini dibuat. Selama status masih processing, saldo itu ditahan
 * sampai Xendit menyatakan pencairan selesai atau gagal.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $payment_user_id
 * @property string $saldo_history_id
 * @property string|null $refund_saldo_history_id
 * @property string $external_id
 * @property string|null $provider_disbursement_id
 * @property int $amount
 * @property int $refund_portion
 * @property int $income_portion
 * @property WithdrawalStatus $status
 * @property string|null $failure_code
 * @property int $check_attempts
 * @property \Carbon\CarbonImmutable|null $next_check_at
 * @property \Carbon\CarbonImmutable|null $last_checked_at
 * @property \Carbon\CarbonImmutable|null $overdue_logged_at
 * @property \Carbon\CarbonImmutable|null $settled_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
class SaldoWithdrawal extends Model
{
    use HasUuids;

    protected $table = 'saldo_withdrawals';

    protected $fillable = [
        'user_id',
        'payment_user_id',
        'saldo_history_id',
        'refund_saldo_history_id',
        'external_id',
        'provider_disbursement_id',
        'amount',
        'refund_portion',
        'income_portion',
        'status',
        'failure_code',
        'check_attempts',
        'next_check_at',
        'last_checked_at',
        'overdue_logged_at',
        'settled_at',
    ];

    protected $casts = [
        // Nominal rupiah bulat; status memakai enum agar nilai di luar lifecycle ditolak.
        'amount' => 'integer',
        'refund_portion' => 'integer',
        'income_portion' => 'integer',
        'status' => WithdrawalStatus::class,
        'check_attempts' => 'integer',

        // Jadwal pengecekan dan penanda waktu.
        'next_check_at' => 'immutable_datetime',
        'last_checked_at' => 'immutable_datetime',
        'overdue_logged_at' => 'immutable_datetime',
        'settled_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * Mengambil baris riwayat penarikan yang mencatat pemotongan saldo.
     *
     * @return BelongsTo<SaldoHistory, SaldoWithdrawal> Relasi ke riwayat penarikan.
     */
    public function saldoHistory(): BelongsTo
    {
        return $this->belongsTo(SaldoHistory::class, 'saldo_history_id');
    }
}
