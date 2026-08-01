<?php

namespace App\Http\Controllers;

use App\Models\Alamat;
use App\Models\User;
use App\Services\AlamatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AlamatController extends Controller
{
    /**
     * Menyiapkan controller dengan layanan alamat.
     *
     * @param  AlamatService  $alamatService  Layanan pengelolaan dan verifikasi alamat.
     *
     * @return void  Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(protected AlamatService $alamatService) {}

    /**
     * Menampilkan daftar alamat milik buyer.
     *
     * Query selalu dibatasi ke alamat buyer milik user terautentikasi. Pencarian diterapkan pada
     * label, penerima, telepon, dan alamat, kemudian alamat aktif diprioritaskan pada response.
     *
     * @param  Request  $request  Filter pencarian alamat buyer.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function getAlamatBuyer(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi user
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['result' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi user

        // --- step 2 - start - ambil daftar alamat
        $alamats = Alamat::where('user_id', $user_id)
            ->where('type', 'buyer');

        if (! empty($request->searchAlamat) && trim($request->searchAlamat) != '') {
            $searchAlamat = $request->searchAlamat;
            $alamats->where(function ($query) use ($searchAlamat) {
                $query->where('place', 'ILIKE', "%{$searchAlamat}%")
                    ->orWhere('name', 'ILIKE', "%{$searchAlamat}%")
                    ->orWhere('phone', 'ILIKE', "%{$searchAlamat}%")
                    ->orWhere('alamat', 'ILIKE', "%{$searchAlamat}%");
            });
        }

        $alamats = $alamats->orderBy('enable', 'DESC')->limit(5)
            ->get();
        // --- step 2 - end - ambil daftar alamat

        return response()->json(['result' => 'suceess', 'alamats' => $alamats]);
    }

    /**
     * Menambahkan alamat pengiriman buyer beserta data lokasinya.
     *
     * Payload lokasi diverifikasi melalui Geoapify sebelum alamat aktif yang lama diubah. Function
     * menegakkan batas lima alamat, mengaktifkan alamat pertama secara otomatis, dan memastikan
     * metadata lokasi yang disimpan berasal dari hasil verifikasi server.
     *
     * @param  Request  $request  Data alamat dan pinpoint buyer.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function addAlamatBuyer(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi user
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['result' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi user

        // --- step 2 - start - validasi parameter request
        $validator = Validator::make($request->all(), array_merge([
            'place' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20'],
            'enable' => ['required', 'boolean'],
        ], $this->alamatService->locationRules()));

        if ($validator->fails()) {
            return response()->json(['result' => 'error', 'message' => $validator->messages()], 422);
        }
        // --- step 2 - end - validasi parameter request

        // Verifikasi lokasi sebelum mengubah alamat aktif agar kegagalan provider
        // tidak membuat buyer kehilangan alamat yang sedang aktif.
        $locationAttributes = $this->alamatService->locationAttributes($request);

        // --- step 3 - start - simpan alamat dan status aktif secara atomik
        $addressLimitReached = DB::transaction(function () use ($user_id, $request, $locationAttributes): bool {
            // Lock parent user agar dua request alamat pertama atau keenam tidak dapat
            // melewati invariant hanya karena belum ada row alamat yang bisa dikunci.
            User::where('id', $user_id)->lockForUpdate()->first();
            $buyerAddresses = Alamat::where('user_id', $user_id)
                ->where('type', 'buyer')
                ->lockForUpdate()
                ->get();

            if ($buyerAddresses->count() >= 5) {
                return true;
            }

            $enableAddress = $buyerAddresses->isEmpty() || $request->boolean('enable');
            if ($enableAddress) {
                Alamat::whereIn('id', $buyerAddresses->pluck('id')->all())
                    ->update(['enable' => 0]);
            }

            Alamat::create(array_merge([
                'user_id' => $user_id,
                'type' => 'buyer',
                'place' => $request->place,
                'name' => $request->name,
                'phone' => $request->phone,
                'enable' => $enableAddress,
            ], $locationAttributes));

            return false;
        });

        if ($addressLimitReached) {
            return response()->json(['result' => 'error', 'message' => 'Alamat Tidak Boleh Lebih Dari 5'], 400);
        }
        // --- step 3 - end - simpan alamat dan status aktif secara atomik

        // --- step 4 - start - ambil daftar alamat
        $alamats = Alamat::where('user_id', $user_id)
            ->where('type', 'buyer');

        if (! empty($request->searchAlamat) && trim($request->searchAlamat) != '') {
            $searchAlamat = $request->searchAlamat;
            $alamats->where(function ($query) use ($searchAlamat) {
                $query->where('place', 'ILIKE', "%{$searchAlamat}%")
                    ->orWhere('name', 'ILIKE', "%{$searchAlamat}%")
                    ->orWhere('phone', 'ILIKE', "%{$searchAlamat}%")
                    ->orWhere('alamat', 'ILIKE', "%{$searchAlamat}%");
            });
        }

        $alamats = $alamats->orderBy('enable', 'DESC')
            ->get();
        // --- step 4 - end - ambil daftar alamat

        return response()->json(['result' => 'success', 'alamats' => $alamats, 'message' => 'Alamat Berhasil Ditambah']);
    }

    /**
     * Menghapus alamat pengiriman milik buyer.
     *
     * Alamat hanya dapat dihapus oleh buyer pemiliknya. Function menjaga invariant alamat aktif dan
     * mengembalikan daftar terbaru setelah penghapusan agar pengguna tidak melihat state lama.
     *
     * @param  string  $id  ID alamat.
     * @param  Request  $request  Request buyer terautentikasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function deleteAlamatBuyer(string $id, Request $request): JsonResponse
    {
        // --- step 1 - start - validasi user
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['result' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi user

        // --- step 2 - start - hapus alamat dan pilih fallback terverifikasi secara atomik
        // Batasi pencarian berdasarkan buyer terautentikasi agar UUID yang terekspos
        // tidak dapat digunakan untuk menghapus alamat milik user lain.
        $alamatDeleted = DB::transaction(function () use ($id, $user_id): bool {
            User::where('id', $user_id)->lockForUpdate()->first();
            $buyerAddresses = Alamat::where('user_id', $user_id)
                ->where('type', 'buyer')
                ->lockForUpdate()
                ->get();
            $alamat = $buyerAddresses->firstWhere('id', $id);

            if (! $alamat) {
                return false;
            }

            $alamat->delete();

            if (! $buyerAddresses->where('id', '!=', $id)->contains('enable', true)) {
                // Alamat manual legacy tidak boleh aktif otomatis karena checkout baru hanya
                // menerima lokasi yang telah diverifikasi melalui Pinpoint.
                $buyerAddresses
                    ->where('id', '!=', $id)
                    ->sortByDesc('created_at')
                    ->first(fn (Alamat $candidate) => $this->alamatService->isVerifiedPinpoint($candidate))
                    ?->update(['enable' => 1]);
            }

            return true;
        });

        if (! $alamatDeleted) {
            return response()->json(['result' => 'error', 'message' => 'Alamat Tidak Ditemukan'], 400);
        }
        // --- step 2 - end - hapus alamat dan pilih fallback terverifikasi secara atomik

        // --- step 3 - start - ambil daftar alamat
        $alamats = Alamat::where('user_id', $user_id)
            ->where('type', 'buyer');

        if (! empty($request->searchAlamat) && trim($request->searchAlamat) != '') {
            $searchAlamat = $request->searchAlamat;
            $alamats->where(function ($query) use ($searchAlamat) {
                $query->where('place', 'ILIKE', "%{$searchAlamat}%")
                    ->orWhere('name', 'ILIKE', "%{$searchAlamat}%")
                    ->orWhere('phone', 'ILIKE', "%{$searchAlamat}%")
                    ->orWhere('alamat', 'ILIKE', "%{$searchAlamat}%");
            });
        }

        $alamats = $alamats->orderBy('enable', 'DESC')
            ->get();
        // --- step 3 - end - ambil daftar alamat

        return response()->json(['result' => 'success', 'alamats' => $alamats, 'message' => 'Alamat Berhasil Dihapus']);
    }

    /**
     * Menetapkan alamat utama buyer.
     *
     * Function memverifikasi kepemilikan dan kelengkapan pinpoint sebelum memilih alamat utama. Alamat
     * aktif sebelumnya dinonaktifkan dalam scope user yang sama sehingga hanya satu alamat buyer yang
     * menjadi default.
     *
     * @param  string  $id  ID alamat yang diaktifkan.
     * @param  Request  $request  Request buyer terautentikasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function setEnableAlamatBuyer(string $id, Request $request): JsonResponse
    {
        // --- step 1 - start - validasi user
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['result' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi user

        // --- step 2 - start - pilih alamat terverifikasi secara atomik
        $selectionResult = DB::transaction(function () use ($user_id, $id): string {
            User::where('id', $user_id)->lockForUpdate()->first();
            $buyerAddresses = Alamat::where('user_id', $user_id)
                ->where('type', 'buyer')
                ->lockForUpdate()
                ->get();
            $alamat = $buyerAddresses->firstWhere('id', $id);

            if (! $alamat) {
                return 'missing';
            }

            if (! $this->alamatService->isVerifiedPinpoint($alamat)) {
                return 'unverified';
            }

            Alamat::whereIn('id', $buyerAddresses->pluck('id')->all())
                ->update(['enable' => 0]);
            $alamat->update(['enable' => 1]);

            return 'selected';
        });

        if ($selectionResult === 'missing') {
            return response()->json(['result' => 'error', 'message' => 'Alamat Tidak Ditemukan'], 400);
        }

        if ($selectionResult === 'unverified') {
            return response()->json([
                'result' => 'error',
                'code' => 'ADDRESS_REQUIRES_VERIFICATION',
                'message' => 'Verifikasi alamat dengan pinpoint sebelum memilihnya.',
            ], 409);
        }
        // --- step 2 - end - pilih alamat terverifikasi secara atomik

        // --- step 3 - start - ambil daftar alamat
        $alamats = Alamat::where('user_id', $user_id)
            ->where('type', 'buyer');

        if (! empty($request->searchAlamat) && trim($request->searchAlamat) != '') {
            $searchAlamat = $request->searchAlamat;
            $alamats->where(function ($query) use ($searchAlamat) {
                $query->where('place', 'ILIKE', "%{$searchAlamat}%")
                    ->orWhere('name', 'ILIKE', "%{$searchAlamat}%")
                    ->orWhere('phone', 'ILIKE', "%{$searchAlamat}%")
                    ->orWhere('alamat', 'ILIKE', "%{$searchAlamat}%");
            });
        }

        $alamats = $alamats->orderBy('enable', 'DESC')
            ->get();
        // --- step 3 - end - ambil daftar alamat

        // --- step 4 - start - ambil alamat aktif
        $currentAlamat = Alamat::where('user_id', $user_id)
            ->where('type', 'buyer')
            ->where('enable', 1)
            ->first();
        // --- step 4 - end - ambil alamat aktif

        return response()->json(['result' => 'success', 'alamats' => $alamats, 'currentAlamat' => $currentAlamat, 'message' => 'Alamat Berhasil Dipilih']);
    }

    /**
     * Memperbarui alamat pengiriman buyer beserta data lokasinya.
     *
     * Kepemilikan alamat dan payload lokasi diperiksa sebelum mutasi. Metadata pinpoint diverifikasi
     * ulang oleh server, sedangkan perubahan status aktif tetap menjaga satu alamat utama untuk buyer
     * tersebut.
     *
     * @param  string  $id  ID alamat.
     * @param  Request  $request  Data alamat dan pinpoint terbaru.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function updateAlamatBuyer(string $id, Request $request): JsonResponse
    {
        // --- step 1 - start - validasi user
        $user_id = optional(auth()->user())->id;
        $userExists = User::where('id', $user_id)->exists();

        if (! $userExists) {
            return response()->json(['result' => 'error', 'message' => 'Unauthorized'], 401);
        }
        // --- step 1 - end - validasi user

        // --- step 2 - start - validasi parameter request
        $validator = Validator::make($request->all(), array_merge([
            'place' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20'],
        ], $this->alamatService->locationRules()));

        if ($validator->fails()) {
            return response()->json(['result' => 'error', 'message' => $validator->messages()], 422);
        }
        // --- step 2 - end - validasi parameter request

        // --- step 3 - start - periksa keberadaan alamat
        $alamat = Alamat::where('user_id', $user_id)
            ->where('type', 'buyer')
            ->where('id', $id)
            ->first();

        if (empty($alamat)) {
            return response()->json(['result' => 'error', 'message' => 'Alamat Tidak Ditemukan'], 400);
        }
        // --- step 3 - end - periksa keberadaan alamat

        // --- step 4 - start - perbarui alamat
        $locationAttributes = $this->alamatService->locationAttributes($request);
        $alamat->fill(array_merge([
            'place' => $request->place,
            'name' => $request->name,
            'phone' => $request->phone,
        ], $locationAttributes));
        $alamat->save();
        // --- step 4 - end - perbarui alamat

        // --- step 5 - start - ambil daftar alamat
        $alamats = Alamat::where('user_id', $user_id)
            ->where('type', 'buyer');

        if (! empty($request->searchAlamat) && trim($request->searchAlamat) != '') {
            $searchAlamat = $request->searchAlamat;
            $alamats->where(function ($query) use ($searchAlamat) {
                $query->where('place', 'ILIKE', "%{$searchAlamat}%")
                    ->orWhere('name', 'ILIKE', "%{$searchAlamat}%")
                    ->orWhere('phone', 'ILIKE', "%{$searchAlamat}%")
                    ->orWhere('alamat', 'ILIKE', "%{$searchAlamat}%");
            });
        }

        $alamats = $alamats->orderBy('enable', 'DESC')
            ->get();
        // --- step 5 - end - ambil daftar alamat

        return response()->json(['result' => 'success', 'alamats' => $alamats, 'message' => 'Alamat Berhasil Diubah']);
    }
}
