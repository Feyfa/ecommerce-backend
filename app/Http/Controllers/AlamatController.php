<?php

namespace App\Http\Controllers;

use App\Models\Alamat;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AlamatController extends Controller
{
    public function getAlamatBuyer(Request $request)
    {
        /* VALIDATION USER */
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if(!$userExists)
            return response()->json(['result' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER */

        /* GET LIST ALAMAT */
        $alamats = Alamat::where('user_id', $user_id)
                         ->where('type', 'buyer');

        if(!empty($request->searchAlamat) && trim($request->searchAlamat) != '') 
        {
            $searchAlamat = $request->searchAlamat;
            $alamats->where(function ($query) use ($searchAlamat) {
                $query->where('place', 'LIKE', "%{$searchAlamat}%")
                      ->orWhere('name', 'LIKE', "%{$searchAlamat}%")
                      ->orWhere('phone', 'LIKE', "%{$searchAlamat}%")
                      ->orWhere('alamat', 'LIKE', "%{$searchAlamat}%");
            });
        }

        $alamats = $alamats->orderBy('enable', 'DESC')->limit(5) 
                           ->get();
        /* GET LIST ALAMAT */

        return response()->json(['result' => 'suceess', 'alamats' => $alamats]);
    }

    public function addAlamatBuyer(Request $request)
    {
        /* VALIDATION USER */
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if(!$userExists)
            return response()->json(['result' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER */

        /* VALIDATION PARAMETER REQUEST */
        $validator = Validator::make($request->all(), [
            'place' => ['required'],
            'name' => ['required'],
            'phone' => ['required'],
            'alamat' => ['required'],
            'enable' => ['required'],
        ]);

        if($validator->fails())
            return response()->json(['result' => 'error', 'message' => $validator->messages()], 422);
        /* VALIDATION PARAMETER REQUEST */

        /* VALIDATION MAX 5 ALAMAT */
        $totalAlamat = Alamat::where('user_id', $user_id)
                             ->where('type', 'buyer')
                             ->count();
        // info(['totalAlamat' => $totalAlamat, 'user_id' => $user_id  ]);
        if($totalAlamat >= 5)
            return response()->json(['result' => 'error', 'message' => 'Alamat Tidak Boleh Lebih Dari 5'], 400);    
        /* VALIDATION MAX 5 ALAMAT */

        if($request->enable == true)
        {
            Alamat::where('user_id', $user_id)
                  ->where('type', 'buyer')
                  ->where('enable', 1)
                  ->update(['enable' => 0]);
        }
        if($totalAlamat == 0)
        {
            $request->merge(['enable' => 1]);
        }
                  
        Alamat::create([
            'user_id' => $user_id,
            'type' => 'buyer',
            'place' => $request->place,
            'name' => $request->name,
            'phone' => $request->phone,
            'alamat' => $request->alamat,
            'enable' => $request->enable,
        ]);

       /* GET LIST ALAMAT */
       $alamats = Alamat::where('user_id', $user_id)
                        ->where('type', 'buyer');

       if(!empty($request->searchAlamat) && trim($request->searchAlamat) != '') 
       {
           $searchAlamat = $request->searchAlamat;
           $alamats->where(function ($query) use ($searchAlamat) {
               $query->where('place', 'LIKE', "%{$searchAlamat}%")
                     ->orWhere('name', 'LIKE', "%{$searchAlamat}%")
                     ->orWhere('phone', 'LIKE', "%{$searchAlamat}%")
                     ->orWhere('alamat', 'LIKE', "%{$searchAlamat}%");
           });
       }

       $alamats = $alamats->orderBy('enable', 'DESC') 
                          ->get();
       /* GET LIST ALAMAT */

        return response()->json(['result' => 'success', 'alamats' => $alamats, 'message' => 'Alamat Berhasil Ditambah']);
    }

    public function deleteAlamatBuyer(string $id, Request $request)
    {
        /* VALIDATION USER */
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if(!$userExists)
            return response()->json(['result' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATION USER */

        /* DELETE ALAMAT */
        Alamat::where('id', $id)
              ->delete(); 
        /* DELETE ALAMAT */

        /* FORCE ENABLE 1 WHEN TOTAL ALAMAT 1 */
        $enableAlamatExist = Alamat::where('user_id', $user_id)
                                   ->where('enable', 1)
                                   ->exists();
        
        if(!$enableAlamatExist)
        {
            Alamat::where('user_id', $user_id)
                  ->where('type', 'buyer')
                  ->orderBy('id', 'DESC')  
                  ->first()?->update(['enable' => 1]);  
        }
        /* FORCE ENABLE 1 WHEN TOTAL ALAMAT 1 */

       /* GET LIST ALAMAT */
       $alamats = Alamat::where('user_id', $user_id)
                        ->where('type', 'buyer');

       if(!empty($request->searchAlamat) && trim($request->searchAlamat) != '') 
       {
           $searchAlamat = $request->searchAlamat;
           $alamats->where(function ($query) use ($searchAlamat) {
               $query->where('place', 'LIKE', "%{$searchAlamat}%")
                     ->orWhere('name', 'LIKE', "%{$searchAlamat}%")
                     ->orWhere('phone', 'LIKE', "%{$searchAlamat}%")
                     ->orWhere('alamat', 'LIKE', "%{$searchAlamat}%");
           });
       }

       $alamats = $alamats->orderBy('enable', 'DESC') 
                          ->get();
       /* GET LIST ALAMAT */


        return response()->json(['result' => 'success', 'alamats' => $alamats, 'message' => 'Alamat Berhasil Dihapus']);
    }

    public function setEnableAlamatBuyer(string $id, Request $request)
    {
        /* VALIDATOR USER */
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if(!$userExists)
            return response()->json(['result' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATOR USER */

        /* CHECK ALAMAT EXISTS */
        $alamat = Alamat::where('user_id', $user_id)
                        ->where('type', 'buyer')
                        ->where('id', $id)
                        ->exists();

        if(!$alamat) 
            return response()->json(['result' => 'error', 'message' => 'Alamat Tidak Ditemukan'], 400);
        /* CHECK ALAMAT EXISTS */

        /* UPDATE DISABLE ALL ALAMAT */
        Alamat::where('user_id', $user_id)
              ->where('type', 'buyer')  
              ->update(['enable' => 0]);
        /* UPDATE DISABLE ALL ALAMAT */

        /* ENABLE ALAMAT */
        Alamat::where('user_id', $user_id)
              ->where('type', 'buyer')
              ->where('id', $id)
              ->update(['enable' => 1]);
        /* ENABLE ALAMAT */

       /* GET LIST ALAMAT */
       $alamats = Alamat::where('user_id', $user_id)
                        ->where('type', 'buyer');

       if(!empty($request->searchAlamat) && trim($request->searchAlamat) != '') 
       {
           $searchAlamat = $request->searchAlamat;
           $alamats->where(function ($query) use ($searchAlamat) {
               $query->where('place', 'LIKE', "%{$searchAlamat}%")
                     ->orWhere('name', 'LIKE', "%{$searchAlamat}%")
                     ->orWhere('phone', 'LIKE', "%{$searchAlamat}%")
                     ->orWhere('alamat', 'LIKE', "%{$searchAlamat}%");
           });
       }

       $alamats = $alamats->orderBy('enable', 'DESC') 
                          ->get();
        /* GET LIST ALAMAT */

        /* GET CURRENT ALAMAT */
        $currentAlamat = Alamat::where('user_id', $user_id)
                               ->where('type', 'buyer')
                               ->where('enable', 1)
                               ->first();
        /* GET CURRENT ALAMAT */

        return response()->json(['result' => 'success', 'alamats' => $alamats, 'currentAlamat' => $currentAlamat, 'message' => 'Alamat Berhasil Dipilih']);
    }

    public function updateAlamatBuyer(string $id, Request $request)
    {
        /* VALIDATOR USER */
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if(!$userExists)
            return response()->json(['result' => 'error', 'message' => 'Unauthorized'], 401);
        /* VALIDATOR USER */

        /* VALIDATION PARAMETER REQUEST */
        $validator = Validator::make($request->all(), [
            'place' => ['required'],
            'name' => ['required'],
            'phone' => ['required'],
            'alamat' => ['required'],
        ]);

        if($validator->fails())
            return response()->json(['result' => 'error', 'message' => $validator->messages()], 422);
        /* VALIDATION PARAMETER REQUEST */

        /* CHECK ALAMAT EXISTS */
        $alamat = Alamat::where('user_id', $user_id)
                        ->where('type', 'buyer')
                        ->where('id', $id)
                        ->first();

        if(empty($alamat)) 
            return response()->json(['result' => 'error', 'message' => 'Alamat Tidak Ditemukan'], 400);
        /* CHECK ALAMAT EXISTS */

        /* UPDATE ALAMAT */
        $alamat->place = $request->place;
        $alamat->name = $request->name;
        $alamat->phone = $request->phone;
        $alamat->alamat = $request->alamat;
        $alamat->save();
        /* UPDATE ALAMAT */

        /* GET LIST ALAMAT */
        $alamats = Alamat::where('user_id', $user_id)
                         ->where('type', 'buyer');

        if(!empty($request->searchAlamat) && trim($request->searchAlamat) != '') 
        {
            $searchAlamat = $request->searchAlamat;
            $alamats->where(function ($query) use ($searchAlamat) {
                $query->where('place', 'LIKE', "%{$searchAlamat}%")
                        ->orWhere('name', 'LIKE', "%{$searchAlamat}%")
                        ->orWhere('phone', 'LIKE', "%{$searchAlamat}%")
                        ->orWhere('alamat', 'LIKE', "%{$searchAlamat}%");
            });
        }

        $alamats = $alamats->orderBy('enable', 'DESC') 
                           ->get();
        /* GET LIST ALAMAT */

        return response()->json(['result' => 'success', 'alamats' => $alamats, 'message' => 'Alamat Berhasil Diubah']);
    }
}
