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
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * @phpstan-type XenditError array{status: 'error', message: string}
 * @phpstan-type CreateVirtualAccountResult array{status: 'suceess', data: mixed}|XenditError
 * @phpstan-type XenditResult array{status: 'success', data: mixed}|XenditError
 * @phpstan-type DisbursementSummary array{
 *     id: string|null,
 *     status: string|null,
 *     failure_code: string|null
 * }
 * @phpstan-type DisbursementAccepted array{
 *     status: 'success',
 *     disbursement: DisbursementSummary
 * }
 * @phpstan-type DisbursementRejected array{
 *     status: 'error',
 *     message: string,
 *     error_code: string|null
 * }
 * @phpstan-type DisbursementUnknown array{status: 'unknown'}
 * @phpstan-type DisbursementResult DisbursementAccepted|DisbursementRejected|DisbursementUnknown
 * @phpstan-type DisbursementFound array{
 *     status: 'found',
 *     disbursements: list<DisbursementSummary>
 * }
 * @phpstan-type DisbursementNotFound array{status: 'not_found'}
 * @phpstan-type DisbursementLookup DisbursementFound|DisbursementNotFound|DisbursementUnknown
 */
class XenditService
{
    /*
     * Batas waktu setiap request ke Xendit.
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
     * Disbursement memakai batas yang sama. Saldo penarikan sudah ditahan sebelum request dikirim,
     * dan jawaban yang hilang tidak dianggap penolakan: hasilnya `unknown`, penarikan tetap
     * diproses, lalu `withdrawal:check` membaca statusnya di Xendit. Sebelum TOK-88, jawaban yang
     * terputus dianggap penolakan tanpa potong saldo padahal dana mungkin sudah terkirim, sehingga
     * user bisa menarik lagi dan perusahaan membayar dua kali.
     */
    public const DEFAULT_CONNECT_TIMEOUT_SECONDS = 5;

    public const DEFAULT_REQUEST_TIMEOUT_SECONDS = 20;

    // Pesan Guzzle untuk timeout dan 5xx memuat teks cURL dan URL Xendit, jadi buyer hanya
    // menerima pesan ini dan detail teknisnya ditulis ke log.
    public const PROVIDER_UNAVAILABLE_MESSAGE =
        'Layanan Pembayaran Sedang Bermasalah, Silakan Coba Lagi';

    private const DISBURSEMENT_URL = 'https://api.xendit.co/disbursements';

    // Diverifikasi di Xendit Sandbox (TOK-88): idempotency key yang pernah dipakai ditolak dengan
    // HTTP 400 kode ini, bukan diputar ulang. Artinya request pertama dengan key itu sudah
    // diterima, jadi hasilnya tidak boleh dianggap penolakan yang mengembalikan saldo.
    private const DUPLICATE_IDEMPOTENCY_ERROR_CODE = 'DUPLICATE_TRANSACTION_ERROR';

    // Status 4xx yang menurut HTTP tidak membuktikan request ditolak: 408 berarti server berhenti
    // menunggu request, dan 409 berarti request bentrok dengan data yang sudah ada. Xendit belum
    // terlihat mengirimnya untuk disbursement. Menganggapnya penolakan akan mengembalikan saldo
    // padahal dana mungkin terkirim; sebagai hasil tidak pasti, `withdrawal:check` membaca
    // statusnya, dan not-found tetap mengembalikan saldo setelah masa tunggu.
    private const UNPROVEN_REJECTION_STATUSES = [
        HttpStatus::HTTP_REQUEST_TIMEOUT,
        HttpStatus::HTTP_CONFLICT,
    ];

    // Diverifikasi di Xendit Sandbox (TOK-88): external ID yang tidak dikenal menghasilkan HTTP 404
    // dengan kode ini. 404 tanpa kode ini tidak dianggap bukti bahwa disbursement tidak ada.
    private const DISBURSEMENT_NOT_FOUND_ERROR_CODE = 'DIRECT_DISBURSEMENT_NOT_FOUND_ERROR';

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
     * @param  float  $connectTimeoutSeconds  Batas waktu membuka koneksi setiap request Xendit.
     * @param  float  $requestTimeoutSeconds  Batas waktu total satu request Xendit.
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
            $parameter = $this->jsonRequestOptions([
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
            $parameter = $this->jsonRequestOptions([
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
            $parameter = $this->jsonRequestOptions([
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
     * Mengirim permintaan pencairan dana ke Xendit dan mengelompokkan hasilnya.
     *
     * External ID juga dikirim sebagai header `X-IDEMPOTENCY-KEY`, sehingga Xendit tidak pernah
     * mengeksekusi dua pencairan untuk penarikan yang sama. Hasil `success` berarti Xendit
     * menerima permintaan; status awalnya biasanya `PENDING` dan hasil akhirnya dibaca kemudian.
     * Hasil `error` hanya untuk penolakan 4xx yang jelas, sehingga caller boleh mengembalikan
     * saldo. Timeout, koneksi gagal, 5xx, key duplikat, 408, 409, dan kegagalan lain menjadi
     * `unknown` karena dana mungkin sudah dikirim. Method ini tidak mengubah saldo lokal.
     *
     * @param  string  $external_id  ID penarikan lokal, sekaligus idempotency key di Xendit.
     * @param  int  $amount  Nominal transaksi yang dikirim ke provider.
     * @param  string  $bank_code  Kode bank tujuan atau virtual account.
     * @param  string  $account_holder_name  Nama pemilik rekening tujuan pencairan.
     * @param  string  $account_number  Nomor rekening tujuan pencairan.
     * @param  string  $description  Deskripsi pencairan yang dikirim kepada provider.
     *
     * @return DisbursementResult Ringkasan pencairan, penolakan dengan kode error, atau unknown.
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
            $parameter = $this->jsonRequestOptions(
                [
                    'external_id' => $external_id,
                    'amount' => $amount,
                    'bank_code' => $bank_code,
                    'account_holder_name' => $account_holder_name,
                    'account_number' => $account_number,
                    'description' => $description,
                ],
                ['X-IDEMPOTENCY-KEY' => $external_id],
            );
            // --- step 1 - siapkan request disbursement - end ---

            // --- step 2 - kirim request dan baca response Xendit - start ---
            $response = $this->client->post(self::DISBURSEMENT_URL, $parameter);
            $payload = json_decode($response->getBody(), true);
            // --- step 2 - kirim request dan baca response Xendit - end ---

            return [
                'status' => 'success',
                'disbursement' => $this->disbursementSummary($payload),
            ];
        } catch (ClientException $e) {
            return $this->classifyDisbursementRejection($e);
        } catch (TransferException $e) {
            $this->logUnusableAnswer('create_disbursement', $e);

            return ['status' => 'unknown'];
        } catch (Exception $e) {
            // Permintaan mungkin sudah terkirim sebelum kegagalan ini, misalnya saat body dibaca.
            $this->logger->warning('Xendit disbursement failed with an unexpected exception.', [
                'operation' => 'create_disbursement',
                'exception' => $e::class,
            ]);

            return ['status' => 'unknown'];
        }
    }

    /**
     * Membaca status pencairan berdasarkan external ID penarikan lokal.
     *
     * Dipakai `withdrawal:check` untuk penarikan yang masih diproses. Hasil `not_found` hanya
     * diberikan untuk HTTP 404 dengan kode not-found Xendit, karena caller dapat mengembalikan
     * saldo berdasarkan hasil itu. Jawaban lain yang tidak bisa dipakai, termasuk timeout, 5xx,
     * 4xx lain, dan daftar kosong, menjadi `unknown` dan dicatat sebagai `warning`.
     *
     * @param  string  $externalId  External ID yang dikirim saat pencairan dibuat.
     *
     * @return DisbursementLookup Daftar pencairan yang cocok, not_found, atau unknown.
     */
    public function findDisbursementByExternalId(string $externalId): array
    {
        try {
            // --- step 1 - kirim request pencarian - start ---
            $parameter = $this->queryRequestOptions(['external_id' => $externalId]);
            $response = $this->client->get(self::DISBURSEMENT_URL, $parameter);
            $payload = json_decode($response->getBody(), true);
            // --- step 1 - kirim request pencarian - end ---

            // --- step 2 - tolak jawaban yang bukan daftar pencairan - start ---
            // Sandbox menjawab 404 untuk external ID yang tidak dikenal, jadi daftar kosong atau
            // bentuk lain tidak dipakai sebagai bukti bahwa pencairan tidak ada.
            if (! is_array($payload) || $payload === [] || ! array_is_list($payload)) {
                $this->logger->warning('Xendit disbursement lookup returned an unexpected body.', [
                    'operation' => 'find_disbursement',
                    'http_status' => $response->getStatusCode(),
                ]);

                return ['status' => 'unknown'];
            }
            // --- step 2 - tolak jawaban yang bukan daftar pencairan - end ---

            return [
                'status' => 'found',
                'disbursements' => array_map($this->disbursementSummary(...), $payload),
            ];
        } catch (ClientException $e) {
            return $this->classifyLookupRejection($e);
        } catch (TransferException $e) {
            $this->logUnusableAnswer('find_disbursement', $e);

            return ['status' => 'unknown'];
        }
    }

    /**
     * Mengelompokkan respons 4xx pembuatan pencairan menjadi penolakan atau hasil tidak pasti.
     *
     * Key duplikat serta status 408 dan 409 menjadi unknown, karena ketiganya tidak membuktikan
     * Xendit menolak request. 4xx lain, termasuk 429, adalah penolakan yang mengembalikan saldo.
     *
     * @param  ClientException  $exception  Respons 4xx dari Xendit.
     *
     * @return DisbursementRejected|DisbursementUnknown Penolakan, atau unknown bila belum pasti.
     */
    private function classifyDisbursementRejection(ClientException $exception): array
    {
        // --- step 1 - baca respons penolakan - start ---
        $response = $exception->getResponse();
        $body = $response->getBody()->getContents();
        $errorCode = $this->extractErrorCode($body);
        // --- step 1 - baca respons penolakan - end ---

        // --- step 2 - tandai 4xx yang belum pasti sebagai unknown - start ---
        // Key duplikat berarti pencairan dengan external ID ini sudah pernah diterima.
        $isDuplicateKey = $errorCode === self::DUPLICATE_IDEMPOTENCY_ERROR_CODE;
        $isUnprovenStatus = in_array(
            $response->getStatusCode(),
            self::UNPROVEN_REJECTION_STATUSES,
            true,
        );

        if ($isDuplicateKey || $isUnprovenStatus) {
            $this->logUnusableAnswer('create_disbursement', $exception);

            return ['status' => 'unknown'];
        }
        // --- step 2 - tandai 4xx yang belum pasti sebagai unknown - end ---

        return [
            'status' => 'error',
            'message' => $this->extractErrorMessage($body),
            'error_code' => $errorCode,
        ];
    }

    /**
     * Mengelompokkan respons 4xx pencarian pencairan menjadi not_found atau unknown.
     *
     * @param  ClientException  $exception  Respons 4xx dari Xendit.
     *
     * @return DisbursementNotFound|DisbursementUnknown not_found hanya untuk 404 not-found Xendit.
     */
    private function classifyLookupRejection(ClientException $exception): array
    {
        // --- step 1 - kenali jawaban not-found dari Xendit - start ---
        $response = $exception->getResponse();
        $errorCode = $this->extractErrorCode($response->getBody()->getContents());

        $isNotFound = $response->getStatusCode() === HttpStatus::HTTP_NOT_FOUND
            && $errorCode === self::DISBURSEMENT_NOT_FOUND_ERROR_CODE;

        if ($isNotFound) {
            return ['status' => 'not_found'];
        }
        // --- step 1 - kenali jawaban not-found dari Xendit - end ---

        // --- step 2 - catat 4xx lain sebagai hasil tidak pasti - start ---
        // Contohnya 401 karena key salah atau 429 karena rate limit; keduanya tidak membuktikan
        // apa pun tentang pencairan.
        $this->logUnusableAnswer('find_disbursement', $exception);
        // --- step 2 - catat 4xx lain sebagai hasil tidak pasti - end ---

        return ['status' => 'unknown'];
    }

    /**
     * Mengambil ID, status, dan kode gagal dari satu pencairan Xendit.
     *
     * @param  mixed  $payload  Objek pencairan hasil decode JSON.
     *
     * @return DisbursementSummary Field yang dipakai caller; nilai yang bukan teks menjadi null.
     */
    private function disbursementSummary(mixed $payload): array
    {
        // Data rekening sengaja tidak diambil agar tidak ikut tersimpan atau tercatat di log.
        $fields = is_array($payload) ? $payload : [];

        return [
            'id' => is_string($fields['id'] ?? null) ? $fields['id'] : null,
            'status' => is_string($fields['status'] ?? null) ? $fields['status'] : null,
            'failure_code' => is_string($fields['failure_code'] ?? null)
                ? $fields['failure_code']
                : null,
        ];
    }

    /**
     * Menyusun opsi Guzzle untuk request Xendit yang mengirim body JSON.
     *
     * @param  array<string, mixed>  $json  Body JSON yang dikirim ke Xendit.
     * @param  array<string, string>  $headers  Header tambahan, misalnya idempotency key.
     *
     * @return array<string, mixed> Body, autentikasi, serta batas waktu koneksi dan request.
     */
    private function jsonRequestOptions(array $json, array $headers = []): array
    {
        // Body ditambahkan di atas opsi dasar agar tidak ada request tanpa batas waktu.
        return [RequestOptions::JSON => $json, ...$this->baseRequestOptions($headers)];
    }

    /**
     * Menyusun opsi Guzzle untuk request Xendit yang membawa query string.
     *
     * @param  array<string, string>  $query  Parameter query yang dikirim ke Xendit.
     *
     * @return array<string, mixed> Query, autentikasi, serta batas waktu koneksi dan request.
     */
    private function queryRequestOptions(array $query): array
    {
        // Guzzle meng-encode query, sehingga external ID tidak perlu disusun manual di URL.
        return [RequestOptions::QUERY => $query, ...$this->baseRequestOptions()];
    }

    /**
     * Menyusun autentikasi dan batas waktu yang dipakai setiap request Xendit.
     *
     * @param  array<string, string>  $headers  Header tambahan di samping Authorization.
     *
     * @return array<string, mixed> Header serta batas waktu koneksi dan request.
     */
    private function baseRequestOptions(array $headers = []): array
    {
        // Batas waktu dipasang di sini agar tidak ada request Xendit yang menunggu selamanya.
        return [
            RequestOptions::HEADERS => [
                'Authorization' => 'Basic '.base64_encode("{$this->api_key}:"),
                ...$headers,
            ],
            RequestOptions::CONNECT_TIMEOUT => $this->connectTimeoutSeconds,
            RequestOptions::TIMEOUT => $this->requestTimeoutSeconds,
        ];
    }

    /**
     * Mencatat kegagalan provider yang tidak membawa jawaban terpakai, lalu menyiapkan pesan aman.
     *
     * Dipakai operasi virtual account untuk timeout, koneksi gagal, 5xx, dan kegagalan transport
     * lain setelah 4xx ditangani terpisah.
     *
     * @param  string  $operation  Nama operasi Xendit untuk log.
     * @param  TransferException  $exception  Kegagalan transport atau respons 5xx dari Guzzle.
     *
     * @return XenditError Pesan aman untuk diteruskan caller ke buyer.
     */
    private function providerUnavailable(string $operation, TransferException $exception): array
    {
        // Buyer hanya melihat pesan aman; detail teknis cukup ada di log.
        $this->logUnusableAnswer($operation, $exception);

        return [
            'status' => 'error',
            'message' => self::PROVIDER_UNAVAILABLE_MESSAGE,
        ];
    }

    /**
     * Menulis `warning` untuk jawaban Xendit yang tidak bisa dipakai, tanpa body maupun URL.
     *
     * Log hanya memuat operasi, kelas exception, status HTTP, serta kode dan teks error cURL.
     * Pesan exception tidak dicatat karena pesan 4xx dan 5xx Guzzle menyalin potongan body
     * respons, dan pesan transport memuat URL Xendit beserta external ID di query string.
     *
     * @param  string  $operation  Nama operasi Xendit untuk log.
     * @param  TransferException  $exception  Kegagalan transport atau respons HTTP dari Guzzle.
     *
     * @return void Hanya menulis log.
     */
    private function logUnusableAnswer(string $operation, TransferException $exception): void
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

    /**
     * Mengambil kode error Xendit, misalnya `DUPLICATE_TRANSACTION_ERROR`, dari body penolakan.
     *
     * @param  string  $body  Isi respons ketika provider menolak permintaan.
     *
     * @return string|null Kode error, atau null jika body tidak membawa kode berbentuk teks.
     */
    private function extractErrorCode(string $body): ?string
    {
        // Kode menentukan apakah saldo boleh dikembalikan, jadi bentuk lain tidak ditebak.
        $decoded = json_decode($body, true);
        $fields = is_array($decoded) ? $decoded : [];
        $errorCode = $fields['error_code'] ?? null;

        return is_string($errorCode) ? $errorCode : null;
    }
}
