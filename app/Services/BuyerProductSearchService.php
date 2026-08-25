<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Arr;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;

/**
 * Mengelola index katalog buyer yang merupakan proyeksi turunan PostgreSQL.
 *
 * Service ini menjadi satu-satunya tempat yang membentuk dokumen, menerapkan
 * konfigurasi index, dan menerjemahkan query katalog ke Meilisearch.
 */
class BuyerProductSearchService
{
    /**
     * Menyiapkan dependency untuk mengelola proyeksi katalog buyer dan aturan ketersediaannya.
     *
     * @param  Client  $client  Client Meilisearch yang menjalankan operasi index dan pencarian.
     * @param  ProductAvailabilityService  $availabilityService  Service aturan produk yang boleh ditampilkan ke buyer.
     */
    public function __construct(private Client $client, private ProductAvailabilityService $availabilityService) {}

    /**
     * Menerapkan pengaturan index yang dimiliki aplikasi, termasuk sinonim dan ranking.
     *
     * @return void Pengaturan aplikasi diterapkan langsung ke index katalog buyer.
     */
    public function configureIndex(): void
    {
        $index = $this->index();
        $task = $index->updateSettings((array) config('buyer_product_search.settings'));

        $this->client->waitForTask($task['taskUid']);
    }

    /**
     * Menghapus seluruh dokumen proyeksi sebelum katalog dibangun ulang dari PostgreSQL.
     *
     * Operasi hanya membersihkan Meilisearch dan tidak memutasi produk atau transaksi PostgreSQL.
     * Command reindex akan melanjutkannya dengan mengantrekan state katalog terkini.
     *
     * @return void Seluruh dokumen pada index katalog buyer dihapus setelah task Meilisearch selesai.
     */
    public function clearIndex(): void
    {
        $task = $this->index()->deleteAllDocuments();

        $this->client->waitForTask($task['taskUid']);
    }

    /**
     * Menyinkronkan satu produk dari PostgreSQL ke index atau menghapusnya bila tak layak tampil.
     *
     * Model dimuat ulang saat job berjalan agar retry maupun job duplikat selalu memproyeksikan
     * keadaan database terbaru, bukan snapshot ketika job dikirimkan.
     *
     * @param  string  $productId  ID produk yang akan diproyeksikan.
     *
     * @return void Dokumen produk disamakan atau dihapus dari Meilisearch sesuai state terbaru.
     */
    public function syncProduct(string $productId): void
    {
        $product = Product::withTrashed()->with(['seller.company'])->find($productId);

        if (! $product || $product->trashed() || ! $this->isPurchasable($product)) {
            $this->removeProduct($productId);

            return;
        }

        $task = $this->index()->addDocuments([$this->document($product)], 'id');
        $this->client->waitForTask($task['taskUid']);
    }

    /**
     * Menghapus satu dokumen produk secara idempoten dari index katalog buyer.
     *
     * @param  string  $productId  ID dokumen produk yang akan dihapus.
     *
     * @return void Dokumen produk dihapus setelah task Meilisearch selesai.
     */
    public function removeProduct(string $productId): void
    {
        $task = $this->index()->deleteDocument($productId);

        $this->client->waitForTask($task['taskUid']);
    }

    /**
     * Mencari dokumen katalog buyer menggunakan keyword, filter, sorting, dan pagination bernomor.
     *
     * Query meminta satu dokumen tambahan sebagai lookahead agar keberadaan halaman berikutnya
     * ditentukan dari hit aktual, bukan dari estimated total Meilisearch.
     *
     * @param  string  $buyerId  ID buyer yang produk miliknya harus dikecualikan.
     * @param  array<string, int|string|null>  $filters  Filter tervalidasi dari endpoint katalog.
     * @param  int  $page  Nomor halaman yang diminta, dimulai dari satu.
     * @param  int  $perPage  Jumlah dokumen maksimum per halaman.
     *
     * @return array{products: array<int, array<string, mixed>>, page: int, per_page: int, has_more: bool, limit_reached: bool} Halaman katalog beserta metadata pagination dan boundary.
     */
    public function search(string $buyerId, array $filters, int $page, int $perPage): array
    {
        $searchProduct = trim((string) ($filters['search_product'] ?? ''));
        $sortProduct = (string) ($filters['sort_product'] ?? 'latest');
        $maxTotalHits = max(1, (int) config('buyer_product_search.max_total_hits'));
        $offset = ($page - 1) * $perPage;

        if ($offset >= $maxTotalHits) {
            return [
                'products' => [],
                'page' => $page,
                'per_page' => $perPage,
                'has_more' => false,
                'limit_reached' => true,
            ];
        }

        $searchParameters = [
            'filter' => $this->filters($buyerId, $filters),
            'offset' => $offset,
            'limit' => min($perPage + 1, $maxTotalHits - $offset),
        ];

        $sort = $this->sort($sortProduct, $searchProduct !== '');

        if ($sort !== null) {
            $searchParameters['sort'] = $sort;
        }

        $result = $this->index()->search($searchProduct, $searchParameters);
        $hits = $result->getHits();
        $products = array_slice($hits, 0, $perPage);
        $hasMore = count($hits) > $perPage;
        $limitReached = ! $hasMore && ($offset + count($products)) >= $maxTotalHits;

        return [
            'products' => $products,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasMore,
            'limit_reached' => $limitReached,
        ];
    }

    /**
     * Menghasilkan dokumen katalog yang hanya berisi data aman untuk halaman buyer.
     *
     * @param  Product  $product  Produk aktif dengan relasi seller dan company yang sudah dimuat.
     *
     * @return array<string, bool|int|string|null> Dokumen yang dikirim ke Meilisearch.
     */
    public function document(Product $product): array
    {
        $seller = $product->seller;
        $companyName = trim((string) optional($seller?->company)->name);
        $storeName = $companyName !== '' ? $companyName : (string) optional($seller)->name;

        return [
            'id' => (string) $product->id,
            'p_id' => (string) $product->id,
            'seller_id' => (string) $product->user_id_seller,
            'u_id' => (string) $product->user_id_seller,
            'p_name' => (string) $product->name,
            'p_name_sort' => mb_strtolower((string) $product->name),
            'u_name' => $storeName,
            'p_img' => $product->img,
            'p_price' => (int) $product->price,
            'p_stock' => (int) $product->stock,
            'created_at' => optional($product->created_at)?->toIso8601String(),
            'updated_at' => optional($product->updated_at)?->toIso8601String(),
            'created_at_timestamp' => optional($product->created_at)?->getTimestamp(),
            'updated_at_timestamp' => optional($product->updated_at)?->getTimestamp(),
            'is_purchasable' => true,
        ];
    }

    /**
     * Menentukan apakah produk boleh diproyeksikan ke katalog buyer saat ini.
     *
     * @param  Product  $product  Produk yang kondisi publikasinya diperiksa.
     *
     * @return bool True apabila produk aktif, berstok, dan seller mempunyai lokasi terverifikasi.
     */
    private function isPurchasable(Product $product): bool
    {
        return ! $product->trashed()
            && (int) $product->stock > 0
            && $this->availabilityService->sellerHasVerifiedAddress((string) $product->user_id_seller);
    }

    /**
     * Menyusun filter Meilisearch dari nilai request yang telah divalidasi controller.
     *
     * @param  string  $buyerId  ID buyer peminta katalog.
     * @param  array<string, int|string|null>  $filters  Filter katalog tervalidasi.
     *
     * @return array<int, string> Filter yang harus dipenuhi seluruh dokumen hasil.
     */
    private function filters(string $buyerId, array $filters): array
    {
        $conditions = [
            'is_purchasable = true',
            "seller_id != '".str_replace("'", "\\'", $buyerId)."'",
        ];

        if (Arr::get($filters, 'min_price') !== null) {
            $conditions[] = 'p_price >= '.(int) $filters['min_price'];
        }

        if (Arr::get($filters, 'max_price') !== null) {
            $conditions[] = 'p_price <= '.(int) $filters['max_price'];
        }

        if (Arr::get($filters, 'added_within') !== null) {
            $conditions[] = 'created_at_timestamp >= '.now()->subDays((int) $filters['added_within'])->getTimestamp();
        }

        return $conditions;
    }

    /**
     * Menerjemahkan pilihan urutan UI menjadi field sort Meilisearch yang diizinkan.
     *
     * Keyword dengan mode relevance tidak mengirim sort agar ranking native Meilisearch tetap utama.
     *
     * @param  string  $sortProduct  Nilai sort tervalidasi dari request.
     * @param  bool  $hasKeyword  Penanda apakah request memuat kata kunci.
     *
     * @return array<int, string>|null Urutan sort Meilisearch atau null untuk relevance native.
     */
    private function sort(string $sortProduct, bool $hasKeyword): ?array
    {
        if ($hasKeyword && $sortProduct === 'relevance') {
            return null;
        }

        return match ($sortProduct) {
            'oldest' => ['updated_at_timestamp:asc', 'id:asc'],
            'price_lowest' => ['p_price:asc', 'id:asc'],
            'price_highest' => ['p_price:desc', 'id:asc'],
            'name_asc' => ['p_name_sort:asc', 'id:asc'],
            'name_desc' => ['p_name_sort:desc', 'id:asc'],
            default => ['updated_at_timestamp:desc', 'id:asc'],
        };
    }

    /**
     * Mengambil handle index katalog buyer yang dikonfigurasi aplikasi.
     *
     * @return Indexes Handle index Meilisearch aktif.
     */
    private function index(): Indexes
    {
        return $this->client->index((string) config('buyer_product_search.index'));
    }
}
