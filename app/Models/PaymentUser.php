<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentUser extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'payment_users';

    protected $fillable = [
        'user_id',
        'payment_id',
        'name',
        'account',
    ];

    /**
     * Mengambil user pemilik rekening pembayaran.
     *
     * @return BelongsTo  Relasi Eloquent menuju model induk yang terkait.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Mengambil metode pembayaran yang digunakan rekening ini.
     *
     * @return BelongsTo  Relasi Eloquent menuju model induk yang terkait.
     */
    public function paymentList(): BelongsTo
    {
        return $this->belongsTo(PaymentList::class, 'payment_id');
    }
}
