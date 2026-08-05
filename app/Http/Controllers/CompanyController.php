<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Models\Alamat;
use App\Models\Company;
use App\Models\User;
use App\Services\AlamatService;
use App\Services\AuditLogService;
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
     * @param  AuditLogService  $auditLogService  Service yang membatasi context dan metadata audit.
     *
     * @return void  Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(
        protected CompanyService $companyService,
        protected AlamatService $alamatService,
        protected AuditLogService $auditLogService,
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
     * tidak menggunakan alamat yang belum terverifikasi. Audit dicatat dalam transaksi yang sama.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function updateCompany(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi user
        $user = $request->user();
        $user_id = optional($user)->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $user || ! $userExists) {
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
        $beforeValues = $this->currentCompanySnapshot((string) $user_id);

        // --- step 3 - start - perbarui profil dan alamat toko bersama audit secara atomik
        DB::transaction(function () use ($user, $user_id, $request, $locationAttributes, $beforeValues): void {
            User::where('id', $user_id)->lockForUpdate()->first();

            $company = Company::updateOrCreate(
                ['user_id' => $user_id],
                [
                    'name' => $request->name ?? '',
                    'email' => $request->email ?? '',
                    'phone' => $request->phone ?? '',
                    'description' => $request->description ?? '',
                ]
            );

            $sellerAddress = Alamat::updateOrCreate(
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

            $this->auditLogService->recordCompanyUpdated(
                $user,
                $request,
                $company,
                $this->companyChanges($beforeValues, $company, $sellerAddress),
                $sellerAddress,
            );
        });
        // --- step 3 - end - perbarui profil dan alamat toko bersama audit secara atomik

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
     * dibersihkan setelah transaksi database dan audit berhasil agar kegagalan upload tidak
     * meninggalkan profil tanpa gambar yang valid.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi user
        $user = $request->user();
        $user_id = optional($user)->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $user || ! $userExists) {
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

        // --- step 3 - start - unggah file baru sebelum mengganti referensi toko
        $previousImage = Company::where('user_id', $user_id)->value('img');
        $filename = $user_id.'-'.Carbon::now()->timestamp.'.'.$request->file('file')->getClientOriginalExtension();
        $path = Storage::disk('public')->putFileAs('company-imgs', $request->file('file'), $filename);
        // --- step 3 - end - unggah file baru sebelum mengganti referensi toko

        // --- step 4 - start - ganti referensi gambar dan catat audit secara atomik
        try {
            DB::transaction(function () use ($user, $user_id, $path, $request): void {
                $company = Company::updateOrCreate(
                    ['user_id' => $user_id],
                    ['img' => $path]
                );
                $sellerAddress = Alamat::query()
                    ->where('user_id', $user_id)
                    ->where('type', 'seller')
                    ->first();

                $this->auditLogService->recordCompanyImageChanged(
                    $user,
                    $request,
                    $company,
                    AuditEvent::COMPANY_IMAGE_UPLOADED,
                    $sellerAddress,
                );
            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($path);

            throw $exception;
        }
        // --- step 4 - end - ganti referensi gambar dan catat audit secara atomik

        // --- step 5 - start - bersihkan file sebelumnya setelah transaksi berhasil
        if ($previousImage && Storage::disk('public')->exists($previousImage)) {
            Storage::disk('public')->delete($previousImage);
        }
        // --- step 5 - end - bersihkan file sebelumnya setelah transaksi berhasil

        // --- step 6 - start - ambil profil toko
        $getCompany = $this->companyService->getCompany($user_id);
        $company = $getCompany['company'];
        // --- step 6 - end - ambil profil toko

        return response()->json(['status' => 'success', 'message' => 'Foto toko berhasil diunggah.', 'company' => $company], 200);
    }

    /**
     * Menghapus gambar profil toko seller.
     *
     * Function membatasi penghapusan ke perusahaan milik user aktif, mengosongkan referensi database
     * bersama audit, lalu membersihkan file terkait dari storage. State perusahaan terbaru
     * dikembalikan kepada frontend.
     *
     * @param  Request  $request  Request terautentikasi beserta metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function deleteImage(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi user
        $user = $request->user();
        $user_id = optional($user)->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $user || ! $userExists) {
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

        // --- step 3 - start - pastikan file aktif tersedia sebelum mengubah profil toko
        $previousImage = $company->img;
        if (! $previousImage || ! Storage::disk('public')->exists($previousImage)) {
            return response()->json(['status' => 'error', 'message' => 'File foto toko tidak ditemukan.'], 400);
        }
        // --- step 3 - end - pastikan file aktif tersedia sebelum mengubah profil toko

        // --- step 4 - start - hapus referensi gambar dan catat audit secara atomik
        DB::transaction(function () use ($user, $company, $request): void {
            $company->img = null;
            $company->save();

            $sellerAddress = Alamat::query()
                ->where('user_id', $company->user_id)
                ->where('type', 'seller')
                ->first();

            $this->auditLogService->recordCompanyImageChanged(
                $user,
                $request,
                $company,
                AuditEvent::COMPANY_IMAGE_DELETED,
                $sellerAddress,
            );
        });
        // --- step 4 - end - hapus referensi gambar dan catat audit secara atomik

        // --- step 5 - start - hapus file setelah perubahan database dan audit berhasil
        Storage::disk('public')->delete($previousImage);
        // --- step 5 - end - hapus file setelah perubahan database dan audit berhasil

        return response()->json(['status' => 'success', 'message' => 'Foto toko berhasil dihapus.', 'company' => $company], 200);
    }

    /**
     * Membandingkan snapshot Profil Toko sebelum dan sesudah disimpan.
     *
     * Field yang tetap sama tidak dicatat di daftar perubahan, tetapi snapshot akhir tetap memungkinkan
     * UI menampilkannya sebagai status Tetap. Koordinat dan place id tidak pernah masuk daftar ini.
     *
     * @param  array{name: string, email: string, phone: string, description: string, formatted_address: string, address_detail: string, has_company_image: bool}  $beforeValues  Snapshot sebelum mutasi.
     * @param  Company  $company  Profil toko setelah mutasi disimpan.
     * @param  Alamat|null  $sellerAddress  Alamat seller setelah mutasi disimpan.
     *
     * @return array<int, array{field: string, label: string, before: mixed, after: mixed}> Daftar field yang benar-benar berubah.
     */
    private function companyChanges(array $beforeValues, Company $company, ?Alamat $sellerAddress): array
    {
        // --- step 1 - start - siapkan snapshot sesudah perubahan dan label field yang diaudit
        $afterValues = $this->auditLogService->companySnapshot($company, $sellerAddress);
        $labels = [
            'name' => 'Nama Toko',
            'email' => 'Email',
            'phone' => 'Nomor Telepon',
            'description' => 'Deskripsi',
            'formatted_address' => 'Lokasi',
            'address_detail' => 'Detail Alamat',
        ];
        $changes = [];
        // --- step 1 - end - siapkan snapshot sesudah perubahan dan label field yang diaudit

        // --- step 2 - start - kumpulkan hanya nilai before dan after yang berbeda
        foreach ($labels as $field => $label) {
            if ($beforeValues[$field] === $afterValues[$field]) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => $label,
                'before' => $beforeValues[$field],
                'after' => $afterValues[$field],
            ];
        }
        // --- step 2 - end - kumpulkan hanya nilai before dan after yang berbeda

        return $changes;
    }

    /**
     * Membentuk snapshot Profil Toko saat ini sebelum mutasi, termasuk state kosong untuk toko baru.
     *
     * @param  string  $userId  ID user lokal pemilik profil toko.
     *
     * @return array{name: string, email: string, phone: string, description: string, formatted_address: string, address_detail: string, has_company_image: bool} Snapshot allow-listed sebelum perubahan.
     */
    private function currentCompanySnapshot(string $userId): array
    {
        $company = Company::query()
            ->where('user_id', $userId)
            ->first();
        $sellerAddress = Alamat::query()
            ->where('user_id', $userId)
            ->where('type', 'seller')
            ->first();

        if (! $company) {
            return [
                'name' => '',
                'email' => '',
                'phone' => '',
                'description' => '',
                'formatted_address' => (string) ($sellerAddress?->formatted_address ?? ''),
                'address_detail' => (string) ($sellerAddress?->address_detail ?? ''),
                'has_company_image' => false,
            ];
        }

        return $this->auditLogService->companySnapshot($company, $sellerAddress);
    }
}
