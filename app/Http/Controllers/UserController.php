<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function changePassword(Request $request)
    {
        /* VALIDATION REQUEST AND GET */
        $validator = Validator::make($request->all(), [
            'id' => ['required', 'integer'],
            'oldPassword' => ['required'],
            'newPassword' => ['required'],
        ]);

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATION REQUEST AND GET */

        /* GET USER AND COMPARE PASSWORD */
        $user = User::where('id', $request->id)
                    ->first();

        if(empty($user))
            return response()->json([
                'result' => 'error',
                'error_type' => '',
                'message' => 'User Not Found'
            ], 404);

        if(!Hash::check($request->oldPassword, $user->password))
            return response()->json([
                'result' => 'error',
                'error_type' => 'old_password_invalid',
                'message' => 'Old Password Invalid'
            ], 404);
        /* GET USER AND COMPARE PASSWORD */

        /* UPDATE PASSWORD */
        $user->password = Hash::make($request->newPassword);
        $user->save();
        /* UPDATE PASSWORD */

        return response()->json([
            'result' => 'success',
            'message' => 'Change Password Successfully'
        ]);
    }

    public function deleteImage(Request $request) 
    {
        /* VALIDATION REQUEST AND GET */
        $validator = Validator::make($request->all(), [
            'img' => ['required', 'string']   
        ]);

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATION REQUEST AND GET */

        /* DELETE IMG PREV, IF IMG EXISTS */
        if($validate['img'])
        {
            if(Storage::disk('public')->exists($validate['img'])) 
            {
                /* UPDATE IN DATABASE */
                $user = User::where('img', $validate['img'])
                            ->first();
                $user->img = null;
                $user->save();
                /* UPDATE IN DATABASE */

                Storage::disk('public')->delete($validate['img']);
    
                return response()->json(['status' => 200, 'message' => 'Delete Image Success', 'user' => $user], 200);
            }
        }
        /* DELETE IMG PREV, IF IMG EXISTS */

        return response()->json(['status' => 404, 'message' => 'Delete Image Error, Path File Empty'], 404);
    }

    public function uploadImage(Request $request)
    {
        /* VALIDATION REQUEST */     
        $validator = Validator::make($request->all(), [
            'id' => ['required', 'integer'],
            'file' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,svg', 'max:1024']
        ]);

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATION REQUEST */ 

        /* VALIDATION USER EXISTS */
        $user = User::where('id', $validate['id'])
                    ->first();

        if(!$user)
            return response()->json(['status' => 404, 'message' => 'User Not Found'], 404);
        /* VALIDATION USER EXISTS */
        
        /* DELETE IMG PREV, IF IMG EXISTS */
        if($user->img)
            if(Storage::disk('public')->exists($user->img))
                Storage::disk('public')->delete($user->img);
        /* DELETE IMG PREV, IF IMG EXISTS */

        /* UPLOAD IMG AND UPDATE IN DATABASE */   
        $filename = $request->id . "-" . Carbon::now()->timestamp . "." .$request->file('file')->getClientOriginalExtension();
        $path = Storage::disk('public')->putFileAs('user-imgs', $request->file('file'), $filename);
        
        $user->img = $path;
        $user->save();
        /* UPLOAD IMG AND UPDATE IN DATABASE */
        
        return response()->json(['status' => 200, 'message' => 'Upload Image Successfully', 'user' => $user], 200);
    }
    
    public function show(string $id)
    {
        $user = User::where('id', $id)
                    ->first();

        return ($user) ? 
               response()->json(['status' => 200, 'user' => $user], 200) : 
               response()->json(['status' => 404, 'message' => 'User Not Found'], 404) ;
    }

    public function updateUser(Request $request, string $id)
    {
        /* VALIDATION REQUEST AND GET */        
        $validator = Validator::make(
            [
                'id' => $id,
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
            ], 
            [
                'id' => ['required', 'integer'],
                'name' => ['required', 'string'],
                'email' => ['required', 'string', 'max:255', 'email', Rule::unique('users')->ignore($id)],
                'phone' => ['required', 'string', 'max:15', Rule::unique('users')->ignore($id)],
            ]
        );
        
        if($validator->fails())
            return response()->json(['status' => 422, 'result' => 'error', 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATION REQUEST AND GET */

        /* VALIDATION USER EXISTS */
        $user = User::where('id', $validate['id'])
                    ->first();

        if(!$user)
            return response()->json(['status' => 404, 'message' => 'User Not Found'], 404);
        /* VALIDATION USER EXISTS */
        
        /* UPDATE USER */
        $user->name = $validate['name'];
        $user->email = $validate['email'];
        $user->jenis_kelamin = $request->jenis_kelamin;
        $user->tanggal_lahir = $request->tanggal_lahir;
        $user->phone = $validate['phone'];
        $user->tfa = $request->tfa;
        $user->alamat = $request->alamat;
        $user->save();
        /* UPDATE USER */

        return response()->json(['status' => 200, 'message' => 'User Update Successfully', 'user' => $user], 200);
    }
}
