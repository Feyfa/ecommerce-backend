<?php

namespace App\Http\Controllers;

use App\Models\Alamat;
use App\Models\Company;
use App\Models\User;
use App\Services\AlamatService;
use App\Services\CompanyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class CompanyController extends Controller
{
    /**
     * Menyiapkan controller dengan layanan profil toko dan audit log.
     *
     * @param  CompanyService  $companyService  Service company yang digunakan oleh class ini.
     * @param  AlamatService  $alamatService  Service alamat yang digunakan oleh class ini.
     *
     * @return void  Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(
        protected CompanyService $companyService,
        protected AlamatService $alamatService,
    ) {}

    /**
     * Menampilkan profil toko pengguna yang terautentikasi.
     *
     * Identitas user terautentikasi digunakan sebagai satu-satunya scope pembacaan profil toko.
     * Response menggabungkan data perusahaan dan alamat seller yang relevan untuk halaman pengaturan.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function show(): JsonResponse
    {
        // --- step 1 - start - validasi user
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi user

        // --- step 2 - start - ambil profil toko
        $getCompany = $this->companyService->getCompany($user_id);
        $company = $getCompany['company'];
        // --- step 2 - end - ambil profil toko

        return response()->json(['status' => 'success', 'company' => $company]);
    }

    /**
     * Memperbarui profil dan lokasi toko seller.
     *
     * Data perusahaan dan pinpoint seller divalidasi sebelum perubahan disimpan. Lokasi diverifikasi
     * melalui service alamat, dan pembaruan terkait dilakukan secara konsisten agar produk seller
     * tidak menggunakan alamat yang belum terverifikasi.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function updateCompany(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi user
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi user

        // --- step 2 - start - validasi request dan ambil data
        $validator = Validator::make(
            [
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'alamat' => $request->alamat,
                'location_source' => $request->location_source,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'geoapify_place_id' => $request->geoapify_place_id,
                'formatted_address' => $request->formatted_address,
                'address_detail' => $request->address_detail,
            ],
            array_merge([
                'name' => ['required', 'string'],
                'email' => [
                    'required', 'string', 'max:255', 'email',
                    function ($attribute, $value, $fail) use ($user_id) {
                        $userExists = User::where('id', '<>', $user_id)
                            ->where('email', $value)
                            ->exists();
                        $companyExists = Company::where('user_id', '<>', $user_id)
                            ->where('email', $value)
                            ->exists();
                        if ($userExists || $companyExists) {
                            $fail('Email Already Exists');
                        }
                    },
                ],
                'phone' => [
                    'required', 'string', 'max:20',
                    function ($attribute, $value, $fail) use ($user_id) {
                        $userExists = User::where('id', '<>', $user_id)
                            ->where('phone', $value)
                            ->exists();
                        $companyExists = Company::where('user_id', '<>', $user_id)
                            ->where('phone', $value)
                            ->exists();
                        if ($userExists || $companyExists) {
                            $fail('Phone Already Exists');
                        }
                    },
                ],
            ], $this->alamatService->locationRules())
        );

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => $validator->messages()], 422);
        }
        // --- step 2 - end - validasi request dan ambil data

        // Verifikasi pinpoint sebelum mengubah profil agar kegagalan provider
        // tidak menghasilkan pembaruan data toko yang hanya tersimpan sebagian.
        $locationAttributes = $this->alamatService->locationAttributes($request);

        // --- step 3 - start - perbarui profil dan alamat toko secara atomik
        DB::transaction(function () use ($user_id, $request, $locationAttributes): void {
            User::where('id', $user_id)->lockForUpdate()->first();

            Company::updateOrCreate(
                ['user_id' => $user_id],
                [
                    'name' => $request->name ?? '',
                    'email' => $request->email ?? '',
                    'phone' => $request->phone ?? '',
                    'description' => $request->description ?? '',
                ]
            );

            Alamat::updateOrCreate(
                [
                    'user_id' => $user_id,
                    'type' => 'seller',
                ],
                array_merge([
                    'user_id' => $user_id,
                    'type' => 'seller',
                    'enable' => 1,
                ], $locationAttributes)
            );
        });
        // --- step 3 - end - perbarui profil dan alamat toko secara atomik

        // --- step 4 - start - ambil profil toko
        $getCompany = $this->companyService->getCompany($user_id);
        $company = $getCompany['company'];
        // --- step 4 - end - ambil profil toko

        return response()->json(['status' => 'success', 'message' => 'Company Update Successfully', 'company' => $company], 200);
    }

    /**
     * Mengunggah gambar profil toko seller.
     *
     * Gambar toko divalidasi dan disimpan untuk perusahaan milik user terautentikasi. Referensi lama
     * dibersihkan hanya dalam alur yang aman agar kegagalan upload tidak meninggalkan profil tanpa
     * gambar yang valid.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi user
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi user

        // --- step 2 - start - validasi request
        $validator = Validator::make(
            $request->all(),
            [
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
            return response()->json(['status' => 'error', 'message' => $validator->messages()], 422);
        }
        // --- step 2 - end - validasi request

        // --- step 3 - start - ambil profil toko
        $companyImg = Company::where('user_id', $user_id)->value('img');
        // --- step 3 - end - ambil profil toko

        // --- step 4 - start - hapus gambar lama jika tersedia
        if ($companyImg) {
            if (Storage::disk('public')->exists($companyImg)) {
                Storage::disk('public')->delete($companyImg);
            }
        }
        // --- step 4 - end - hapus gambar lama jika tersedia

        // --- step 5 - start - unggah gambar dan perbarui database
        $filename = $user_id.'-'.Carbon::now()->timestamp.'.'.$request->file('file')->getClientOriginalExtension();
        $path = Storage::disk('public')->putFileAs('company-imgs', $request->file('file'), $filename);

        Company::updateOrCreate(
            ['user_id' => $user_id],
            ['img' => $path]
        );
        // --- step 5 - end - unggah gambar dan perbarui database

        // --- step 6 - start - ambil profil toko
        $getCompany = $this->companyService->getCompany($user_id);
        $company = $getCompany['company'];
        // --- step 6 - end - ambil profil toko

        return response()->json(['status' => 'success', 'message' => 'Foto toko berhasil diunggah.', 'company' => $company], 200);
    }

    /**
     * Menghapus gambar profil toko seller.
     *
     * Function membatasi penghapusan ke perusahaan milik user aktif, mengosongkan referensi database,
     * lalu membersihkan file terkait dari storage. State perusahaan terbaru dikembalikan kepada
     * frontend.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function deleteImage(): JsonResponse
    {
        // --- step 1 - start - validasi user
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi user

        // --- step 2 - start - ambil profil toko
        $company = Company::where('user_id', $user_id)
            ->first();
        if (! $company) {
            return response()->json(['status' => 'error', 'message' => 'Company Is Empty'], 400);
        }
        // --- step 2 - end - ambil profil toko

        // --- step 3 - start - hapus gambar dari storage dan database
        if (! Storage::disk('public')->exists(($company->img ?? ''))) {
            return response()->json(['status' => 'error', 'message' => 'File foto toko tidak ditemukan.'], 400);
        }

        Storage::disk('public')->delete(($company->img ?? ''));
        $company->img = null;
        $company->save();
        // --- step 3 - end - hapus gambar dari storage dan database

        return response()->json(['status' => 'success', 'message' => 'Foto toko berhasil dihapus.', 'company' => $company], 200);
    }
}
