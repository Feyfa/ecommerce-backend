<?php

namespace App\Services;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @phpstan-type XenditError array{status: 'error', message: string}
 * @phpstan-type CreateVirtualAccountResult array{status: 'suceess', data: mixed}|XenditError
 * @phpstan-type XenditResult array{status: 'success', data: mixed}|XenditError
 */
class XenditService
{
    /*
     * Batas waktu request virtual account ke Xendit.
     *
     * Tanpa opsi, Guzzle menunggu jawaban selamanya (`timeout` 0) dan mencoba terhubung sampai
     * 300 detik. Checkout memanggil createVirtualAccount() di dalam DB transaction setelah
     * keranjang, produk, dan alamat dikunci FOR UPDATE. Selama Xendit belum menjawab, buyer lain
     * yang checkout produk yang sama dan perubahan produk atau alamat ikut menunggu, dan satu
     * worker PHP tertahan.
     *
     * Reverse proxy dan backend-nginx tidak mengatur `proxy_read_timeout` maupun
     * `fastcgi_read_timeout`, sehingga keduanya memakai default nginx 60 detik. Setelah itu buyer
     * menerima 504 sementara PHP masih menunggu. Batas 20 detik menyisakan ruang untuk query
     * checkout di bawah 60 detik. Nilai ini titik awal, bukan hasil pengukuran latensi Xendit,
     * dan dapat diubah lewat XENDIT_CONNECT_TIMEOUT_SECONDS dan XENDIT_REQUEST_TIMEOUT_SECONDS.
     *
     * Disbursement sengaja belum memakai batas ini. Saldo penarikan hanya dipotong setelah Xendit
     * menjawab sukses, jadi jawaban yang terputus diperlakukan sebagai penolakan padahal dana
     * mungkin sudah terkirim. Timeout akan membuat kasus itu lebih sering sampai penarikan punya
     * status pending yang dapat dicek ulang ke Xendit.
     */
    public const DEFAULT_CONNECT_TIMEOUT_SECONDS = 5;

    public const DEFAULT_REQUEST_TIMEOUT_SECONDS = 20;

    // Pesan Guzzle untuk timeout dan 5xx memuat teks cURL dan URL Xendit, jadi buyer hanya
    // menerima pesan ini dan detail teknisnya ditulis ke log.
    public const PROVIDER_UNAVAILABLE_MESSAGE =
        'Layanan Pembayaran Sedang Bermasalah, Silakan Coba Lagi';

    private string $api_key;

    private Client $client;

    private float $connectTimeoutSeconds;

    private float $requestTimeoutSeconds;

    private LoggerInterface $logger;

    /**
     * Menyiapkan client provider untuk operasi virtual account dan pencairan.
     *
     * @param  string  $api_key  API key Xendit yang digunakan client provider.
     * @param  Client|null  $client  Client untuk pengujian respons HTTP tanpa menghubungi provider.
     * @param  float  $connectTimeoutSeconds  Batas waktu membuka koneksi request virtual account.
     * @param  float  $requestTimeoutSeconds  Batas waktu total satu request virtual account.
     * @param  LoggerInterface|null  $logger  Tujuan log kegagalan provider; null membuang log.
     *
     * @return void Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(
        string $api_key = '',
        ?Client $client = null,
        float $connectTimeoutSeconds = self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
        float $requestTimeoutSeconds = self::DEFAULT_REQUEST_TIMEOUT_SECONDS,
        ?LoggerInterface $logger = null,
    ) {
        // NullLogger menjaga pemanggil tanpa logger, termasuk unit test, tetap berjalan.
        $this->api_key = $api_key;
        $this->client = $client ?? new Client();
        $this->connectTimeoutSeconds = $connectTimeoutSeconds;
        $this->requestTimeoutSeconds = $requestTimeoutSeconds;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Membuat virtual account pembayaran melalui Xendit.
     *
     * Payload virtual account dibentuk dari parameter checkout dan dikirim dengan batas waktu
     * virtual account. Penolakan 4xx meneruskan pesan Xendit, sedangkan timeout, koneksi gagal,
     * dan 5xx menjadi pesan aman agar invoice tidak dibuat dari account yang belum tersedia.
     *
     * @param  string  $external_id  Identifier idempotensi yang dikirim ke provider.
     * @param  string  $bank_code  Kode bank tujuan atau virtual account.
     * @param  string  $name  Nama user, rekening, atau resource sesuai konteks operasi.
     * @param  string  $country  Kode negara untuk pembuatan virtual account.
     * @param  string  $currency  Kode mata uang transaksi.
     * @param  bool  $is_single_use  Penanda bahwa virtual account hanya dapat dibayar sekali.
     * @param  bool  $is_closed  Penanda bahwa virtual account memakai nominal tetap.
     * @param  int  $expected_amount  Nominal yang diharapkan oleh virtual account.
     * @param  string  $expiration_date  Waktu kedaluwarsa virtual account.
     *
     * @return CreateVirtualAccountResult Respons VA dengan status sukses lama atau pesan error.
     */
    public function createVirtualAccount(
        string $external_id = '',
        string $bank_code = '',
        string $name = '',
        string $country = 'ID',
        string $currency = 'IDR',
        bool $is_single_use = false,
        bool $is_closed = false,
        int $expected_amount = 0,
        string $expiration_date = '',
    ): array {
        try {
            // --- step 1 - siapkan request pembuatan virtual account - start ---
            $url = 'https://api.xendit.co/callback_virtual_accounts';
            $parameter = $this->virtualAccountRequestOptions([
                'external_id' => $external_id,
                'bank_code' => $bank_code,
                'name' => $name,
                'country' => $country,
                'currency' => $currency,
                'is_single_use' => $is_single_use,
                'is_closed' => $is_closed,
                'expected_amount' => $expected_amount,
                'expiration_date' => $expiration_date,
            ]);
            // --- step 1 - siapkan request pembuatan virtual account - end ---

            // --- step 2 - kirim request dan baca response Xendit - start ---
            $response = $this->client->post($url, $parameter);
            $response = json_decode($response->getBody(), true);
            // --- step 2 - kirim request dan baca response Xendit - end ---

            return [
                'status' => 'suceess',
                'data' => $response,
            ];
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();

            $message = $this->extractErrorMessage($body);

            return [
                'status' => 'error',
                'message' => $message,
            ];
        } catch (TransferException $e) {
            return $this->providerUnavailable('create_virtual_account', $e);
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Menyimulasikan pembayaran virtual account pool pada environment pengujian.
     *
     * Nomor rekening, bank, dan nominal dikirim ke endpoint simulasi pool account dengan batas
     * waktu virtual account. Method ini digunakan untuk pengujian pembayaran dan mengembalikan
     * hasil provider tanpa membuat invoice baru.
     *
     * @param  string  $bank_code  Kode bank tujuan atau virtual account.
     * @param  string  $bank_account_number  Nomor virtual account pada skenario simulasi.
     * @param  int  $transfer_amount  Nominal transfer pada simulasi pembayaran.
     *
     * @return XenditResult Payload simulasi pool atau pesan error.
     */
    public function simulateVirtualAccountPool(
        string $bank_code = '',
        string $bank_account_number = '',
        int $transfer_amount = 0,
    ): array {
        try {
            // --- step 1 - siapkan request simulasi pembayaran pool - start ---
            $url = 'https://api.xendit.co/pool_virtual_accounts/simulate_payment';
            $parameter = $this->virtualAccountRequestOptions([
                'bank_code' => $bank_code,
                'bank_account_number' => $bank_account_number,
                'transfer_amount' => $transfer_amount,
            ]);
            // --- step 1 - siapkan request simulasi pembayaran pool - end ---

            // --- step 2 - kirim request dan baca response Xendit - start ---
            $response = $this->client->post($url, $parameter);
            $response = json_decode($response->getBody(), true);
            // --- step 2 - kirim request dan baca response Xendit - end ---

            return [
                'status' => 'success',
                'data' => $response,
            ];
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();

            $message = $this->extractErrorMessage($body);

            return [
                'status' => 'error',
                'message' => $message,
            ];
        } catch (TransferException $e) {
            return $this->providerUnavailable('simulate_virtual_account_pool', $e);
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Menyimulasikan pembayaran fixed virtual account pada environment pengujian.
     *
     * External ID dan nominal dikirim ke endpoint simulasi fixed account dengan batas waktu virtual
     * account. Hasil dinormalisasi untuk controller pengujian tanpa mengubah state checkout secara
     * langsung.
     *
     * @param  string  $external_id  Identifier idempotensi yang dikirim ke provider.
     * @param  int  $amount  Nominal transaksi yang dikirim ke provider.
     *
     * @return XenditResult Payload simulasi fixed atau pesan error.
     */
    public function simulateVirtualAccountFixed(string $external_id = '', int $amount = 0): array
    {
        try {
            // --- step 1 - siapkan request simulasi pembayaran fixed virtual account - start ---
            $url = "https://api.xendit.co/callback_virtual_accounts/external_id={$external_id}/simulate_payment";
            $parameter = $this->virtualAccountRequestOptions([
                'amount' => $amount,
            ]);
            // --- step 1 - siapkan request simulasi pembayaran fixed virtual account - end ---

            // --- step 2 - kirim request dan baca response Xendit - start ---
            $response = $this->client->post($url, $parameter);
            $response = json_decode($response->getBody(), true);
            // --- step 2 - kirim request dan baca response Xendit - end ---

            return [
                'status' => 'success',
                'data' => $response,
            ];
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();

            $message = $this->extractErrorMessage($body);

            return [
                'status' => 'error',
                'message' => $message,
            ];
        } catch (TransferException $e) {
            return $this->providerUnavailable('simulate_virtual_account_fixed', $e);
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Membuat permintaan pencairan dana melalui Xendit.
     *
     * Detail rekening tujuan dan nominal disusun menjadi permintaan pencairan provider. Caller hanya
     * boleh mengubah saldo setelah hasil sukses diterima, sehingga method ini tidak melakukan mutasi
     * balance lokal.
     *
     * @param  string  $external_id  Identifier idempotensi yang dikirim ke provider.
     * @param  int  $amount  Nominal transaksi yang dikirim ke provider.
     * @param  string  $bank_code  Kode bank tujuan atau virtual account.
     * @param  string  $account_holder_name  Nama pemilik rekening tujuan pencairan.
     * @param  string  $account_number  Nomor rekening tujuan pencairan.
     * @param  string  $description  Deskripsi pencairan yang dikirim kepada provider.
     *
     * @return XenditResult Payload pencairan atau pesan error.
     */
    public function disbursement(
        string $external_id = '',
        int $amount = 0,
        string $bank_code = '',
        string $account_holder_name = '',
        string $account_number = '',
        string $description = '',
    ): array {
        try {
            // --- step 1 - siapkan request disbursement - start ---
            // Sengaja tanpa batas waktu virtual account; alasannya ada di komentar konstanta
            // DEFAULT_REQUEST_TIMEOUT_SECONDS.
            $url = 'https://api.xendit.co/disbursements';
            $json = [
                'external_id' => $external_id,
                'amount' => $amount,
                'bank_code' => $bank_code,
                'account_holder_name' => $account_holder_name,
                'account_number' => $account_number,
                'description' => $description,
            ];
            $headers = [
                'Authorization' => 'Basic '.base64_encode("{$this->api_key}:"),
            ];
            $parameter = [
                'json' => $json,
                'headers' => $headers,
            ];
            // --- step 1 - siapkan request disbursement - end ---

            // --- step 2 - kirim request dan baca response Xendit - start ---
            $response = $this->client->post($url, $parameter);
            $response = json_decode($response->getBody(), true);
            // --- step 2 - kirim request dan baca response Xendit - end ---

            return [
                'status' => 'success',
                'data' => $response,
            ];
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();

            $message = $this->extractErrorMessage($body);

            return [
                'status' => 'error',
                'message' => $message,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Menyusun opsi Guzzle untuk satu request virtual account.
     *
     * @param  array<string, mixed>  $json  Body JSON yang dikirim ke Xendit.
     *
     * @return array<string, mixed> Body, autentikasi, serta batas waktu koneksi dan request.
     */
    private function virtualAccountRequestOptions(array $json): array
    {
        // Batas waktu dipasang di sini agar setiap operasi virtual account memakainya.
        return [
            RequestOptions::JSON => $json,
            RequestOptions::HEADERS => [
                'Authorization' => 'Basic '.base64_encode("{$this->api_key}:"),
            ],
            RequestOptions::CONNECT_TIMEOUT => $this->connectTimeoutSeconds,
            RequestOptions::TIMEOUT => $this->requestTimeoutSeconds,
        ];
    }

    /**
     * Mencatat kegagalan provider yang tidak membawa jawaban terpakai, lalu menyiapkan pesan aman.
     *
     * Dipakai untuk timeout, koneksi gagal, 5xx, dan kegagalan transport lain setelah 4xx
     * ditangani terpisah. Log hanya memuat operasi, kelas exception, status HTTP, serta kode dan
     * teks error cURL. Pesan exception tidak dicatat karena pesan 5xx Guzzle menyalin potongan body
     * respons dan pesan transport memuat URL Xendit.
     *
     * @param  string  $operation  Nama operasi Xendit untuk log.
     * @param  TransferException  $exception  Kegagalan transport atau respons 5xx dari Guzzle.
     *
     * @return XenditError Pesan aman untuk diteruskan caller ke buyer.
     */
    private function providerUnavailable(string $operation, TransferException $exception): array
    {
        // --- step 1 - ambil detail teknis kegagalan - start ---
        // Hanya dua exception Guzzle ini yang membawa konteks handler cURL.
        $handlerContext = [];

        if ($exception instanceof ConnectException || $exception instanceof RequestException) {
            $handlerContext = $exception->getHandlerContext();
        }

        $response = $exception instanceof RequestException ? $exception->getResponse() : null;
        // --- step 1 - ambil detail teknis kegagalan - end ---

        // --- step 2 - catat kegagalan tanpa body maupun URL - start ---
        $this->logger->warning('Xendit request failed without a usable response.', [
            'operation' => $operation,
            'exception' => $exception::class,
            'http_status' => $response?->getStatusCode(),
            'curl_errno' => $handlerContext['errno'] ?? null,
            'curl_error' => $handlerContext['error'] ?? null,
        ]);
        // --- step 2 - catat kegagalan tanpa body maupun URL - end ---

        return [
            'status' => 'error',
            'message' => self::PROVIDER_UNAVAILABLE_MESSAGE,
        ];
    }

    /**
     * Menyiapkan pesan error provider untuk respons API atau exception checkout.
     *
     * @param  string  $body  Isi respons ketika provider menolak permintaan.
     *
     * @return string Pesan asli jika berbentuk teks, atau pesan fallback.
     */
    private function extractErrorMessage(string $body): string
    {
        // Pesan non-string tidak dapat dipakai sebagai teks RuntimeException di checkout.
        $decoded = json_decode($body, true);
        $fields = is_array($decoded) ? $decoded : [];
        $message = $fields['message'] ?? null;

        return is_string($message) ? $message : 'Unknown error';
    }
}
