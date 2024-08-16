<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
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

        $response = $this->client->post('http://messend.com/api/gmail/send', [
            'form_params' => [
                'user_secret_key' => 'fa4ca692910fc50489dd14d07a2b3d4836ecd9805a66fcaf17885c9d0b82dcbac60b9942',
                'mail_host' => 'smtp.gmail.com',
                'mail_port' => '587',
                'mail_encryption' => 'tls',
                'mail_username' => 'fisikamodern00@gmail.com',
                'mail_password' => 'ctfqqoasnohinylc',
                'to' => 'muhammadjidan703@gmail.com',
                'subject' => 'Kirim OTP',
                'content' => $content,
            ]
        ]);

        $response = json_decode($response->getBody()->getContents(), true);

        Log::info("", [
            $response
        ]);

        return response()->json(['status' => $response['status'], 'message' => $response['message']]);
    }
}
