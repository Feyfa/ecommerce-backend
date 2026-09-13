<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

/**
 * Membentuk, memvalidasi, dan menerapkan cursor keyset untuk daftar produk seller.
 *
 * Cursor dienkripsi agar detail posisi tidak menjadi kontrak yang dapat dimodifikasi client.
 * Payload memakai hash berukuran tetap untuk mengikat posisi ke seller serta kriteria aktif.
 */
class SellerProductCursorService
{
    private const VERSION = 1;

    private const PRIMARY_VALUE_ATTRIBUTE = 'cursor_primary_value';

    /**
     * Membentuk cursor opaque dari produk terakhir pada batch yang masih memiliki kelanjutan.
     *
     * @param  Product  $product  Produk terakhir yang menjadi batas batch saat ini.
     * @param  string  $sellerId  UUID seller pemilik seluruh produk pada pagination.
     * @param  string  $searchProduct  Keyword pencarian yang telah dibersihkan dari whitespace luar.
     * @param  string  $stockFilter  Filter kondisi stok aktif.
     * @param  string  $sortProduct  Urutan produk aktif.
     *
     * @return string Token cursor terenkripsi untuk request batch berikutnya.
     *
     * @throws JsonException Ketika payload internal tidak dapat diencode sebagai JSON.
     */
    public function encode(
        Product $product,
        string $sellerId,
        string $searchProduct,
        string $stockFilter,
        string $sortProduct,
    ): string {
        $payload = [
            'version' => self::VERSION,
            'criteria_hash' => $this->criteriaHash(
                $sellerId,
                $searchProduct,
                $stockFilter,
                $sortProduct,
            ),
            'position' => [
                'value' => $this->primaryValue($product, $sortProduct),
                'id' => (string) $product->id,
            ],
        ];

        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Mendekripsi cursor dan memastikan payload hanya berlaku untuk seller serta kriteria aktif.
     *
     * Cursor rusak, dimodifikasi, memakai versi lain, atau berasal dari query berbeda ditolak
     * sebagai validation error yang aman tanpa membuka isi maupun detail enkripsi.
     *
     * @param  string  $cursor  Token cursor opaque dari response sebelumnya.
     * @param  string  $sellerId  UUID seller yang sedang meminta daftar produk.
     * @param  string  $searchProduct  Keyword pencarian yang telah dibersihkan dari whitespace luar.
     * @param  string  $stockFilter  Filter kondisi stok aktif.
     * @param  string  $sortProduct  Urutan produk aktif.
     *
     * @return array{value: float|string|null, id: string} Posisi keyset tervalidasi untuk query berikutnya.
     *
     * @throws ValidationException Ketika cursor tidak valid atau tidak kompatibel dengan request.
     */
    public function decode(
        string $cursor,
        string $sellerId,
        string $searchProduct,
        string $stockFilter,
        string $sortProduct,
    ): array {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->throwInvalidCursor();
        }

        $expectedCriteriaHash = $this->criteriaHash(
            $sellerId,
            $searchProduct,
            $stockFilter,
            $sortProduct,
        );

        if (! is_array($payload)
            || array_keys($payload) !== ['version', 'criteria_hash', 'position']
            || $payload['version'] !== self::VERSION
            || ! is_string($payload['criteria_hash'])
            || ! hash_equals($expectedCriteriaHash, $payload['criteria_hash'])
            || ! is_array($payload['position'])
            || array_keys($payload['position']) !== ['value', 'id']
            || ! is_string($payload['position']['id'])
            || ! Str::isUuid($payload['position']['id'])
            || ! $this->isValidPrimaryValue($payload['position']['value'], $sortProduct)) {
            $this->throwInvalidCursor();
        }

        return $payload['position'];
    }

    /**
     * Membentuk fingerprint berukuran tetap untuk seller dan kriteria request yang telah divalidasi.
     *
     * Setiap nilai diberi prefix panjang agar kombinasi input tidak ambigu. Hash disimpan di dalam
     * cursor terenkripsi sehingga frontend tetap memperlakukan seluruh token sebagai data opaque.
     *
     * @param  string  $sellerId  UUID seller yang memiliki katalog.
     * @param  string  $searchProduct  Keyword pencarian yang telah dibersihkan dari whitespace luar.
     * @param  string  $stockFilter  Filter kondisi stok aktif.
     * @param  string  $sortProduct  Urutan produk aktif.
     *
     * @return string Hash SHA-256 hexadecimal yang mewakili seller dan seluruh kriteria aktif.
     */
    private function criteriaHash(
        string $sellerId,
        string $searchProduct,
        string $stockFilter,
        string $sortProduct,
    ): string {
        $canonicalCriteria = '';

        foreach ([$sellerId, $searchProduct, $stockFilter, $sortProduct] as $value) {
            $canonicalCriteria .= pack('N', strlen($value)).$value;
        }

        return hash('sha256', $canonicalCriteria);
    }

    /**
     * Membatasi query agar dimulai setelah posisi cursor menurut arah sorting aktif.
     *
     * Nilai null selalu ditempatkan paling akhir. Setelah cursor mencapai kelompok null, UUID tetap
     * menjadi tie-breaker agar setiap row hanya memiliki satu posisi deterministik.
     *
     * @param  Builder  $query  Query produk seller yang telah menerima ownership dan filter aktif.
     * @param  array{value: float|string|null, id: string}|null  $position  Posisi cursor atau null untuk batch pertama.
     * @param  string  $sortProduct  Urutan produk aktif yang menentukan operator pembanding.
     *
     * @return Builder Query yang telah dibatasi ke data setelah cursor.
     */
    public function applyBoundary(Builder $query, ?array $position, string $sortProduct): Builder
    {
        if ($position === null) {
            return $query;
        }

        [$expression, $direction] = $this->sortDefinition($sortProduct);
        $value = $position['value'];
        $id = $position['id'];

        if ($value === null) {
            return $query->where(function (Builder $boundary) use ($expression, $id): void {
                $boundary->whereRaw("{$expression} IS NULL")
                    ->where('products.id', '>', $id);
            });
        }

        $operator = $direction === 'ASC' ? '>' : '<';

        return $query->where(function (Builder $boundary) use ($expression, $operator, $value, $id): void {
            $boundary->whereRaw("{$expression} {$operator} ?", [$value])
                ->orWhereRaw("{$expression} IS NULL")
                ->orWhere(function (Builder $tie) use ($expression, $value, $id): void {
                    $tie->whereRaw("{$expression} = ?", [$value])
                        ->where('products.id', '>', $id);
                });
        });
    }

    /**
     * Menerapkan primary sort dan UUID tie-breaker yang sama dengan kontrak cursor.
     *
     * @param  Builder  $query  Query produk seller yang akan diurutkan.
     * @param  string  $sortProduct  Pilihan sorting yang telah divalidasi controller.
     *
     * @return Builder Query dengan urutan deterministik dan nilai null di posisi terakhir.
     */
    public function applyOrder(Builder $query, string $sortProduct): Builder
    {
        [$expression, $direction] = $this->sortDefinition($sortProduct);

        if (in_array($sortProduct, ['name_asc', 'name_desc'], true)) {
            // The cursor must reuse the exact database value used by ORDER BY. PHP Unicode
            // lowercasing can produce a different boundary and skip or repeat products.
            $query->addSelect('products.*')->selectRaw(
                "{$expression} AS ".self::PRIMARY_VALUE_ATTRIBUTE,
            );
        }

        return $query
            ->orderByRaw("{$expression} {$direction} NULLS LAST")
            ->orderBy('products.id');
    }

    /**
     * Menyembunyikan atribut primary cursor internal dari representasi response produk.
     *
     * @param  Product  $product  Produk hasil query yang mungkin memuat atribut internal cursor.
     *
     * @return Product Produk yang tidak mengekspos atribut internal saat diserialisasi.
     */
    public function hideInternalAttribute(Product $product): Product
    {
        return $product->makeHidden(self::PRIMARY_VALUE_ATTRIBUTE);
    }

    /**
     * Mengambil nilai primary sort dari produk terakhir dengan representasi stabil untuk cursor.
     *
     * @param  Product  $product  Produk terakhir yang menjadi posisi cursor.
     * @param  string  $sortProduct  Urutan aktif yang menentukan atribut posisi.
     *
     * @return float|string|null Nilai primary sort yang siap disimpan dalam payload.
     */
    private function primaryValue(Product $product, string $sortProduct): float|string|null
    {
        return match ($sortProduct) {
            'price_lowest', 'price_highest' => $product->price === null ? null : (float) $product->price,
            'name_asc', 'name_desc' => $product->getAttribute(self::PRIMARY_VALUE_ATTRIBUTE),
            default => $product->getRawOriginal('updated_at') === null
                ? null
                : (string) $product->getRawOriginal('updated_at'),
        };
    }

    /**
     * Menentukan expression database dan arah primary sort untuk seluruh pilihan Seller Product.
     *
     * @param  string  $sortProduct  Pilihan sorting tervalidasi dari request.
     *
     * @return array{string, 'ASC'|'DESC'} Expression aman dan arah sorting yang sesuai kontrak.
     */
    private function sortDefinition(string $sortProduct): array
    {
        return match ($sortProduct) {
            'oldest' => ['products.updated_at', 'ASC'],
            'price_lowest' => ['products.price', 'ASC'],
            'price_highest' => ['products.price', 'DESC'],
            'name_asc' => ['LOWER(products.name)', 'ASC'],
            'name_desc' => ['LOWER(products.name)', 'DESC'],
            default => ['products.updated_at', 'DESC'],
        };
    }

    /**
     * Memastikan nilai primary cursor sesuai tipe yang digunakan kelompok sorting aktif.
     *
     * @param  mixed  $value  Nilai primary yang dibaca dari payload cursor.
     * @param  string  $sortProduct  Urutan aktif yang menentukan tipe nilai yang diizinkan.
     *
     * @return bool True ketika nilai aman dipakai sebagai parameter keyset.
     */
    private function isValidPrimaryValue(mixed $value, string $sortProduct): bool
    {
        if ($value === null) {
            return true;
        }

        return match ($sortProduct) {
            'price_lowest', 'price_highest' => is_float($value) || is_int($value),
            default => is_string($value),
        };
    }

    /**
     * Menghentikan pemrosesan cursor dengan validation error generik yang aman untuk client.
     *
     * @return never Method selalu melempar validation exception dan tidak kembali ke pemanggil.
     *
     * @throws ValidationException Selalu dilempar untuk cursor yang tidak dapat dipercaya.
     */
    private function throwInvalidCursor(): never
    {
        throw ValidationException::withMessages([
            'cursor' => ['The cursor field is invalid.'],
        ]);
    }
}
