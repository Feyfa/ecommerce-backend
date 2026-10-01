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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies transaction projections, ownership, pagination, and pending invoice grouping.
 */
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
     * @return void Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
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
     * Keeps regular empty pagination nullable and pending invoice pagination zero-based.
     *
     * @return void Empty buyer and seller lists retain their existing count and pagination shapes.
     */
    public function test_empty_lists_keep_the_existing_response_shapes(): void
    {
        $user = User::factory()->create();
        $service = app(TransactionService::class);

        // --- step 1 - start - verify empty regular buyer and seller pagination
        foreach (['buyer', 'seller'] as $perspective) {
            $response = $service->getTransaction($user->id, $perspective);
            $this->assertSame('success', $response['status']);
            $this->assertCount(0, $response['transactions']);
            $this->assertSame(['all' => 0, 'paid' => 0, 'pending_payment' => 0, 'waiting_seller' => 0, 'done' => 0], $response['counts']);
            $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 5, 'total' => 0, 'from' => null, 'to' => null], $response['pagination']);
        }
        // --- step 1 - end - verify empty regular buyer and seller pagination

        // --- step 2 - start - verify the empty pending invoice action queue
        $pending = $service->getTransaction($user->id, 'buyer', ['status' => 'pending_payment']);
        $this->assertCount(0, $pending['transactions']);
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 0, 'total' => 0, 'from' => 0, 'to' => 0], $pending['pagination']);
        // --- step 2 - end - verify the empty pending invoice action queue
    }

    /**
     * Keeps buyer ownership and seller package isolation, including private payment fields.
     *
     * @return void A foreign buyer receives no rows and each seller sees only its own ungrouped package without the virtual account.
     */
    public function test_ownership_and_seller_payment_field_boundaries_are_preserved(): void
    {
        // --- step 1 - start - prepare one invoice and an unrelated buyer
        $fixture = $this->createMultiStoreInvoiceFixture();
        $service = app(TransactionService::class);
        $foreignBuyer = User::factory()->create();
        // --- step 1 - end - prepare one invoice and an unrelated buyer

        // --- step 2 - start - prevent another buyer from reading any invoice packages
        foreach (['all', 'pending_payment'] as $status) {
            $response = $service->getTransaction($foreignBuyer->id, 'buyer', ['status' => $status]);
            $this->assertCount(0, $response['transactions']);
            $this->assertSame(0, $response['counts']['all']);
        }
        // --- step 2 - end - prevent another buyer from reading any invoice packages

        // --- step 3 - start - restrict each seller to its own package without buyer payment details
        foreach (TransactionUser::where('transaction_invoice_id', $fixture['invoice']->id)->get() as $package) {
            $response = $service->getTransaction($package->user_id_seller, 'seller', ['status' => 'pending_payment']);
            $this->assertCount(1, $response['transactions']);
            $row = $response['transactions']->first();
            $this->assertInstanceOf(TransactionUser::class, $row);
            $this->assertSame($package->id, $row->id);
            $this->assertSame((float) $package->product_price + (float) $package->kurir_price, (float) $row->total_price);
            $this->assertArrayNotHasKey('packages', $row->getAttributes());
            $this->assertArrayNotHasKey('payment_account', $row->getAttributes());
        }
        // --- step 3 - end - restrict each seller to its own package without buyer payment details
    }

    /**
     * Checks that changing projection access preserves dates, products, and one batch product query.
     *
     * @return void Regular rows retain formatted timestamps and product collections without per-row product queries.
     */
    public function test_projection_mapping_preserves_dates_products_and_batch_loading(): void
    {
        // --- step 1 - start - prepare paid packages with explicit timestamps
        $fixture = $this->createMultiStoreInvoiceFixture();
        $fixture['invoice']->update(['status' => 'done', 'expired_at' => '2026-09-02 03:04:00']);
        TransactionUser::where('transaction_invoice_id', $fixture['invoice']->id)
            ->update(['created_at' => '2026-09-01 01:02:00']);
        // --- step 1 - end - prepare paid packages with explicit timestamps

        // --- step 2 - start - capture product query count during response mapping
        DB::enableQueryLog();
        try {
            $response = app(TransactionService::class)->getTransaction($fixture['buyer']->id, 'buyer', ['status' => 'paid']);
            $productQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'from "transaction_products"'));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        // --- step 2 - end - capture product query count during response mapping

        // --- step 3 - start - verify dates, payment details, and products on both rows
        $this->assertCount(1, $productQueries);
        $this->assertCount(2, $response['transactions']);
        foreach ($response['transactions'] as $row) {
            $this->assertSame(Carbon::parse('2026-09-01 01:02:00')->setTimezone('Asia/Jakarta')->translatedFormat('d F Y H:i'), $row->transaction_date);
            $this->assertSame(Carbon::parse('2026-09-02 03:04:00')->setTimezone('Asia/Jakarta')->translatedFormat('d F Y H:i'), $row->expired_at);
            $this->assertSame('381659999999999', $row->payment_account);
            $this->assertCount(1, $row->products);
            $this->assertContains($row->products->first()->name, ['Produk Pertama', 'Produk Kedua']);
        }
        // --- step 3 - end - verify dates, payment details, and products on both rows
    }

    /**
     * Keeps Carbon's existing nullable timestamp parsing rather than introducing a new fallback.
     *
     * @return void A nullable expiry is formatted using Carbon's frozen current time in Asia/Jakarta.
     */
    public function test_nullable_expiry_keeps_the_existing_carbon_behavior(): void
    {
        Carbon::setTestNow('2026-09-30 01:02:00');
        try {
            $fixture = $this->createSingleStoreInvoiceFixture();
            $fixture['invoice']->update(['expired_at' => null]);
            $response = app(TransactionService::class)->getTransaction($fixture['buyer']->id, 'buyer');
            $this->assertSame(Carbon::parse(null)->setTimezone('Asia/Jakarta')->translatedFormat('d F Y H:i'), $response['transactions']->first()->expired_at);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Retains page casts and bounds alongside unknown status, sort, and invalid date fallbacks.
     *
     * @return void Numeric strings and decimals use existing integer pagination while unsupported string filters remain non-fatal.
     */
    public function test_pagination_casts_bounds_and_filter_fallbacks_are_preserved(): void
    {
        // --- step 1 - start - prepare deterministically ordered invoice packages
        $fixture = $this->createMultiStoreInvoiceFixture();
        $packages = TransactionUser::where('transaction_invoice_id', $fixture['invoice']->id)->get();
        // Fixture timestamps are guarded model attributes, so set them explicitly for ordering tests.
        $packages[0]->forceFill(['created_at' => '2026-09-01 00:00:00'])->save();
        $packages[1]->forceFill(['created_at' => '2026-09-02 00:00:00'])->save();
        $service = app(TransactionService::class);
        // --- step 1 - end - prepare deterministically ordered invoice packages

        // --- step 2 - start - verify pagination casts and unsupported string filter fallbacks
        foreach ([['-1.5', '30.5', 1, 20], [0, 0, 1, 1], [1.9, 1.9, 1, 1], ['2', '1', 2, 1]] as [$page, $perPage, $expectedPage, $expectedPerPage]) {
            $response = $service->getTransaction($fixture['buyer']->id, 'buyer', [
                'page' => $page,
                'per_page' => $perPage,
                'status' => 'unknown',
                'sort' => 'unknown',
                'date_from' => 'invalid-date',
                'date_to' => 'invalid-date',
            ]);
            $this->assertSame($expectedPage, $response['pagination']['current_page']);
            $this->assertSame($expectedPerPage, $response['pagination']['per_page']);
            $this->assertSame(2, $response['pagination']['total']);
            $this->assertSame(
                $expectedPage == 1 ? $packages[1]->id : $packages[0]->id,
                $response['transactions']->first()->id,
                'Unexpected ordering for page '.json_encode($page).' and per_page '.json_encode($perPage)
            );
        }
        // --- step 2 - end - verify pagination casts and unsupported string filter fallbacks

        // --- step 3 - start - verify explicit ascending order and a valid date range
        $oldest = $service->getTransaction($fixture['buyer']->id, 'buyer', ['sort' => 'oldest', 'per_page' => 1]);
        $this->assertSame($packages[0]->id, $oldest['transactions']->first()->id);
        $dated = $service->getTransaction($fixture['buyer']->id, 'buyer', ['date_from' => '2026-09-02', 'date_to' => '2026-09-02']);
        $this->assertCount(1, $dated['transactions']);
        $this->assertSame($packages[1]->id, $dated['transactions']->first()->id);
        // --- step 3 - end - verify explicit ascending order and a valid date range
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
     *
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
