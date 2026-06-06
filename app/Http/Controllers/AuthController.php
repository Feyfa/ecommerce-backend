<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CompanyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    protected CompanyService $companyService;

    public function __construct(CompanyService $companyService) 
    {
        $this->companyService = $companyService;
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
        $user = User::where('email', $validate['email'])
                    ->first();
        /* CHECK EMAIL CASE SENSITIVE */

        /* VALIDATION USER INVALID EMAIL OR PASSWORD */
        if(empty($user) || !Auth::attempt(['email' => $validate['email'], 'password' => $validate['password']]))
            return response()->json(['status' => 401, 'message' => 'invalid login details'], 401);
        /* VALIDATION USER INVALID EMAIL OR PASSWORD */

        /* GET COMPANY */
        $getCompany = $this->companyService->getCompany($user->id);
        $company = $getCompany['company'];
        /* GET COMPANY */

        $token = $request->user()->createToken('authToken')->plainTextToken;

        return response()->json(['status' => 200, 'message' => 'login success', 'token' => $token, 'user' => $user, 'company' => $company], 200);
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json(['status' => 200, 'message' => 'logout success'], 200);
    }
}
