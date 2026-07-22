<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Menyimpan path dan urutan gambar yang dimiliki sebuah produk.
 */
class ProductImage extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'id',
        'product_id',
        'path',
        'position',
    ];

    protected $hidden = [
        'product_id',
        'created_at',
        'updated_at',
    ];

    /**
     * Mengambil produk pemilik gambar.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
