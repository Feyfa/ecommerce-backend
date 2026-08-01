<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Keranjang extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'product_id',
        'user_id_seller',
        'user_id_buyer',
        'checked',
        'checkout',
        'total',
    ];

    /**
     * Mengambil produk yang disimpan pada item keranjang.
     *
     * @return BelongsTo  Relasi Eloquent menuju model induk yang terkait.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withTrashed();
    }

    /**
     * Mengambil buyer pemilik item keranjang.
     *
     * @return BelongsTo  Relasi Eloquent menuju model induk yang terkait.
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id_buyer');
    }

    /**
     * Mengambil seller pemilik produk pada item keranjang.
     *
     * @return BelongsTo  Relasi Eloquent menuju model induk yang terkait.
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id_seller');
    }
}
