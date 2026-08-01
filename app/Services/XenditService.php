<?php

namespace App\Services;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class XenditService
{
    private string $api_key;

    private Client $client;

    /**
     * Menyiapkan dependency yang diperlukan oleh class.
     *
     * @param  string  $api_key  API key Xendit yang digunakan client provider.
     *
     * @return void  Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(string $api_key = '')
    {
        $this->api_key = $api_key;
        $this->client = new Client();
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
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function createVirtualAccount(string $external_id = '', string $bank_code = '', string $name = '', string $country = 'ID', string $currency = 'IDR', bool $is_single_use = false, bool $is_closed = false, int $expected_amount = 0, string $expiration_date = ''): array
    {
        try {
            // --- step 1 - start - siapkan request pembuatan virtual account
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
            // --- step 1 - end - siapkan request pembuatan virtual account

            // --- step 2 - start - kirim request dan baca response Xendit
            $response = $this->client->post($url, $paramater);
            $response = json_decode($response->getBody(), true);
            // --- step 2 - end - kirim request dan baca response Xendit

            return [
                'status' => 'suceess',
                'data' => $response,
            ];
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();

            $json = json_decode($body, true);
            $message = $json['message'] ?? 'Unknown error';

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
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function simulateVirtualAccountPool(string $bank_code = '', string $bank_account_number = '', int $transfer_amount = 0): array
    {
        try {
            // --- step 1 - start - siapkan request simulasi pembayaran pool
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
            // --- step 1 - end - siapkan request simulasi pembayaran pool

            // --- step 2 - start - kirim request dan baca response Xendit
            $response = $this->client->post($url, $paramater);
            $response = json_decode($response->getBody(), true);
            // --- step 2 - end - kirim request dan baca response Xendit

            return [
                'status' => 'success',
                'data' => $response,
            ];
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();

            $json = json_decode($body, true);
            $message = $json['message'] ?? 'Unknown error';

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
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function simulateVirtualAccountFixed(string $external_id = '', int $amount = 0): array
    {
        try {
            // --- step 1 - start - siapkan request simulasi pembayaran fixed virtual account
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
            // --- step 1 - end - siapkan request simulasi pembayaran fixed virtual account

            // --- step 2 - start - kirim request dan baca response Xendit
            $response = $this->client->post($url, $parameter);
            $response = json_decode($response->getBody(), true);
            // --- step 2 - end - kirim request dan baca response Xendit

            return [
                'status' => 'success',
                'data' => $response,
            ];
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();

            $json = json_decode($body, true);
            $message = $json['message'] ?? 'Unknown error';

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
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function disbursement(string $external_id = '', int $amount = 0, string $bank_code = '', string $account_holder_name = '', string $account_number = '', string $description = ''): array
    {
        try {
            // --- step 1 - start - siapkan request disbursement
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
            // --- step 1 - end - siapkan request disbursement

            // --- step 2 - start - kirim request dan baca response Xendit
            $response = $this->client->post($url, $parameter);
            $response = json_decode($response->getBody(), true);
            // --- step 2 - end - kirim request dan baca response Xendit

            return [
                'status' => 'success',
                'data' => $response,
            ];
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();

            $json = json_decode($body, true);
            $message = $json['message'] ?? 'Unknown error';

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
}
