<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\TransactionInvoice;
use App\Models\TransactionProduct;
use App\Models\TransactionUser;
use App\Models\User;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Memastikan buyer melihat satu kewajiban pembayaran untuk invoice yang mencakup beberapa toko.
     *
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_buyer_pending_transactions_are_grouped_by_invoice_with_all_store_packages(): void
    {
        // --- step 1 - start - siapkan satu invoice pending dengan dua paket toko
        $fixture = $this->createMultiStoreInvoiceFixture();
        // --- step 1 - end - siapkan satu invoice pending dengan dua paket toko

        // --- step 2 - start - ambil daftar pending dari perspektif buyer
        $response = app(TransactionService::class)->getTransaction(
            $fixture['buyer']->id,
            'buyer',
            ['status' => 'pending_payment', 'search' => 'Produk Kedua']
        );
        // --- step 2 - end - ambil daftar pending dari perspektif buyer

        // --- step 3 - start - pastikan invoice dan seluruh paket toko tetap utuh
        $this->assertSame(1, $response['counts']['pending_payment']);
        $this->assertCount(1, $response['transactions']);
        $this->assertSame($fixture['invoice']->id, $response['transactions']->first()->invoice_id);
        $this->assertSame($fixture['invoice']->id, $response['transactions']->first()->id);
        $this->assertSame(352000.0, (float) $response['transactions']->first()->total_price);
        $this->assertCount(2, $response['transactions']->first()->packages);
        $packagesBySeller = $response['transactions']->first()->packages->keyBy('seller_name');
        $this->assertSame('Produk Pertama', $packagesBySeller->get('Toko Pertama')->products->first()->name);
        $this->assertSame('Produk Kedua', $packagesBySeller->get('Toko Kedua')->products->first()->name);
        // --- step 3 - end - pastikan invoice dan seluruh paket toko tetap utuh

        // --- step 4 - start - pastikan transaksi setelah pembayaran tetap terpisah per toko
        $fixture['invoice']->update(['status' => 'done']);

        $paidResponse = app(TransactionService::class)->getTransaction(
            $fixture['buyer']->id,
            'buyer',
            ['status' => 'paid']
        );

        $this->assertCount(2, $paidResponse['transactions']);
        $this->assertSame(2, $paidResponse['counts']['paid']);
        $paidTransactionsBySeller = $paidResponse['transactions']->keyBy('seller_name');
        $this->assertSame(315000.0, (float) $paidTransactionsBySeller->get('Toko Pertama')->total_price);
        $this->assertSame(37000.0, (float) $paidTransactionsBySeller->get('Toko Kedua')->total_price);
        // --- step 4 - end - pastikan transaksi setelah pembayaran tetap terpisah per toko
    }

    /**
     * Memastikan invoice pending satu toko tetap memakai bentuk transaksi lama.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_buyer_single_store_pending_transaction_keeps_the_regular_transaction_shape(): void
    {
        // --- step 1 - start - siapkan satu invoice pending dengan satu paket toko
        $fixture = $this->createSingleStoreInvoiceFixture();
        // --- step 1 - end - siapkan satu invoice pending dengan satu paket toko

        // --- step 2 - start - ambil daftar pending dari perspektif buyer
        $response = app(TransactionService::class)->getTransaction(
            $fixture['buyer']->id,
            'buyer',
            ['status' => 'pending_payment']
        );
        // --- step 2 - end - ambil daftar pending dari perspektif buyer

        // --- step 3 - start - pastikan transaksi satu toko tidak berubah menjadi invoice gabungan
        $transaction = $response['transactions']->first();

        $this->assertSame(1, $response['counts']['pending_payment']);
        $this->assertCount(1, $response['transactions']);
        $this->assertSame($fixture['transaction']->id, $transaction->id);
        $this->assertSame($fixture['invoice']->id, $transaction->invoice_id);
        $this->assertSame('Toko Tunggal', $transaction->seller_name);
        $this->assertSame(25000.0, (float) $transaction->total_price);
        $this->assertArrayNotHasKey('packages', $transaction->getAttributes());
        // --- step 3 - end - pastikan transaksi satu toko tidak berubah menjadi invoice gabungan
    }

    /**
     * Membuat fixture invoice buyer yang memuat dua transaksi seller dan produk berbeda.
     *
     * @return array{buyer: User, invoice: TransactionInvoice} Data buyer dan invoice yang diperlukan test.
     */
    private function createMultiStoreInvoiceFixture(): array
    {
        // --- step 1 - start - buat buyer, seller, dan profil toko
        $buyer = User::factory()->create(['name' => 'Buyer Test']);
        $firstSeller = User::factory()->create(['name' => 'Akun Seller Pertama']);
        $secondSeller = User::factory()->create(['name' => 'Akun Seller Kedua']);
        Company::create(['user_id' => $firstSeller->id, 'name' => 'Toko Pertama']);
        Company::create(['user_id' => $secondSeller->id, 'name' => 'Toko Kedua']);
        // --- step 1 - end - buat buyer, seller, dan profil toko

        // --- step 2 - start - buat invoice dan paket transaksi setiap toko
        $invoice = TransactionInvoice::create([
            'user_id_buyer' => $buyer->id,
            'alamat_buyer' => 'Jakarta',
            'payment_name' => 'BCA Virtual Account',
            'payment_account' => '381659999999999',
            'price' => 352000,
            'status' => 'pending',
            'expired_at' => Carbon::now()->addDay(),
        ]);

        $firstTransaction = $this->createStorePackage($invoice, $buyer, $firstSeller, 'Produk Pertama', 300000, 15000);
        $secondTransaction = $this->createStorePackage($invoice, $buyer, $secondSeller, 'Produk Kedua', 27000, 10000);
        // --- step 2 - end - buat invoice dan paket transaksi setiap toko

        // --- step 3 - start - tetapkan waktu sama agar urutan paket dapat diprediksi
        $createdAt = Carbon::now()->subMinute();
        $firstTransaction->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);
        $secondTransaction->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);
        // --- step 3 - end - tetapkan waktu sama agar urutan paket dapat diprediksi

        return compact('buyer', 'invoice');
    }

    /**
     * Membuat fixture invoice pending yang hanya mencakup satu transaksi seller.
     *
     * @return array{buyer: User, invoice: TransactionInvoice, transaction: TransactionUser} Data yang diperlukan test transaksi satu toko.
     */
    private function createSingleStoreInvoiceFixture(): array
    {
        // --- step 1 - start - buat buyer, seller, dan profil toko tunggal
        $buyer = User::factory()->create(['name' => 'Buyer Toko Tunggal']);
        $seller = User::factory()->create(['name' => 'Akun Seller Tunggal']);
        Company::create(['user_id' => $seller->id, 'name' => 'Toko Tunggal']);
        // --- step 1 - end - buat buyer, seller, dan profil toko tunggal

        // --- step 2 - start - buat invoice dan paket transaksi tunggal
        $invoice = TransactionInvoice::create([
            'user_id_buyer' => $buyer->id,
            'alamat_buyer' => 'Jakarta',
            'payment_name' => 'BCA Virtual Account',
            'payment_account' => '381659999888888',
            'price' => 25000,
            'status' => 'pending',
            'expired_at' => Carbon::now()->addDay(),
        ]);
        $transaction = $this->createStorePackage($invoice, $buyer, $seller, 'Produk Tunggal', 10000, 15000);
        // --- step 2 - end - buat invoice dan paket transaksi tunggal

        return compact('buyer', 'invoice', 'transaction');
    }

    /**
     * Membuat satu paket seller beserta snapshot produk untuk fixture transaksi.
     *
     * @param  TransactionInvoice  $invoice  Invoice induk pembayaran buyer.
     * @param  User  $buyer  Buyer pemilik invoice.
     * @param  User  $seller  Seller pemilik paket.
     * @param  string  $productName  Nama produk snapshot yang dapat dicari buyer.
     * @param  int  $productPrice  Subtotal produk untuk paket seller.
     * @param  int  $shippingPrice  Ongkir untuk paket seller.
     * @return TransactionUser Transaksi seller yang baru dibuat.
     */
    private function createStorePackage(
        TransactionInvoice $invoice,
        User $buyer,
        User $seller,
        string $productName,
        int $productPrice,
        int $shippingPrice
    ): TransactionUser {
        $product = Product::create([
            'user_id_seller' => $seller->id,
            'name' => $productName,
            'price' => $productPrice,
            'stock' => 10,
        ]);
        $transaction = TransactionUser::create([
            'user_id_seller' => $seller->id,
            'user_id_buyer' => $buyer->id,
            'transaction_invoice_id' => $invoice->id,
            'transaction_number' => "transaction-{$seller->id}",
            'kurir_type' => 'JNT',
            'kurir_price' => $shippingPrice,
            'kurir_estimate' => 'Besok',
            'product_price' => $productPrice,
            'noted' => 'Tolong dikirim aman',
        ]);
        TransactionProduct::create([
            'user_id_seller' => $seller->id,
            'user_id_buyer' => $buyer->id,
            'product_id' => $product->id,
            'transaction_user_id' => $transaction->id,
            'price' => $productPrice,
            'total' => 1,
        ]);

        return $transaction;
    }
}
