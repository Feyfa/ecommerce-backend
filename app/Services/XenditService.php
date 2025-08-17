<?php 

namespace App\Services;

use GuzzleHttp\Client;

class XenditService
{
    private string $api_key;
    private Client $client;

    public function __construct(string $api_key = "") 
    {
        $this->api_key = $api_key;
        $this->client = new Client();
    }

    public function createVirtualAccount(string $external_id = "", string $bank_code = "", string $name = "", string $country = "ID", string $currency = "IDR", bool $is_single_use = false, bool $is_closed = false, int $expected_amount = 0, string $expiration_date = "")
    {
        try
        {
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
                'expiration_date' => $expiration_date
            ];
            $headers = [
                'Authorization' => 'Basic ' . base64_encode("{$this->api_key}:")
            ];
            $paramater = [
                'json' => $json,
                'headers' => $headers
            ];

            $response = $this->client->post($url, $paramater);
            $response = json_decode($response->getBody(), true);

            return [
                'status' => 'suceess',
                'data' => $response
            ];
        }
        catch (\GuzzleHttp\Exception\ClientException $e)
        {
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();
            
            $json = json_decode($body, true);
            $message = $json['message'] ?? 'Unknown error';
            
            return [
                'status' => 'error',
                'message' => $message
            ];
        }
        catch (\Exception $e)
        {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function simulateVirtualAccountPool(string $bank_code = "", string $bank_account_number = "", int $transfer_amount = 0) 
    {
        try
        {

            $url = 'https://api.xendit.co/pool_virtual_accounts/simulate_payment';
            $json = [
                'bank_code' => $bank_code,
                'bank_account_number' => $bank_account_number,
                'transfer_amount' => $transfer_amount,
            ];
            $headers = [
                'Authorization' => 'Basic ' . base64_encode("{$this->api_key}:")
            ];
            $paramater = [
                'json' => $json,
                'headers' => $headers
            ];

            $response = $this->client->post($url, $paramater);
            $response = json_decode($response->getBody(), true);

            return [
                'status' => 'success',
                'data' => $response
            ];
        }
        catch (\GuzzleHttp\Exception\ClientException $e)
        {
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();
            
            $json = json_decode($body, true);
            $message = $json['message'] ?? 'Unknown error';
            
            return [
                'status' => 'error',
                'message' => $message
            ];
        }
        catch (\Exception $e)
        {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function simulateVirtualAccountFixed(string $external_id = "", int $amount = 0)
    {
        try 
        {
            $url = "https://api.xendit.co/callback_virtual_accounts/external_id={$external_id}/simulate_payment";
            $json = [
                'amount' => $amount
            ];
            $headers = [
                'Authorization' => 'Basic ' . base64_encode("{$this->api_key}:")
            ];
            $parameter = [
                'json' => $json,
                'headers' => $headers
            ];

            $response = $this->client->post($url, $parameter);
            $response = json_decode($response->getBody(), true);

            return [
                'status' => 'success',
                'data' => $response
            ];
        }
        catch (\GuzzleHttp\Exception\ClientException $e)
        {
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();
            
            $json = json_decode($body, true);
            $message = $json['message'] ?? 'Unknown error';
            
            return [
                'status' => 'error',
                'message' => $message
            ];
        }
        catch (\Exception $e)
        {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function disbursement(string $external_id = "", int $amount = 0, string $bank_code = "", string $account_holder_name = "", string $account_number = "", string $description = "")
    {
        // info(__FUNCTION__, ['get_defined_vars' => get_defined_vars()]);
        try 
        {
            $url = "https://api.xendit.co/disbursements";
            $json = [
                'external_id' => $external_id,
                'amount' => $amount,
                'bank_code' => $bank_code,
                'account_holder_name' => $account_holder_name,
                'account_number' => $account_number,
                'description' => $description,
            ];
            $headers = [
                'Authorization' => 'Basic ' . base64_encode("{$this->api_key}:")
            ];
            $parameter = [
                'json' => $json,
                'headers' => $headers
            ];

            $response = $this->client->post($url, $parameter);
            $response = json_decode($response->getBody(), true);

            return [
                'status' => 'success',
                'data' => $response
            ];
        }
        catch (\GuzzleHttp\Exception\ClientException $e)
        {
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();
            
            $json = json_decode($body, true);
            $message = $json['message'] ?? 'Unknown error';
            
            return [
                'status' => 'error',
                'message' => $message
            ];
        }
        catch (\Exception $e)
        {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}