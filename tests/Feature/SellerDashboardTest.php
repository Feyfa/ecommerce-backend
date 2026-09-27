<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\TransactionInvoice;
use App\Models\TransactionProduct;
use App\Models\TransactionUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Memverifikasi kontrak respons dan batas kepemilikan dashboard seller.
 */
class SellerDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    /**
     * Menyiapkan seller dan waktu tetap agar metrik harian serta bulanan dapat diuji konsisten.
     *
     * @return void Fixture autentikasi dan waktu tersedia bagi setiap skenario.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00', 'Asia/Jakarta'));
        $this->seller = User::factory()->create();
        $this->actingAs($this->seller);
    }

    /**
     * Memastikan seller tanpa produk dan transaksi menerima seluruh bagian respons dengan metrik nol.
     *
     * @return void Struktur respons dan deret performa kosong dibuktikan melalui assertion.
     */
    public function test_empty_seller_dashboard_preserves_the_response_shape(): void
    {
        $response = $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('summary.total_products', 0)
            ->assertJsonPath('summary.new_orders', 0)
            ->assertJsonPath('summary.total_sold', 0)
            ->assertJsonPath('performance.period', '30_days')
            ->assertJsonCount(0, 'recent_transactions')
            ->assertJsonPath('product_snapshot.active_products', 0)
            ->assertJsonPath('product_snapshot.low_stock_products', 0)
            ->assertJsonPath('product_snapshot.empty_stock_products', 0)
            ->assertJsonPath('product_snapshot.new_products', 0);

        $this->assertEquals(0, $response->json('summary.monthly_revenue'));
        $this->assertCount(30, $response->json('performance.labels'));
        $this->assertSame(array_fill(0, 30, 0), $response->json('performance.sales'));
        $this->assertEquals(array_fill(0, 30, 0), $response->json('performance.revenue'));
        $this->assertSame(0, $response->json('performance.total_sold'));
        $this->assertEquals(0, $response->json('performance.total_revenue'));
    }

    /**
     * Memastikan alias buyer dan status invoice nullable terbaca pada lima transaksi terbaru milik seller.
     *
     * @return void Urutan, batas hasil, dan kepemilikan transaksi dibuktikan melalui assertion.
     */
    public function test_recent_transactions_keep_joined_aliases_and_seller_scope(): void
    {
        // --- step 1 - start - siapkan transaksi seller dan satu transaksi toko lain
        $buyer = User::factory()->create(['name' => 'Buyer Dashboard']);
        $invoice = TransactionInvoice::create(['user_id_buyer' => $buyer->id, 'status' => null]);
        $product = $this->createProduct($this->seller, 'Produk Dashboard', 10, 10000);
        $transactionIds = [];

        for ($index = 0; $index < 6; $index++) {
            $transaction = $this->createTransaction(
                $this->seller,
                $buyer,
                $invoice,
                $product,
                'approved_seller',
                Carbon::now('Asia/Jakarta')->subMinutes(6 - $index)
            );
            $transactionIds[] = $transaction->id;

            if ($index === 5) {
                $transaction->transaction_number = null;
                $transaction->save();
            }
        }

        $otherSeller = User::factory()->create();
        $otherProduct = $this->createProduct($otherSeller, 'Produk Seller Lain', 10, 20000);
        $otherTransaction = $this->createTransaction(
            $otherSeller,
            $buyer,
            $invoice,
            $otherProduct,
            'approved_seller',
            Carbon::now('Asia/Jakarta')
        );
        // --- step 1 - end - siapkan transaksi seller dan satu transaksi toko lain

        // --- step 2 - start - periksa proyeksi hasil join dan batas lima transaksi
        $response = $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonCount(5, 'recent_transactions');
        $recentTransactions = $response->json('recent_transactions');

        $this->assertSame(array_reverse(array_slice($transactionIds, 1)), array_column($recentTransactions, 'id'));
        $this->assertNotContains($otherTransaction->id, array_column($recentTransactions, 'id'));
        $this->assertSame('Buyer Dashboard', $recentTransactions[0]['buyer_name']);
        $this->assertNull($recentTransactions[0]['transaction_number']);
        $this->assertNull($recentTransactions[0]['invoice_status']);
        $this->assertSame('Produk Dashboard', $recentTransactions[0]['product_names']);
        $this->assertSame('approved_seller', $recentTransactions[0]['status']);
        $this->assertEquals(10000.0, $recentTransactions[0]['total_price']);
        // --- step 2 - end - periksa proyeksi hasil join dan batas lima transaksi
    }

    /**
     * Memastikan metrik hanya memakai transaksi dan produk seller dengan status bisnis yang sesuai.
     *
     * @return void Ringkasan, performa, dan snapshot produk dibuktikan melalui assertion.
     */
    public function test_metrics_keep_completed_and_paid_transaction_rules(): void
    {
        // --- step 1 - start - siapkan produk serta kombinasi status transaksi
        $buyer = User::factory()->create();
        $activeProduct = $this->createProduct($this->seller, 'Produk Aktif', 10, 10000);
        $lowStockProduct = $this->createProduct($this->seller, 'Produk Stok Rendah', 3, 15000);
        $emptyProduct = $this->createProduct($this->seller, 'Produk Habis', 0, 5000);
        $paidInvoice = TransactionInvoice::create(['user_id_buyer' => $buyer->id, 'status' => 'done']);
        $pendingInvoice = TransactionInvoice::create(['user_id_buyer' => $buyer->id, 'status' => 'pending']);
        $createdAt = Carbon::now('Asia/Jakarta')->subHour();

        $this->createTransaction($this->seller, $buyer, $paidInvoice, $activeProduct, 'done', $createdAt, 2);
        $this->createTransaction($this->seller, $buyer, $paidInvoice, $lowStockProduct, 'approved_seller', $createdAt);
        $this->createTransaction($this->seller, $buyer, $pendingInvoice, $emptyProduct, 'done', $createdAt);

        $otherSeller = User::factory()->create();
        $otherProduct = $this->createProduct($otherSeller, 'Produk Seller Lain', 10, 25000);
        $this->createTransaction($otherSeller, $buyer, $paidInvoice, $otherProduct, 'done', $createdAt);
        // --- step 1 - end - siapkan produk serta kombinasi status transaksi

        // --- step 2 - start - periksa metrik yang mengikuti status dan kepemilikan
        $response = $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('summary.total_products', 3)
            ->assertJsonPath('summary.new_orders', 1)
            ->assertJsonPath('summary.total_sold', 2)
            ->assertJsonPath('performance.total_sold', 2)
            ->assertJsonPath('product_snapshot.active_products', 2)
            ->assertJsonPath('product_snapshot.low_stock_products', 1)
            ->assertJsonPath('product_snapshot.empty_stock_products', 1)
            ->assertJsonPath('product_snapshot.new_products', 3)
            ->assertJsonCount(3, 'recent_transactions');

        $this->assertEquals(20000.0, $response->json('summary.monthly_revenue'));
        $this->assertSame(2, $response->json('performance.sales.29'));
        $this->assertEquals(20000.0, $response->json('performance.revenue.29'));
        $this->assertEquals(20000.0, $response->json('performance.total_revenue'));
        // --- step 2 - end - periksa metrik yang mengikuti status dan kepemilikan
    }

    /**
     * Membuat produk dengan harga dan stok yang dapat digunakan sebagai fixture metrik seller.
     *
     * @param  User  $seller  Pemilik produk yang akan dihitung pada dashboard.
     * @param  string  $name  Nama produk yang muncul pada daftar transaksi terbaru.
     * @param  int  $stock  Stok produk untuk pengelompokan snapshot.
     * @param  int  $price  Harga satuan produk untuk transaksi fixture.
     *
     * @return Product Produk seller yang tersimpan sebagai sumber transaksi.
     */
    private function createProduct(User $seller, string $name, int $stock, int $price): Product
    {
        return Product::create([
            'user_id_seller' => $seller->id,
            'name' => $name,
            'stock' => $stock,
            'price' => $price,
        ]);
    }

    /**
     * Membuat satu transaksi seller beserta itemnya untuk menguji proyeksi dan agregasi dashboard.
     *
     * @param  User  $seller  Seller pemilik transaksi.
     * @param  User  $buyer  Buyer yang namanya diproyeksikan melalui join.
     * @param  TransactionInvoice  $invoice  Invoice dengan status pembayaran yang diuji.
     * @param  Product  $product  Produk yang dicantumkan dalam transaksi.
     * @param  string  $status  Status bisnis transaksi seller.
     * @param  Carbon  $createdAt  Waktu transaksi untuk urutan dan agregasi harian.
     * @param  int  $quantity  Jumlah unit produk pada transaksi.
     *
     * @return TransactionUser Transaksi yang tersimpan dengan satu item produk.
     */
    private function createTransaction(
        User $seller,
        User $buyer,
        TransactionInvoice $invoice,
        Product $product,
        string $status,
        Carbon $createdAt,
        int $quantity = 1
    ): TransactionUser {
        $price = (int) $product->price;
        $transaction = TransactionUser::create([
            'user_id_seller' => $seller->id,
            'user_id_buyer' => $buyer->id,
            'transaction_invoice_id' => $invoice->id,
            'transaction_number' => 'dashboard-'.Str::uuid(),
            'product_price' => $price * $quantity,
            'status' => $status,
        ]);
        $transaction->created_at = $createdAt->copy()->timezone('UTC');
        $transaction->save();

        TransactionProduct::create([
            'user_id_seller' => $seller->id,
            'user_id_buyer' => $buyer->id,
            'product_id' => $product->id,
            'transaction_user_id' => $transaction->id,
            'price' => $price,
            'total' => $quantity,
        ]);

        return $transaction;
    }
}
