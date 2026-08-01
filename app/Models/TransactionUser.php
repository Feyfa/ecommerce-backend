<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransactionUser extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'transaction_users';

    protected $fillable = [
        'user_id_seller',
        'user_id_buyer',
        'transaction_invoice_id',
        'transaction_number',
        'alamat_seller',
        'alamat_seller_latitude',
        'alamat_seller_longitude',
        'alamat_seller_location_source',
        'kurir_price',
        'product_price',
        'kurir_type',
        'kurir_estimate',
        'noted',
        'status',
    ];

    protected $casts = [
        'alamat_seller_latitude' => 'float',
        'alamat_seller_longitude' => 'float',
    ];

    /**
     * Mengambil invoice induk transaksi seller.
     *
     * @return BelongsTo  Relasi Eloquent menuju model induk yang terkait.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(TransactionInvoice::class, 'transaction_invoice_id');
    }

    /**
     * Mengambil seller yang menangani transaksi.
     *
     * @return BelongsTo  Relasi Eloquent menuju model induk yang terkait.
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id_seller');
    }

    /**
     * Mengambil buyer pemilik transaksi.
     *
     * @return BelongsTo  Relasi Eloquent menuju model induk yang terkait.
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id_buyer');
    }

    /**
     * Mengambil seluruh item produk dalam transaksi seller.
     *
     * @return HasMany  Relasi Eloquent menuju seluruh model turunan yang terkait.
     */
    public function products(): HasMany
    {
        return $this->hasMany(TransactionProduct::class, 'transaction_user_id');
    }
}
