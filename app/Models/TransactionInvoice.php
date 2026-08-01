<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransactionInvoice extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'transaction_invoices';

    protected $fillable = [
        'user_id_buyer',
        'checkout_key',
        'alamat_buyer',
        'alamat_buyer_latitude',
        'alamat_buyer_longitude',
        'alamat_buyer_location_source',
        'payment_slug',
        'payment_name',
        'payment_method',
        'payment_account',
        'payment_reference',
        'price',
        'status',
        'expired_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'alamat_buyer_latitude' => 'float',
        'alamat_buyer_longitude' => 'float',
    ];

    /**
     * Mengambil buyer pemilik invoice.
     *
     * @return BelongsTo  Relasi Eloquent menuju model induk yang terkait.
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id_buyer');
    }

    /**
     * Mengambil transaksi per seller yang tergabung dalam invoice.
     *
     * @return HasMany  Relasi Eloquent menuju seluruh model turunan yang terkait.
     */
    public function transactionUsers(): HasMany
    {
        return $this->hasMany(TransactionUser::class, 'transaction_invoice_id');
    }
}
