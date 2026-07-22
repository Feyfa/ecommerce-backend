<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id_seller',
        'img',
        'name',
        'price',
        'stock',
    ];

    public function seller()
    {
        return $this->belongsTo(User::class, 'user_id_seller');
    }

    public function keranjangs()
    {
        return $this->hasMany(Keranjang::class, 'product_id');
    }

    /**
     * Mengambil seluruh gambar produk berdasarkan urutan tampil.
     */
    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function transactionProducts()
    {
        return $this->hasMany(TransactionProduct::class, 'product_id');
    }
}
