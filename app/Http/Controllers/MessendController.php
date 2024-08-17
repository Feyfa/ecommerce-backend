<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

class MessendController extends Controller
{
    protected Client $client;

    public function __construct() 
    {
        $this->client = new Client();
    }

    public function sendEmail()
    {
        $details = [
            'email' => 'fisikamodern@gmail.com',
            'otp' => '123456',
        ];
        $content = View::make('emails.otp-login')
                       ->with('details', $details)
                       ->render();
        
        try
        {
            $response = $this->client->post(env('MESSEND_URL') . '/api/gmail/send', [
                'form_params' => [
                    'user_secret_key' => config('messend.user_secret_key'),
                    'mail_host' => config('mail.mailers.smtp.host'),
                    'mail_port' => config('mail.mailers.smtp.port'),
                    'mail_encryption' => config('mail.mailers.smtp.encryption'),
                    'mail_username' => config('mail.mailers.smtp.username'),
                    'mail_password' => config('mail.mailers.smtp.password'),
                    'to' => 'muhammadjidan703@gmail.com',
                    'subject' => 'Kirim OTP',
                    'content' => $content,
                ]
            ]);
    
            $response = json_decode($response->getBody()->getContents(), true);
        }
        catch(RequestException $e) 
        {
            $response = json_decode($e->getResponse()->getBody()->getContents(), true);
        }

        return response()->json(['status' => $response['status'], 'message' => $response['message']]);
    }
}
