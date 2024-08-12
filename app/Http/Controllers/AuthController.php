<?php

namespace App\Http\Controllers;

use App\Mail\OtpLoginMail;
use App\Models\OtpLogin;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
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
        $otp = $request->otp ?? "";

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
            Log::info("masuk verification otp");
            /* VALIDATION OTP */
            $epochTime = Carbon::now()->timestamp;
            $otpLogin = OtpLogin::whereRaw('BINARY email = ?', $user->email)
                                ->where('otp', $request->otp)
                                ->first();
                
            if(empty($otpLogin))
                return response()->json(['status' => 401, 'message' => "your otp invalid"], 401);
            if($epochTime >= $otpLogin->expired)
                return response()->json(['status' => 401, 'message' => "your otp expired"], 401);
            /* VALIDATION OTP */

            $otpLogin->delete();

            $token = $request->user()->createToken('authToken')->plainTextToken;

            return response()->json(['status' => 200, 'message' => 'login success', 'token' => $token, 'user' => $user], 200);
        }
        /* VERIFICATION OTP */

        /* CREATE OTP WHEN USER USE TFA */
        if($user->tfa === 'T')
        {
            $otp = $this->generateRandomNumber(length: 6);
            $epochTimeAfterTwoMinute = Carbon::now()->addMinutes(2)->timestamp;

            /* DELETE OTP IF EXISTS */
            OtpLogin::whereRaw('BINARY email = ?', $validate['email'])
                    ->delete();
            /* DELETE OTP IF EXISTS */

            /* CREATE NEW OTP */
            OtpLogin::create([
                'otp' => $otp,
                'email' => $user->email,
                'expired' => $epochTimeAfterTwoMinute
            ]);
            /* CREATE NEW OTP */

            /* SEND EMAIL */
            Mail::to($user->email)
                ->send(new OtpLoginMail($otp, $user->email));
            /* SEND EMAIL */

            return response()->json(['status' => 200, 'type' => 'send_otp'], 200);
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
