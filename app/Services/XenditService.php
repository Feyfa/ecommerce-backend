<?php

namespace App\Services;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

/**
 * @phpstan-type XenditError array{status: 'error', message: string}
 * @phpstan-type CreateVirtualAccountResult array{status: 'suceess', data: mixed}|XenditError
 * @phpstan-type XenditResult array{status: 'success', data: mixed}|XenditError
 */
class XenditService
{
    private string $api_key;

    private Client $client;

    /**
     * Menyiapkan client provider untuk operasi virtual account dan pencairan.
     *
     * @param  string  $api_key  API key Xendit yang digunakan client provider.
     * @param  Client|null  $client  Client untuk pengujian respons HTTP tanpa menghubungi provider.
     *
     * @return void Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(string $api_key = '', ?Client $client = null)
    {
        $this->api_key = $api_key;
        $this->client = $client ?? new Client();
    }

    /**
     * Membuat virtual account pembayaran melalui Xendit.
     *
     * Payload virtual account dibentuk dari parameter checkout dan dikirim menggunakan client
     * provider. Response kegagalan diterjemahkan menjadi exception atau hasil terstruktur agar invoice
     * tidak dibuat dari account yang belum tersedia.
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
            $json = [
                'external_id' => $external_id,
                'bank_code' => $bank_code,
                'name' => $name,
                'country' => $country,
                'currency' => $currency,
                'is_single_use' => $is_single_use,
                'is_closed' => $is_closed,
                'expected_amount' => $expected_amount,
                'expiration_date' => $expiration_date,
            ];
            $headers = [
                'Authorization' => 'Basic '.base64_encode("{$this->api_key}:"),
            ];
            $paramater = [
                'json' => $json,
                'headers' => $headers,
            ];
            // --- step 1 - siapkan request pembuatan virtual account - end ---

            // --- step 2 - kirim request dan baca response Xendit - start ---
            $response = $this->client->post($url, $paramater);
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
     * Nomor rekening, bank, dan nominal dikirim ke endpoint simulasi pool account. Method ini
     * digunakan untuk pengujian pembayaran dan mengembalikan hasil provider tanpa membuat invoice
     * baru.
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
            $json = [
                'bank_code' => $bank_code,
                'bank_account_number' => $bank_account_number,
                'transfer_amount' => $transfer_amount,
            ];
            $headers = [
                'Authorization' => 'Basic '.base64_encode("{$this->api_key}:"),
            ];
            $paramater = [
                'json' => $json,
                'headers' => $headers,
            ];
            // --- step 1 - siapkan request simulasi pembayaran pool - end ---

            // --- step 2 - kirim request dan baca response Xendit - start ---
            $response = $this->client->post($url, $paramater);
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
     * Menyimulasikan pembayaran fixed virtual account pada environment pengujian.
     *
     * External ID dan nominal dikirim ke endpoint simulasi fixed account. Hasil dinormalisasi untuk
     * controller pengujian tanpa mengubah state checkout secara langsung.
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
            $json = [
                'amount' => $amount,
            ];
            $headers = [
                'Authorization' => 'Basic '.base64_encode("{$this->api_key}:"),
            ];
            $parameter = [
                'json' => $json,
                'headers' => $headers,
            ];
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
