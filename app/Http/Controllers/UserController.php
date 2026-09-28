<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

class UserController extends Controller
{
    /**
     * Menyiapkan service audit untuk mencatat perubahan profil yang sukses.
     *
     * @param  AuditLogService  $auditLogService  Service yang membatasi context dan metadata audit.
     *
     * @return void Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(
        private readonly AuditLogService $auditLogService
    ) {}

    /**
     * Menghapus gambar profil pengguna yang terautentikasi.
     *
     * Function memastikan request berasal dari user terautentikasi, menghapus referensi gambar pada
     * profil, lalu membersihkan file storage terkait. Response error dikembalikan sebelum mutasi
     * ketika identitas atau payload tidak valid.
     *
     * @param  Request  $request  Request pengguna terautentikasi.
     *
     * @return JsonResponse Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
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

        // --- step 3 - start - pastikan file aktif tersedia sebelum mengubah profil
        if ($validate['img'] && Storage::disk('public')->exists($validate['img'])) {
            // --- step 4 - start - hapus referensi gambar dan catat audit secara atomik
            DB::transaction(function () use ($user, $request): void {
                $user->img = null;
                $user->save();

                $this->auditLogService->recordProfileImageChanged(
                    $user,
                    $request,
                    AuditEvent::PROFILE_IMAGE_DELETED,
                );
            });
            // --- step 4 - end - hapus referensi gambar dan catat audit secara atomik

            // --- step 5 - start - hapus file setelah perubahan database dan audit berhasil
            Storage::disk('public')->delete($validate['img']);
            // --- step 5 - end - hapus file setelah perubahan database dan audit berhasil

            return response()->json(['status' => 200, 'message' => 'Foto profil berhasil dihapus.', 'user' => $user], 200);
        }
        // --- step 3 - end - pastikan file aktif tersedia sebelum mengubah profil

        return response()->json(['status' => 404, 'message' => 'File foto profil tidak ditemukan.'], 404);
    }

    /**
     * Mengunggah dan menyimpan gambar profil pengguna.
     *
     * Hanya JPEG, PNG, dan GIF yang diterima; Laravel membuat path unik dan menentukan ekstensi
     * dari isi file yang telah divalidasi pada disk publik. Gambar lama dibersihkan setelah
     * transaksi berhasil agar rollback dan pembersihan tidak menghapus foto aktif.
     *
     * @param  Request  $request  File gambar profil.
     *
     * @return JsonResponse Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     *
     * @throws RuntimeException Ketika file gambar tidak dapat disimpan sebelum profil diubah.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi request
        $validator = Validator::make(
            $request->all(),
            [
                'id' => ['required', 'uuid'],
                'file' => ['required', 'image', 'mimes:jpeg,png,jpg,gif', 'max:1024'],
            ],
            [
                'file.required' => 'Gambar wajib dipilih.',
                'file.image' => 'File harus berupa gambar.',
                'file.mimes' => 'File harus berformat jpeg, png, jpg, atau gif.',
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

        if (! $user instanceof User) {
            return response()->json(['status' => 404, 'message' => 'User Not Found'], 404);
        }

        if ((string) $user->id !== (string) $validate['id']) {
            return response()->json(['status' => 403, 'message' => 'Forbidden'], 403);
        }
        // --- step 2 - end - validasi user terautentikasi

        // --- step 3 - start - unggah file baru sebelum mengganti referensi profil
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            $validator->errors()->add('file', 'File harus berupa gambar.');

            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);
        }

        $previousImage = $user->img;
        // A new uploaded file gets its own random path, keeping cleanup away from the active image.
        $path = $file->store('user-imgs', 'public');
        if ($path === false) {
            throw new RuntimeException('Profile image could not be stored.');
        }
        // --- step 3 - end - unggah file baru sebelum mengganti referensi profil

        // --- step 4 - start - ganti referensi gambar dan catat audit secara atomik
        try {
            DB::transaction(function () use ($user, $path, $request): void {
                $user->img = $path;
                $user->save();

                $this->auditLogService->recordProfileImageChanged(
                    $user,
                    $request,
                    AuditEvent::PROFILE_IMAGE_UPLOADED,
                );
            });
        } catch (\Throwable $exception) {
            if ($path !== $previousImage) {
                Storage::disk('public')->delete($path);
            }

            throw $exception;
        }
        // --- step 4 - end - ganti referensi gambar dan catat audit secara atomik

        // --- step 5 - start - bersihkan file sebelumnya setelah transaksi berhasil
        if ($previousImage && $previousImage !== $path && Storage::disk('public')->exists($previousImage)) {
            Storage::disk('public')->delete($previousImage);
        }
        // --- step 5 - end - bersihkan file sebelumnya setelah transaksi berhasil

        return response()->json(['status' => 200, 'message' => 'Foto profil berhasil diunggah.', 'user' => $user], 200);
    }

    /**
     * Menampilkan profil pengguna yang terautentikasi.
     *
     * @return JsonResponse Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function show(): JsonResponse
    {
        $authUser = auth()->user();

        if (! $authUser instanceof User) {
            return response()->json(['status' => 404, 'message' => 'User Not Found'], 404);
        }

        $user = User::where('id', $authUser->id)
            ->first();

        return ($user) ?
               response()->json(['status' => 200, 'user' => $user], 200) :
               response()->json(['status' => 404, 'message' => 'User Not Found'], 404);
    }

    /**
     * Memperbarui profil pengguna yang sesuai dengan identitas terautentikasi.
     *
     * Identitas pada route dan session harus merujuk user yang sama sebelum data profil divalidasi.
     * Field profil opsional divalidasi sebagai string atau tanggal sebelum disimpan, dan nilai kosong
     * mengosongkan field terkait. Response mengembalikan representasi user terbaru.
     *
     * @param  Request  $request  Data profil terbaru.
     * @param  string  $id  ID pengguna.
     *
     * @return JsonResponse Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
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

        $validatedRouteId = $routeIdValidator->validated()['id'];
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
        $gender = $request->input('jenis_kelamin');
        $birthDate = $request->input('tanggal_lahir');

        // Empty optional fields clear their stored values, even when request middleware is bypassed.
        $validator = Validator::make(
            [
                'phone' => $request->phone,
                'jenis_kelamin' => $gender === '' ? null : $gender,
                'tanggal_lahir' => $birthDate === '' ? null : $birthDate,
            ],
            [
                'phone' => ['required', 'string', 'max:15', Rule::unique('users')->ignore($validatedRouteId)],
                'jenis_kelamin' => ['nullable', 'string', 'max:20'],
                'tanggal_lahir' => ['nullable', 'date_format:Y-m-d'],
            ]
        );

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'result' => 'error', 'message' => $validator->messages()], 422);
        }

        /** @var array{phone: string, jenis_kelamin: string|null, tanggal_lahir: string|null} $validate */
        $validate = $validator->validated();
        // --- step 3 - end - validasi request dan ambil data

        // --- step 4 - start - perbarui Pengaturan Pengguna bersama audit secara atomik
        $beforeValues = $this->auditLogService->profileSnapshot($user);

        DB::transaction(function () use ($user, $request, $validate, $beforeValues): void {
            $user->jenis_kelamin = $validate['jenis_kelamin'];
            $user->tanggal_lahir = $validate['tanggal_lahir'];
            $user->phone = $validate['phone'];
            $user->save();

            $this->auditLogService->recordProfileUpdated(
                $user,
                $request,
                $this->profileChanges($beforeValues, $user),
            );
        });
        // --- step 4 - end - perbarui Pengaturan Pengguna bersama audit secara atomik

        return response()->json(['status' => 200, 'message' => 'User Update Successfully', 'user' => $user], 200);
    }

    /**
     * Membandingkan snapshot Pengaturan Pengguna sebelum dan sesudah disimpan.
     *
     * Nama dan email tidak masuk karena dikelola autentikasi, bukan form Pengaturan Pengguna. Field
     * yang tetap sama tidak dicatat di daftar perubahan, tetapi snapshot akhir tetap memungkinkan UI
     * menampilkannya sebagai status Tetap.
     *
     * @param  array{phone: string, tanggal_lahir: string|null, jenis_kelamin: string|null, has_profile_image: bool}  $beforeValues  Snapshot sebelum mutasi.
     * @param  User  $user  Profil setelah mutasi disimpan.
     *
     * @return array<int, array{field: string, label: string, before: mixed, after: mixed}> Daftar field yang benar-benar berubah.
     */
    private function profileChanges(array $beforeValues, User $user): array
    {
        // --- step 1 - start - siapkan snapshot sesudah perubahan dan label field yang diaudit
        $afterValues = $this->auditLogService->profileSnapshot($user);
        $labels = [
            'phone' => 'Nomor Telepon',
            'tanggal_lahir' => 'Tanggal Lahir',
            'jenis_kelamin' => 'Jenis Kelamin',
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
}
