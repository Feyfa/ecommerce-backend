<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public const STOCK_FILTER_OPTIONS = ['all', 'healthy', 'low', 'empty', 'available'];

    public const SORT_OPTIONS = ['latest', 'oldest', 'price_lowest', 'price_highest', 'name_asc', 'name_desc'];

    protected $fillable = [
        'user_id_seller',
        'img',
        'name',
        'price',
        'stock',
    ];

    /**
     * Mengambil seller pemilik produk.
     *
     * @return BelongsTo  Relasi Eloquent menuju model induk yang terkait.
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id_seller');
    }

    /**
     * Mengambil seluruh item keranjang yang merujuk produk ini.
     *
     * @return HasMany  Relasi Eloquent menuju seluruh model turunan yang terkait.
     */
    public function keranjangs(): HasMany
    {
        return $this->hasMany(Keranjang::class, 'product_id');
    }

    /**
     * Mengambil seluruh gambar produk berdasarkan urutan tampil.
     *
     * @return HasMany  Relasi Eloquent menuju seluruh model turunan yang terkait.
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    /**
     * Mengambil seluruh item transaksi yang merujuk produk ini.
     *
     * @return HasMany  Relasi Eloquent menuju seluruh model turunan yang terkait.
     */
    public function transactionProducts(): HasMany
    {
        return $this->hasMany(TransactionProduct::class, 'product_id');
    }

    /**
     * Membatasi katalog buyer ke produk yang masih dapat dibeli.
     *
     * Scope ini mensyaratkan stok positif dan alamat seller yang aktif serta terverifikasi melalui
     * pinpoint. Produk tanpa lokasi seller yang lengkap dikeluarkan sejak level query agar katalog,
     * keranjang, dan checkout memakai definisi ketersediaan yang sama.
     *
     * @param  Builder  $query  Query Eloquent yang akan ditambahkan kondisi tanpa dieksekusi langsung.
     *
     * @return Builder  Query builder yang telah ditambahkan scope atau kondisi terkait.
     */
    public function scopePurchasable(Builder $query): Builder
    {
        return $query
            ->where($this->qualifyColumn('stock'), '>', 0)
            ->whereExists(function ($addressQuery) {
                $addressQuery->selectRaw('1')
                    ->from('alamats')
                    ->whereColumn('alamats.user_id', $this->qualifyColumn('user_id_seller'))
                    ->where('alamats.type', 'seller')
                    ->where('alamats.enable', 1)
                    ->where('alamats.location_source', 'map')
                    ->whereNotNull('alamats.latitude')
                    ->whereNotNull('alamats.longitude')
                    ->whereNotNull('alamats.formatted_address')
                    ->where('alamats.formatted_address', '<>', '')
                    ->whereNotNull('alamats.address_detail')
                    ->where('alamats.address_detail', '<>', '');
            });
    }

    /**
     * Memfilter produk seller berdasarkan kelompok kondisi stok yang tidak tumpang tindih.
     *
     * Filter membagi stok menjadi kelompok sehat, rendah, dan kosong yang tidak tumpang tindih. Nilai
     * filter yang tidak dikenali dibiarkan menggunakan query awal supaya pemanggil dapat menerapkan
     * default secara eksplisit.
     *
     * @param  Builder  $query  Query Eloquent yang akan ditambahkan kondisi tanpa dieksekusi langsung.
     * @param  string  $stockFilter  Kelompok kondisi stok yang dipilih seller.
     *
     * @return Builder  Query builder yang telah ditambahkan scope atau kondisi terkait.
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
     *
     * Pilihan urutan diterjemahkan ke kolom dan arah yang telah diizinkan, lalu ID dipakai sebagai
     * tie-breaker agar cursor pagination menghasilkan urutan stabil.
     *
     * @param  Builder  $query  Query Eloquent yang akan ditambahkan kondisi tanpa dieksekusi langsung.
     * @param  string  $sortProduct  Pilihan urutan produk yang telah divalidasi.
     *
     * @return Builder  Query builder yang telah ditambahkan scope atau kondisi terkait.
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
