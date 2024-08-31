<?php

namespace App\Http\Controllers;

use App\Mail\OtpLoginMail;
use App\Models\OtpLogin;
use App\Models\User;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Messend\Messend;

class AuthController extends Controller
{
    protected Client $client;
    protected Messend $messend;

    public function __construct(Client $client, Messend $messend) 
    {
        $this->client = $client;
        $this->messend = $messend;
    }

    public function tokenValidation()
    {
        return response()->json(['status' => 200, 'message' => 'token valid'], 200);
    }

    public function register(Request $request)
    {
        /* VALIDATE AND GET */
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'max:255','email', 'unique:users'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        if($validator->fails()) 
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATE AND GET */

        $validate['password'] = Hash::make($validate['password']);

        User::create($validate);

        return response()->json(['status' => 201, 'message' => 'register success'], 201);
    }

    public function login(Request $request)
    {
        $type = $request->type ?? "";

        /* VALIDATOR */
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'max:255', 'email'],
            'password' => ['required', 'string'],
        ]);

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATOR */

        /* CHECK EMAIL CASE SENSITIVE */
        $user = User::whereRaw('BINARY email = ?', $validate['email'])
                    ->first();
        /* CHECK EMAIL CASE SENSITIVE */

        if(empty($user) || !Auth::attempt(['email' => $validate['email'], 'password' => $validate['password']]))
            return response()->json(['status' => 401, 'message' => 'invalid login details'], 401);

        /* VERIFICATION OTP */
        if($type === 'verification_otp')
        {
            /* MATCH OTP */
            $otp_secret_key = $request->otpSecretKey ?? "";
            $otp_code = $request->otpCode ?? "";
            $now = $request->now ?? "";

            // try catch is already handle on library messend
            $responseMatchOtp = $this->messend->tfa->matchOtp([
                'user_secret_key' => config('messend.user_secret_key'),
                'otp_secret_key' => $otp_secret_key,
                'contact' => $validate['email'],
                'otp_code' => $otp_code,
                'now' => $now
            ]);

            if($responseMatchOtp->status === 'error') {
                return response()->json(['status' => 422, 'message' => $responseMatchOtp->message], 422);
            }
            else if($responseMatchOtp->status === 'success') {
                $token = $request->user()->createToken('authToken')->plainTextToken;
                return response()->json(['status' => 200, 'message' => 'login success', 'token' => $token, 'user' => $user], 200);
            }
            else {
                return response()->json(['status' => 422, 'message' => ['messend_other' => ['something went wrong']]], 422);
            }
            /* MATCH OTP */
        }
        /* VERIFICATION OTP */

        /* CREATE OTP WHEN USER USE TFA */
        if($user->tfa !== 'F' && $user->tfa !== 'Phone')
        {
            /* GENERATE OTP */
            $expired = $request->expired ?? "";

            // try catch is already handle on library messend
            $responseGenerateOtp = $this->messend->tfa->generateOtp([
                'user_secret_key' => config('messend.user_secret_key'),
                'contact' => $validate['email'],
                'expired' => $expired,
            ]);

            if($responseGenerateOtp->status == 'error') 
                return response()->json(['status' => 422, 'message' => $responseGenerateOtp->message], 422);
            /* GENERATE OTP */

            /* SEND EMAIL */
            $details = [
                'email' => $validate['email'],
                'otp' => $responseGenerateOtp->otp_code,
            ];

            $content = View::make('emails.otp-login')
                           ->with('details', $details)
                           ->render();
            
            // try catch is already handle on library messend
            $responseSendEmail = $this->messend->email->send([
                'user_secret_key' => config('messend.user_secret_key'),
                'mail_host' => config('mail.mailers.smtp.host'),
                'mail_port' => config('mail.mailers.smtp.port'),
                'mail_encryption' => config('mail.mailers.smtp.encryption'),
                'mail_username' => config('mail.mailers.smtp.username'),
                'mail_password' => config('mail.mailers.smtp.password'),
                'to' => $validate['email'],
                'subject' => 'Kirim OTP',
                'content' => $content,
            ]);

            if($responseSendEmail->status == 'error') 
                return response()->json(['status' => 422, 'message' => $responseSendEmail->message], 422);
            /* SEND EMAIL */
    
            return response()->json(['status' => 200, 'type' => 'send_otp', 'otp_secret_key' => $responseGenerateOtp->otp_secret_key], 200);
        }
        /* CREATE OTP WHEN USER USE TFA */

        $token = $request->user()->createToken('authToken')->plainTextToken;

        return response()->json(['status' => 200, 'message' => 'login success', 'token' => $token, 'user' => $user], 200);
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json(['status' => 200, 'message' => 'logout success'], 200);
    }
}
