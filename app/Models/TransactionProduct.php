<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionProduct extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'transaction_products';

    protected $fillable = [
        'user_id_seller',
        'user_id_buyer',
        'product_id',
        'transaction_user_id',
        'price',
        'total',
    ];

    /**
     * Mengambil transaksi seller yang memiliki item ini.
     *
     * @return BelongsTo  Relasi Eloquent menuju model induk yang terkait.
     */
    public function transactionUser(): BelongsTo
    {
        return $this->belongsTo(TransactionUser::class, 'transaction_user_id');
    }

    /**
     * Mengambil produk sumber dari item transaksi.
     *
     * @return BelongsTo  Relasi Eloquent menuju model induk yang terkait.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withTrashed();
    }
}
