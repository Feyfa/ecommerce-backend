<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentList extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'payment_lists';

    protected $fillable = [
        'type',
        'method',
        'slug',
        'name',
    ];

    /**
     * Mengambil seluruh rekening user untuk metode pembayaran ini.
     *
     * @return HasMany  Relasi Eloquent menuju seluruh model turunan yang terkait.
     */
    public function paymentUsers(): HasMany
    {
        return $this->hasMany(PaymentUser::class, 'payment_id');
    }
}
