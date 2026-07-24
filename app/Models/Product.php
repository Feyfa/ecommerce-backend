<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory, HasUuids;

    public const STOCK_FILTER_OPTIONS = ['all', 'healthy', 'low', 'empty', 'available'];

    public const SORT_OPTIONS = ['latest', 'oldest', 'price_lowest', 'price_highest', 'name_asc', 'name_desc'];

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

    /**
     * Membatasi katalog buyer ke produk yang masih dapat dibeli.
     */
    public function scopePurchasable(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('stock'), '>', 0);
    }

    /**
     * Memfilter produk seller berdasarkan kelompok kondisi stok yang tidak tumpang tindih.
     */
    public function scopeWithStockCondition(Builder $query, string $stockFilter): Builder
    {
        $stockColumn = $this->qualifyColumn('stock');

        return match ($stockFilter) {
            'healthy', 'available' => $query->where($stockColumn, '>', 5),
            'low' => $query->whereBetween($stockColumn, [1, 5]),
            'empty' => $query->where($stockColumn, '<=', 0),
            default => $query,
        };
    }

    /**
     * Mengurutkan daftar produk dengan kontrak yang sama untuk buyer dan seller.
     */
    public function scopeWithProductSort(Builder $query, string $sortProduct): Builder
    {
        [$column, $direction] = match ($sortProduct) {
            'oldest' => ['updated_at', 'ASC'],
            'price_lowest' => ['price', 'ASC'],
            'price_highest' => ['price', 'DESC'],
            'name_asc' => ['name', 'ASC'],
            'name_desc' => ['name', 'DESC'],
            default => ['updated_at', 'DESC'],
        };

        // UUID menjadi tie-breaker agar urutan infinite scroll stabil ketika nilai utama sama.
        return $query
            ->orderBy($this->qualifyColumn($column), $direction)
            ->orderBy($this->qualifyColumn('id'));
    }
}
