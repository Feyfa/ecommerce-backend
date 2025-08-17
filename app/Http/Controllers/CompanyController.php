<?php

namespace App\Http\Controllers;

use App\Models\Alamat;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class CompanyController extends Controller
{
    protected CompanyService $companyService;

    public function __construct(CompanyService $companyService) 
    {
        $this->companyService = $companyService;
    }

    public function show()
    {
        /* VALIDATION USER */
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if(!$userExists)
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER */

        /* GET COMPANY */
        $getCompany = $this->companyService->getCompany($user_id);
        $company = $getCompany['company'];
        /* GET COMPANY */
                          
        return response()->json(['status' => 'success', 'company' => $company]);
    }

    public function updateCompany(Request $request)
    {
        /* VALIDATION USER */
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if(!$userExists)
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER */

        /* VALIDATION REQUEST AND GET */        
        $validator = Validator::make(
            [
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'alamat' => $request->alamat,
            ], 
            [
                'name' => ['required', 'string'],
                'alamat' => ['required', 'string'],
                'email' => [
                    'required', 'string', 'max:255', 'email', 
                    function ($attribute, $value, $fail) use ($user_id) {
                        $userExists = User::where('id','<>',$user_id)
                                          ->where('email', $value)
                                          ->exists();
                        $companyExists = Company::where('user_id','<>',$user_id)
                                                ->where('email', $value)
                                                ->exists();
                        if($userExists || $companyExists)
                            $fail("Email Already Exists");
                    }
                ],
                'phone' => [
                    'required', 'string', 'max:20', 
                    function ($attribute, $value, $fail) use ($user_id) {
                        $userExists = User::where('id','<>',$user_id)
                                          ->where('phone', $value)
                                          ->exists();
                        $companyExists = Company::where('user_id','<>',$user_id)
                                                ->where('phone', $value)
                                                ->exists();
                        if($userExists || $companyExists)
                            $fail("Phone Already Exists");
                    }
                ],
            ]
        );
        
        if($validator->fails())
            return response()->json(['status' => 'error', 'message' => $validator->messages()], 422);
        /* VALIDATION REQUEST AND GET */

        /* UPDATE COMPANY */
        Company::updateOrCreate(
            ['user_id' => $user_id],
            [
                'name' => $request->name ?? "",
                'email' => $request->email ?? "",
                'phone' => $request->phone ?? "",
                'description' => $request->description ?? "",
            ]
        );
        /* UPDATE COMPANY */

        /* UPDATE ALAMAT */
        Alamat::updateOrCreate(
            [
                'user_id' => $user_id,
                'type' => 'seller'
            ],
            [
                'user_id' => $user_id,
                'type' => 'seller',
                'alamat' => $request->alamat ?? "",
                'enable' => 1
            ]
        );
        /* UPDATE ALAMAT */

        /* GET COMPANY */
        $getCompany = $this->companyService->getCompany($user_id);
        $company = $getCompany['company'];
        /* GET COMPANY */

        return response()->json(['status' => 'success', 'message' => 'Company Update Successfully', 'company' => $company], 200);
    }

    public function uploadImage(Request $request)
    {
        /* VALIDATION USER */
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if(!$userExists) 
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER */

        /* VALIDATION REQUEST */     
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,svg', 'max:1024']
        ]);

        if($validator->fails())
            return response()->json(['status' => 'error', 'message' => $validator->messages()], 422);
        /* VALIDATION REQUEST */ 

        /* GET COMPANY */
        $companyImg = Company::where('user_id', $user_id)->value('img');
        /* GET COMPANY */

        /* DELETE IMG PREV, IF IMG EXISTS */
        if($companyImg)
            if(Storage::disk('public')->exists($companyImg))
                Storage::disk('public')->delete($companyImg);
        /* DELETE IMG PREV, IF IMG EXISTS */

        /* UPLOAD IMG AND UPDATE IN DATABASE */   
        $filename = $user_id . "-" . Carbon::now()->timestamp . "." .$request->file('file')->getClientOriginalExtension();
        $path = Storage::disk('public')->putFileAs('company-imgs', $request->file('file'), $filename);
        
        Company::updateOrCreate(
            ['user_id' => $user_id],
            ['img' => $path]
        );
        /* UPLOAD IMG AND UPDATE IN DATABASE */

        /* GET COMPANY */
        $getCompany = $this->companyService->getCompany($user_id);
        $company = $getCompany['company'];
        /* GET COMPANY */

        return response()->json(['status' => 'success', 'message' => 'Upload Image Successfully', 'company' => $company], 200);
    }

    public function deleteImage()
    {
        /* VALIDATION USER */
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if(!$userExists)
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER */

        /* GET COMPANY */
        $company = Company::where('user_id', $user_id)
                          ->first();
        if(!$company)
            return response()->json(['status' => 'error', 'message' => 'Company Is Empty'], 400);
        /* GET COMPANY */

        /* DELETE IMAGE IN PATH AND DATABASE */
        if(!Storage::disk('public')->exists(($company->img ?? "")))
            return response()->json(['status' => 'error', 'message' => 'Delete Image Error, Path File Empty'], 400);    
        
        Storage::disk('public')->delete(($company->img ?? ""));
        $company->img = null;
        $company->save();

        return response()->json(['status' => 'success', 'message' => 'Delete Image Success', 'company' => $company], 200);
        /* DELETE IMAGE IN PATH AND DATABASE */
    }
}
