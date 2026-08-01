<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Menghapus gambar profil pengguna yang terautentikasi.
     *
     * Function memastikan request berasal dari user terautentikasi, menghapus referensi gambar pada
     * profil, lalu membersihkan file storage terkait. Response error dikembalikan sebelum mutasi
     * ketika identitas atau payload tidak valid.
     *
     * @param  Request  $request  Request pengguna terautentikasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function deleteImage(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi request dan ambil data
        $validator = Validator::make($request->all(), [
            'img' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);
        }

        $validate = $validator->validate();
        // --- step 1 - end - validasi request dan ambil data

        // --- step 2 - start - validasi user terautentikasi
        $user = $request->user();

        if (! $user) {
            return response()->json(['status' => 404, 'message' => 'User Not Found'], 404);
        }

        if ($user->img !== $validate['img']) {
            return response()->json(['status' => 403, 'message' => 'Forbidden'], 403);
        }
        // --- step 2 - end - validasi user terautentikasi

        // --- step 3 - start - hapus gambar lama jika tersedia
        if ($validate['img']) {
            if (Storage::disk('public')->exists($validate['img'])) {
                // --- step 4 - start - perbarui data di database
                $user->img = null;
                $user->save();
                // --- step 4 - end - perbarui data di database

                Storage::disk('public')->delete($validate['img']);

                return response()->json(['status' => 200, 'message' => 'Foto profil berhasil dihapus.', 'user' => $user], 200);
            }
        }
        // --- step 3 - end - hapus gambar lama jika tersedia

        return response()->json(['status' => 404, 'message' => 'File foto profil tidak ditemukan.'], 404);
    }

    /**
     * Mengunggah dan menyimpan gambar profil pengguna.
     *
     * File divalidasi sebagai gambar yang didukung sebelum disimpan. Gambar lama dibersihkan setelah
     * path baru berhasil diperoleh sehingga profil tidak menunjuk file yang gagal dibuat.
     *
     * @param  Request  $request  File gambar profil.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi request
        $validator = Validator::make(
            $request->all(),
            [
                'id' => ['required', 'uuid'],
                'file' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,svg', 'max:1024'],
            ],
            [
                'file.required' => 'Gambar wajib dipilih.',
                'file.image' => 'File harus berupa gambar.',
                'file.mimes' => 'File harus berformat jpeg, png, jpg, gif, atau svg.',
                'file.max' => 'Ukuran gambar tidak boleh lebih dari 1024 KB.',
            ]
        );

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);
        }

        $validate = $validator->validate();
        // --- step 1 - end - validasi request

        // --- step 2 - start - validasi user terautentikasi
        $user = $request->user();

        if (! $user) {
            return response()->json(['status' => 404, 'message' => 'User Not Found'], 404);
        }

        if ((string) $user->id !== (string) $validate['id']) {
            return response()->json(['status' => 403, 'message' => 'Forbidden'], 403);
        }
        // --- step 2 - end - validasi user terautentikasi

        // --- step 3 - start - hapus gambar lama jika tersedia
        if ($user->img) {
            if (Storage::disk('public')->exists($user->img)) {
                Storage::disk('public')->delete($user->img);
            }
        }
        // --- step 3 - end - hapus gambar lama jika tersedia

        // --- step 4 - start - unggah gambar dan perbarui database
        $filename = $request->id.'-'.Carbon::now()->timestamp.'.'.$request->file('file')->getClientOriginalExtension();
        $path = Storage::disk('public')->putFileAs('user-imgs', $request->file('file'), $filename);

        $user->img = $path;
        $user->save();
        // --- step 4 - end - unggah gambar dan perbarui database

        return response()->json(['status' => 200, 'message' => 'Foto profil berhasil diunggah.', 'user' => $user], 200);
    }

    /**
     * Menampilkan profil pengguna yang terautentikasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function show(): JsonResponse
    {
        $id = auth()->user()->id;

        $user = User::where('id', $id)
            ->first();

        return ($user) ?
               response()->json(['status' => 200, 'user' => $user], 200) :
               response()->json(['status' => 404, 'message' => 'User Not Found'], 404);
    }

    /**
     * Memperbarui profil pengguna yang sesuai dengan identitas terautentikasi.
     *
     * Identitas pada route dan session harus merujuk user yang sama sebelum data profil divalidasi.
     * Hanya field yang diizinkan yang diperbarui dan response mengembalikan representasi user terbaru.
     *
     * @param  Request  $request  Data profil terbaru.
     * @param  string  $id  ID pengguna.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function updateUser(Request $request, string $id): JsonResponse
    {
        // --- step 1 - start - validasi id route
        $routeIdValidator = Validator::make(['id' => $id], [
            'id' => ['required', 'uuid'],
        ]);

        if ($routeIdValidator->fails()) {
            return response()->json(['status' => 422, 'result' => 'error', 'message' => $routeIdValidator->messages()], 422);
        }

        $validatedRouteId = $routeIdValidator->validate()['id'];
        // --- step 1 - end - validasi id route

        // --- step 2 - start - validasi user terautentikasi
        $user = $request->user();

        if (! $user) {
            return response()->json(['status' => 404, 'message' => 'User Not Found'], 404);
        }

        if ((string) $user->id !== (string) $validatedRouteId) {
            return response()->json(['status' => 403, 'message' => 'Forbidden'], 403);
        }
        // --- step 2 - end - validasi user terautentikasi

        // --- step 3 - start - validasi request dan ambil data
        $validator = Validator::make(
            [
                'phone' => $request->phone,
            ],
            [
                'phone' => ['required', 'string', 'max:15', Rule::unique('users')->ignore($validatedRouteId)],
            ]
        );

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'result' => 'error', 'message' => $validator->messages()], 422);
        }

        $validate = $validator->validate();
        // --- step 3 - end - validasi request dan ambil data

        // --- step 4 - start - perbarui data user
        $user->jenis_kelamin = $request->jenis_kelamin;
        $user->tanggal_lahir = $request->tanggal_lahir;
        $user->phone = $validate['phone'];
        $user->save();
        // --- step 4 - end - perbarui data user

        return response()->json(['status' => 200, 'message' => 'User Update Successfully', 'user' => $user], 200);
    }
}
