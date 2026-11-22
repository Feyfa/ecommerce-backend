<?php

namespace App\Http\Controllers;

use App\Exceptions\CheckoutAvailabilityException;
use App\Exceptions\CheckoutChangedException;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\OutboxRecorderService;
use App\Services\PaymentService;
use App\Services\XenditService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class CheckoutController extends Controller
{
    /**
     * Menyiapkan controller dengan layanan checkout dan ketersediaan produk.
     *
     * @param  CheckoutService  $checkoutService  Layanan penyusunan checkout.
     * @param  XenditService  $xenditService  Layanan integrasi pembayaran Xendit.
     * @param  PaymentService  $paymentService  Layanan metode pembayaran.
     * @param  OutboxRecorderService  $outboxRecorder  Recorder durable untuk perubahan stok katalog buyer.
     */
    public function __construct(
        protected CheckoutService $checkoutService,
        protected XenditService $xenditService,
        protected PaymentService $paymentService,
        protected OutboxRecorderService $outboxRecorder,
    ) {}

    /**
     * Mengambil data checkout terkini untuk buyer yang terautentikasi.
     *
     * Rekonsiliasi cart dan pemeriksaan alamat buyer serta seller dilakukan sebelum opsi
     * pengiriman dan pembayaran ditampilkan.
     *
     * @return JsonResponse Alamat, paket checkout, metode pembayaran, dan total harga produk.
     */
    public function getDataCheckout(): JsonResponse
    {
        // --- step 1 - validasi buyer terautentikasi - start ---
        // Model user lokal harus masih ada sebelum rekonsiliasi dapat mengubah pilihan cart.
        $user = auth()->user();
        $userExists = $user instanceof User && User::where('id', $user->id)->exists();
        // --- step 1 - validasi buyer terautentikasi - end ---

        if (! $userExists) {
            return response()->json(['result' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $user_id_buyer = $user->id;

        // --- step 2 - sinkronkan availability item checkout - start ---
        $cartState = $this->checkoutService->reconcileCheckoutCart($user_id_buyer);
        $availabilityError = $this->checkoutService->checkoutAvailabilityError($cartState);

        if ($availabilityError !== null) {
            return response()->json([
                'status' => 'error',
                ...$availabilityError,
            ], 409);
        }
        // --- step 2 - sinkronkan availability item checkout - end ---

        // --- step 3 - validasi alamat aktif buyer - start ---
        $getAlamatBuyer = $this->checkoutService->getAlamatBuyer($user_id_buyer);
        $alamat = $getAlamatBuyer['alamat'];

        if (empty($alamat)) {
            return response()->json(['status' => 'error', 'message' => 'Alamat belum ditambahkan'], 400);
        }

        if (! $this->checkoutService->isAddressVerified($alamat)) {
            return response()->json([
                'status' => 'error',
                'code' => 'ADDRESS_REQUIRES_VERIFICATION',
                'message' => 'Alamat pengiriman perlu diverifikasi dengan pinpoint sebelum melanjutkan checkout.',
            ], 409);
        }
        // --- step 3 - validasi alamat aktif buyer - end ---

        // --- step 4 - ambil dan validasi kelompok item checkout - start ---
        $getKeranjangCheckout = $this->checkoutService->getKeranjangCheckout($user_id_buyer);
        $checkouts = $getKeranjangCheckout['checkouts'];
        $totalPrice = $getKeranjangCheckout['totalPrice'];

        if (count($checkouts) == 0) {
            return response()->json([
                'status' => 'error',
                'code' => 'CHECKOUT_INVALID',
                'message' => 'Keranjang Not Checked',
            ], 409);
        }

        if ($this->checkoutService->hasUnverifiedSellerAddress($checkouts)) {
            return response()->json([
                'status' => 'error',
                'code' => 'SELLER_ADDRESS_REQUIRES_VERIFICATION',
                'message' => 'Lokasi toko penjual belum diverifikasi. Checkout belum dapat dilanjutkan.',
            ], 409);
        }
        // --- step 4 - ambil dan validasi kelompok item checkout - end ---

        // --- step 5 - ambil metode pembayaran checkout - start ---
        $getCheckoutPayment = $this->paymentService->getCheckoutPayment();
        $payments = $getCheckoutPayment['payments'];

        if (count($payments) == 0) {
            return response()->json(['status' => 'error', 'message' => 'Payment List Empty'], 400);
        }
        // --- step 5 - ambil metode pembayaran checkout - end ---

        return response()->json([
            'status' => 'success',
            'alamat' => $alamat,
            'checkouts' => $checkouts,
            'payments' => $payments,
            'totalPrice' => $totalPrice,
        ]);
    }

    /**
     * Memproses pembayaran setelah checkout aktual cocok dengan snapshot yang dilihat buyer.
     *
     * Snapshot frontend dibandingkan sebelum lock checkout diambil. Cart, produk, dan alamat
     * diperiksa kembali pada baris yang terkunci sebelum Xendit diminta membuat Virtual Account.
     * Jika ketersediaan berubah, transaksi di-rollback sebelum pilihan item dilepas.
     *
     * @param  Request  $request  Data pembayaran dan snapshot checkout.
     *
     * @return JsonResponse Hasil checkout dengan ID invoice saat berhasil, atau alasan penolakan.
     */
    public function processCheckout(Request $request): JsonResponse
    {
        // --- step 1 - validasi buyer terautentikasi - start ---
        $user_id_buyer = optional(auth()->user())->id;
        $user = User::where('id', $user_id_buyer)->first();

        if (! $user) {
            return response()->json(['result' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - validasi buyer terautentikasi - end ---

        // --- step 2 - validasi payload checkout - start ---
        $validator = Validator::make($request->all(), [
            'payment_slug' => ['required'],
            'shipping_options' => ['required', 'array'],
            'shipping_options.*.user_id_seller' => ['required'],
            'shipping_options.*.kurir_name' => ['required'],
            'noteds' => ['required', 'array'],
            'client_snapshot' => ['required', 'array'],
            'client_snapshot.alamat_id' => ['required', 'string'],
            'client_snapshot.alamat_updated_at' => ['required', 'string'],
            'client_snapshot.cart_item_ids' => ['required', 'array'],
            'client_snapshot.total_product' => ['required', 'numeric'],
            'client_snapshot.total_shipping' => ['required', 'numeric'],
            'client_snapshot.total_all' => ['required', 'numeric'],
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->messages()], 422);
        }
        // --- step 2 - validasi payload checkout - end ---

        // --- step 3 - bangun dan bandingkan snapshot checkout backend - start ---
        $checkoutSnapshot = $this->checkoutService->buildCheckoutSnapshot(
            user_id_buyer: $user_id_buyer,
            shippingOptions: $request->shipping_options ?? [],
            noteds: $request->noteds ?? [],
            paymentSlug: $request->payment_slug ?? '',
        );

        if ($checkoutSnapshot['status'] === 'invalid') {
            return response()->json([
                'status' => 'error',
                'code' => $checkoutSnapshot['code'],
                'message' => $checkoutSnapshot['message'],
            ], 409);
        }

        if ($checkoutSnapshot['status'] === 'error') {
            return response()->json([
                'status' => 'error',
                'message' => $checkoutSnapshot['message'],
            ], 400);
        }

        if ($this->checkoutService->checkoutSnapshotChanged($checkoutSnapshot, $request->client_snapshot ?? [])) {
            return response()->json([
                'status' => 'error',
                'code' => 'CHECKOUT_CHANGED',
                'message' => 'Checkout berubah, silakan cek ulang sebelum membayar',
                'checkout' => $this->checkoutService->formatCheckoutSnapshotForFrontend($checkoutSnapshot),
            ], 409);
        }
        // --- step 3 - bangun dan bandingkan snapshot checkout backend - end ---

        $checkoutKey = '';

        try {
            // --- step 4 - kunci proses checkout yang sama - start ---
            $checkoutKey = $this->checkoutService->generateCheckoutKey(
                user_id_buyer: $user_id_buyer,
                checkoutSnapshot: $checkoutSnapshot,
            );
            $this->checkoutService->lockCheckoutKey($checkoutKey);
            // --- step 4 - kunci proses checkout yang sama - end ---

            // --- step 5 - cegah checkout yang sudah diproses - start ---
            $existingCheckoutInvoice = $this->checkoutService->getExistingCheckoutInvoice(
                user_id_buyer: $user_id_buyer,
                checkout_key: $checkoutKey,
            );

            if (! empty($existingCheckoutInvoice)) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'CHECKOUT_ALREADY_PROCESSED',
                    'message' => 'Checkout ini sudah diproses, silakan cek transaksi Anda',
                ], 409);
            }
            // --- step 5 - cegah checkout yang sudah diproses - end ---

            // --- step 6 - siapkan data payment - start ---
            $now = Carbon::now()->timestamp;
            $uniqid = uniqid();
            $external_id = "{$checkoutSnapshot['data']['payment']['method']}-{$checkoutSnapshot['data']['payment']['slug']}-{$user_id_buyer}-{$now}-{$uniqid}";
            $bank_code = 'BCA';
            $name = $user->name ?? '';
            $country = 'ID';
            $currency = 'IDR';
            $is_single_use = true;
            $is_closed = true;
            $expected_amount = intval($checkoutSnapshot['clientComparable']['total_all']);

            $expired_at = Carbon::now()->addDay()->setMinutes(0)->setSeconds(0)->setMicroseconds(0);
            $expired_at_xendit = $expired_at->toIso8601String();
            $expired_at_transaction = $expired_at->format('Y-m-d H:i:s');
            // --- step 6 - siapkan data payment - end ---

            // --- step 7 - proses checkout secara atomik - start ---
            $transactionInvoiceId = DB::transaction(function () use (
                $user_id_buyer,
                $checkoutSnapshot,
                $expired_at_transaction,
                $checkoutKey,
                $external_id,
                $bank_code,
                $name,
                $country,
                $currency,
                $is_single_use,
                $is_closed,
                $expected_amount,
                $expired_at_xendit,
            ) {
                // --- step 7.1 - kunci item checkout - start ---
                // Data dikunci dan diverifikasi lagi karena seller dapat mengubah produk
                // setelah buyer membuka halaman checkout atau setelah snapshot awal dibuat.
                $this->checkoutService->lockAndValidateCheckoutItems(
                    $user_id_buyer,
                    $checkoutSnapshot,
                );
                // --- step 7.1 - kunci item checkout - end ---

                // --- step 7.2 - buat virtual account - start ---
                // Payment baru dibuat setelah data checkout lolos validasi row-lock,
                // sehingga item invalid tidak menghasilkan virtual account yatim.
                $resultXendit = $this->xenditService->createVirtualAccount(
                    external_id: $external_id,
                    bank_code: $bank_code,
                    name: $name,
                    country: $country,
                    currency: $currency,
                    is_single_use: $is_single_use,
                    is_closed: $is_closed,
                    expected_amount: $expected_amount,
                    expiration_date: $expired_at_xendit,
                );
                // --- step 7.2 - buat virtual account - end ---

                // --- step 7.3 - validasi respons Xendit - start ---
                // Tolak payload skalar sebelum penyimpanan invoice, cart, dan stok.
                // Data null tetap mengikuti validasi payload kosong di service.
                if ($resultXendit['status'] === 'error') {
                    throw new RuntimeException($resultXendit['message']);
                }

                $dataXendit = $resultXendit['data'] ?? [];

                if (! is_array($dataXendit)) {
                    throw new RuntimeException('Payment creation failed');
                }
                // --- step 7.3 - validasi respons Xendit - end ---

                // --- step 7.4 - simpan invoice checkout - start ---
                $saveCheckoutToDatabase = $this->checkoutService->saveCheckoutToDatabase(
                    user_id_buyer: $user_id_buyer,
                    checkouts: $checkoutSnapshot['data']['checkouts'],
                    kurirs: $checkoutSnapshot['data']['kurirs'],
                    noteds: $checkoutSnapshot['data']['noteds'],
                    alamat_buyer: $checkoutSnapshot['data']['alamat'],
                    payment_method: $checkoutSnapshot['data']['payment']['method'] ?? '',
                    payment_slug: $checkoutSnapshot['data']['payment']['slug'] ?? '',
                    payment_name: $checkoutSnapshot['data']['payment']['name'] ?? '',
                    expired_at: $expired_at_transaction,
                    price: intval($checkoutSnapshot['clientComparable']['total_all']),
                    checkout_key: $checkoutKey,
                    dataXendit: $dataXendit
                );

                if ($saveCheckoutToDatabase['status'] == 'error') {
                    throw new RuntimeException($saveCheckoutToDatabase['message'] ?? 'Save checkout failed');
                }
                // --- step 7.4 - simpan invoice checkout - end ---

                // --- step 7.5 - hapus item keranjang yang diproses - start ---
                $deleteKeranjangAfterCheckout = $this->checkoutService->deleteKeranjangAfterCheckoutForBuyer(
                    user_id_buyer: $user_id_buyer,
                    checkouts: $checkoutSnapshot['data']['checkouts']
                );

                if ($deleteKeranjangAfterCheckout['status'] == 'error') {
                    throw new RuntimeException($deleteKeranjangAfterCheckout['message'] ?? 'Delete keranjang failed');
                }
                // --- step 7.5 - hapus item keranjang yang diproses - end ---

                // --- step 7.6 - kurangi stok produk - start ---
                $changeStockProductAfterCheckout = $this->checkoutService->changeStockProductAfterCheckout(
                    checkouts: $checkoutSnapshot['data']['checkouts']
                );

                if ($changeStockProductAfterCheckout['status'] == 'error') {
                    throw new CheckoutAvailabilityException(
                        $changeStockProductAfterCheckout['message'] ?? 'Change stock product failed',
                        $this->checkoutService->checkoutCartIds(
                            checkouts: $checkoutSnapshot['data']['checkouts'],
                        ),
                    );
                }
                // --- step 7.6 - kurangi stok produk - end ---

                // Catat sinkronisasi katalog di transaksi yang sama dengan perubahan stok.
                $this->recordCheckoutProductOutbox($checkoutSnapshot['data']['checkouts']);

                // Id invoice dikembalikan dari dalam transaksi database supaya frontend dapat menandai
                // transaksi hasil checkout ini secara langsung, bukan menebak data terbaru buyer.
                return $saveCheckoutToDatabase['transaction_invoice_id'] ?? '';
            });
            // --- step 7 - proses checkout secara atomik - end ---

        } catch (CheckoutAvailabilityException $e) {
            // Transaksi database sudah rollback pada titik ini, sehingga perubahan cart
            // disimpan terpisah dan tidak ikut dibatalkan bersama pesanan.
            $this->checkoutService->uncheckCheckoutItems($user_id_buyer, $e->cartIds);

            return response()->json([
                'status' => 'error',
                'code' => 'CHECKOUT_INVALID',
                'message' => $e->getMessage(),
            ], 409);
        } catch (CheckoutChangedException $e) {
            $currentSnapshot = $this->checkoutService->buildCheckoutSnapshot(
                user_id_buyer: $user_id_buyer,
                shippingOptions: $request->shipping_options ?? [],
                noteds: $request->noteds ?? [],
                paymentSlug: $request->payment_slug ?? '',
            );

            if ($currentSnapshot['status'] !== 'success') {
                return response()->json([
                    'status' => 'error',
                    'code' => $currentSnapshot['code'] ?? 'CHECKOUT_INVALID',
                    'message' => $currentSnapshot['message'],
                ], 409);
            }

            return response()->json([
                'status' => 'error',
                'code' => 'CHECKOUT_CHANGED',
                'message' => $e->getMessage(),
                'checkout' => $this->checkoutService->formatCheckoutSnapshotForFrontend($currentSnapshot),
            ], 409);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        } finally {
            if ($checkoutKey !== '') {
                $this->checkoutService->unlockCheckoutKey($checkoutKey);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Pembayaran Berhasil',
            'transaction_invoice_id' => $transactionInvoiceId ?? '',
        ]);
    }

    /**
     * Mencatat outbox Meilisearch untuk setiap produk yang stoknya berubah saat checkout.
     *
     * Method dipanggil di dalam transaksi checkout. ID produk dibuat unik agar beberapa baris
     * checkout untuk produk yang sama tidak menghasilkan intent publikasi duplikat.
     *
     * @param  array<int, array<string, mixed>>  $checkouts  Kelompok checkout authoritative yang sudah diproses.
     *
     * @return void Setiap produk unik dicatat pada outbox transaksi aktif.
     */
    private function recordCheckoutProductOutbox(array $checkouts): void
    {
        // --- step 1 - kumpulkan ID produk checkout secara unik - start ---
        $productIds = [];

        foreach ($checkouts as $checkout) {
            foreach (($checkout['keranjangs'] ?? []) as $keranjang) {
                $productId = (string) ($keranjang['p_id'] ?? '');

                if ($productId !== '') {
                    $productIds[$productId] = true;
                }
            }
        }
        // --- step 1 - kumpulkan ID produk checkout secara unik - end ---

        // --- step 2 - catat proyeksi terbaru bersama commit checkout - start ---
        $this->outboxRecorder->recordProductSyncMany(
            array_keys($productIds),
            OutboxRecorderService::SOURCE_CHECKOUT_STOCK_CHANGED,
        );
        // --- step 2 - catat proyeksi terbaru bersama commit checkout - end ---
    }
}
