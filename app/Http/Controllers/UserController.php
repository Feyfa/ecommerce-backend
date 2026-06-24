<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
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

        /* VALIDATION AUTHENTICATED USER */
        $user = $request->user();

        if(!$user)
            return response()->json(['status' => 404, 'message' => 'User Not Found'], 404);

        if($user->img !== $validate['img'])
            return response()->json(['status' => 403, 'message' => 'Forbidden'], 403);
        /* VALIDATION AUTHENTICATED USER */

        /* DELETE IMG PREV, IF IMG EXISTS */
        if($validate['img'])
        {
            if(Storage::disk('public')->exists($validate['img'])) 
            {
                /* UPDATE IN DATABASE */
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
            'id' => ['required', 'uuid'],
            'file' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,svg', 'max:1024']
        ]);

        if($validator->fails())
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATION REQUEST */ 

        /* VALIDATION AUTHENTICATED USER */
        $user = $request->user();

        if(!$user)
            return response()->json(['status' => 404, 'message' => 'User Not Found'], 404);

        if((string) $user->id !== (string) $validate['id'])
            return response()->json(['status' => 403, 'message' => 'Forbidden'], 403);
        /* VALIDATION AUTHENTICATED USER */

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
    
    public function show()
    {
        $id = auth()->user()->id;

        $user = User::where('id', $id)
                    ->first();

        return ($user) ? 
               response()->json(['status' => 200, 'user' => $user], 200) : 
               response()->json(['status' => 404, 'message' => 'User Not Found'], 404) ;
    }

    public function updateUser(Request $request, string $id)
    {
        /* VALIDATION ROUTE ID */
        $routeIdValidator = Validator::make(['id' => $id], [
            'id' => ['required', 'uuid'],
        ]);

        if($routeIdValidator->fails())
            return response()->json(['status' => 422, 'result' => 'error', 'message' => $routeIdValidator->messages()], 422);

        $validatedRouteId = $routeIdValidator->validate()['id'];
        /* VALIDATION ROUTE ID */

        /* VALIDATION AUTHENTICATED USER */
        $user = $request->user();

        if(!$user)
            return response()->json(['status' => 404, 'message' => 'User Not Found'], 404);

        if((string) $user->id !== (string) $validatedRouteId)
            return response()->json(['status' => 403, 'message' => 'Forbidden'], 403);
        /* VALIDATION AUTHENTICATED USER */

        /* VALIDATION REQUEST AND GET */        
        $validator = Validator::make(
            [
                'phone' => $request->phone,
            ], 
            [
                'phone' => ['required', 'string', 'max:15', Rule::unique('users')->ignore($validatedRouteId)],
            ]
        );
        
        if($validator->fails())
            return response()->json(['status' => 422, 'result' => 'error', 'message' => $validator->messages()], 422);

        $validate = $validator->validate();
        /* VALIDATION REQUEST AND GET */
        
        /* UPDATE USER */
        $user->jenis_kelamin = $request->jenis_kelamin;
        $user->tanggal_lahir = $request->tanggal_lahir;
        $user->phone = $validate['phone'];
        // $user->alamat = $request->alamat;
        $user->save();
        /* UPDATE USER */

        return response()->json(['status' => 200, 'message' => 'User Update Successfully', 'user' => $user], 200);
    }
}
