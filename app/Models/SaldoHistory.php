<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaldoHistory extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'saldo_histories';

    protected $fillable = [
        'user_id',
        'transaction_user_id',
        'payment_user_id',
        'type',
        'title',
        'price',
        'saldo_before',
        'saldo_after',
    ];

    /**
     * Mengambil user pemilik riwayat saldo.
     *
     * @return BelongsTo  Relasi Eloquent menuju model induk yang terkait.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Mengambil transaksi yang terkait dengan perubahan saldo.
     *
     * @return BelongsTo  Relasi Eloquent menuju model induk yang terkait.
     */
    public function transactionUser(): BelongsTo
    {
        return $this->belongsTo(TransactionUser::class, 'transaction_user_id');
    }

    /**
     * Mengambil rekening pembayaran yang terkait dengan perubahan saldo.
     *
     * @return BelongsTo  Relasi Eloquent menuju model induk yang terkait.
     */
    public function paymentUser(): BelongsTo
    {
        return $this->belongsTo(PaymentUser::class, 'payment_user_id');
    }
}
