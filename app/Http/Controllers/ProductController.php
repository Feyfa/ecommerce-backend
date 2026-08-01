<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\AuditLogService;
use App\Services\ProductAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidationValidator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ProductController extends Controller
{
    /**
     * Menyiapkan controller dengan layanan produk, ketersediaan, dan audit log.
     *
     * @param  AuditLogService  $auditLogService  Service audit log yang digunakan oleh class ini.
     * @param  ProductAvailabilityService  $productAvailabilityService  Service product availability yang digunakan oleh class ini.
     *
     * @return void  Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(
        protected AuditLogService $auditLogService,
        protected ProductAvailabilityService $productAvailabilityService,
    ) {}

    /**
     * Mengambil daftar produk milik seller dengan filter dan urutan yang dipilih.
     *
     * Filter, pencarian, sorting, cursor, dan identitas seller divalidasi sebelum query produk
     * dibentuk. Response menggunakan urutan stabil serta menyertakan status verifikasi lokasi seller
     * yang menentukan apakah produk baru dapat ditambahkan.
     *
     * @param  string  $user_id_seller  ID seller pemilik produk atau transaksi.
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function index(string $user_id_seller, Request $request): JsonResponse
    {
        // --- step 1 - start - validasi parameter seller, pagination, filter, dan sorting
        $validator = Validator::make(
            [
                'user_id_seller' => $user_id_seller,
                'products_current_id' => $request->products_current_id,
                'search_product' => $request->search_product,
                'stock_filter' => $request->stock_filter,
                'sort_product' => $request->sort_product,
            ],
            [
                'user_id_seller' => ['required', 'uuid'],
                'products_current_id' => [
                    'required',
                    'json',
                    function ($attribute, $value, $fail) {
                        if (! is_string($value) || ! is_array(json_decode($value, true))) {
                            $fail("The {$attribute} field must be a JSON array.");
                        }
                    },
                ],
                'search_product' => ['nullable', 'string'],
                'stock_filter' => ['nullable', Rule::in(Product::STOCK_FILTER_OPTIONS)],
                'sort_product' => ['nullable', Rule::in(Product::SORT_OPTIONS)],
            ]
        );

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);
        }

        $validate = $validator->validate();
        // --- step 1 - end - validasi parameter seller, pagination, filter, dan sorting

        // --- step 2 - start - pastikan seller hanya membaca daftar produknya sendiri
        if ($request->user()->id !== $validate['user_id_seller']) {
            return response()->json(['status' => 403, 'message' => 'Forbidden'], 403);
        }
        // --- step 2 - end - pastikan seller hanya membaca daftar produknya sendiri

        // --- step 3 - start - siapkan query dasar dan parameter pencarian produk
        $products_current_id = json_decode($validate['products_current_id'], true);
        $search_product = trim($validate['search_product'] ?? '');
        $stock_filter = $validate['stock_filter'] ?? 'all';
        $sort_product = $validate['sort_product'] ?? 'latest';

        $products = Product::with('images')
            ->where('user_id_seller', $validate['user_id_seller'])
            ->whereNotIn('id', $products_current_id)
            ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search_product).'%'])
            ->withStockCondition($stock_filter)
            ->withProductSort($sort_product);
        // --- step 3 - end - siapkan query dasar dan parameter pencarian produk

        return response()->json([
            'status' => 200,
            'products' => $products->limit(50)->get(),
            'seller_location_verified' => $this->productAvailabilityService
                ->sellerHasVerifiedAddress($validate['user_id_seller']),
        ], 200);
    }

    /**
     * Mengambil satu produk beserta gambar terurut milik seller terautentikasi.
     *
     * Function membatasi pembacaan ke produk milik seller yang terautentikasi dan memvalidasi kedua
     * identifier. Produk dimuat bersama gambar terurut agar editor menerima manifest yang sama dengan
     * state database.
     *
     * @param  string  $user_id_seller  ID seller pemilik produk atau transaksi.
     * @param  string  $id  Identifier record yang menjadi target operasi.
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function show(string $user_id_seller, string $id, Request $request): JsonResponse
    {
        // --- step 1 - start - validasi UUID seller dan produk
        $validator = Validator::make(
            ['user_id_seller' => $user_id_seller, 'id' => $id],
            ['user_id_seller' => ['required', 'uuid'], 'id' => ['required', 'uuid']]
        );

        if ($validator->fails()) {
            return response()->json(['status' => 402, 'message' => $validator->messages()], 422);
        }

        $validate = $validator->validate();
        // --- step 1 - end - validasi UUID seller dan produk

        // --- step 2 - start - pastikan seller hanya membaca produknya sendiri
        if ($request->user()->id !== $validate['user_id_seller']) {
            return response()->json(['status' => 403, 'message' => 'Forbidden'], 403);
        }
        // --- step 2 - end - pastikan seller hanya membaca produknya sendiri

        // --- step 3 - start - ambil produk dan seluruh gambar sesuai urutan
        $product = Product::with('images')
            ->where('user_id_seller', $validate['user_id_seller'])
            ->where('id', $validate['id'])
            ->first();

        if (! $product) {
            return response()->json(['status' => 404, 'message' => 'Product Not Found'], 404);
        }
        // --- step 3 - end - ambil produk dan seluruh gambar sesuai urutan

        return response()->json(['status' => 200, 'product' => $product]);
    }

    /**
     * Membuat produk beserta satu sampai lima gambar dalam satu operasi konsisten.
     *
     * Seller harus terautentikasi dan memiliki lokasi toko terverifikasi sebelum produk dapat dibuat.
     * Manifest satu sampai lima gambar divalidasi, file disimpan, lalu produk, urutan gambar, dan
     * audit log dibuat secara transaksional dengan pembersihan file ketika proses gagal.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function store(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi data produk, file, manifest urutan, dan seller
        $validator = $this->productValidator($request, true);

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);
        }

        $validate = $validator->validate();

        if ($request->user()->id !== $validate['user_id_seller']) {
            return response()->json(['status' => 403, 'message' => 'Forbidden'], 403);
        }

        if (! $this->productAvailabilityService->sellerHasVerifiedAddress($validate['user_id_seller'])) {
            return response()->json([
                'status' => 409,
                'code' => ProductAvailabilityService::SELLER_LOCATION_UNVERIFIED,
                'message' => 'Verifikasi lokasi toko dengan Pinpoint sebelum menambahkan produk.',
            ], 409);
        }
        // --- step 1 - end - validasi data produk, file, manifest urutan, dan seller

        // --- step 2 - start - susun manifest final sebelum menyimpan file
        $orderedImages = $this->resolveImageOrder($request);
        $storedPaths = [];
        // --- step 2 - end - susun manifest final sebelum menyimpan file

        // --- step 3 - start - simpan file lalu buat produk dan relasi gambar dalam transaksi
        try {
            foreach ($orderedImages as $index => $orderedImage) {
                $storedPath = $orderedImage['file']->store('product-imgs');

                if ($storedPath === false) {
                    throw new RuntimeException('Failed to store a product image.');
                }

                $storedPaths[$index] = $storedPath;
            }

            $product = DB::transaction(function () use ($request, $validate, $storedPaths) {
                $product = Product::create([
                    'user_id_seller' => $validate['user_id_seller'],
                    'img' => $storedPaths[0],
                    'name' => $validate['name'],
                    'price' => $validate['price'],
                    'stock' => $validate['stock'],
                ]);

                foreach ($storedPaths as $index => $path) {
                    $product->images()->create(['path' => $path, 'position' => $index + 1]);
                }

                $product->load('images');
                $this->auditLogService->recordProductCreated($request->user(), $product, $request);

                return $product;
            });
        } catch (Throwable $exception) {
            Storage::delete($storedPaths);
            throw $exception;
        }
        // --- step 3 - end - simpan file lalu buat produk dan relasi gambar dalam transaksi

        return response()->json(['status' => 200, 'message' => 'Add Product Success', 'product' => $product], 200);
    }

    /**
     * Memperbarui data dan urutan gambar, lalu menjadikan posisi pertama sebagai cover legacy.
     *
     * Kepemilikan produk, payload, dan manifest gabungan gambar lama-baru diverifikasi sebelum
     * perubahan. Database dan audit diperbarui dalam transaksi, sedangkan file baru atau file yang
     * dilepas dibersihkan pada tahap yang sesuai agar rollback tidak merusak gambar lama.
     *
     * @param  string  $id  Identifier record yang menjadi target operasi.
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function update(string $id, Request $request): JsonResponse
    {
        // --- step 1 - start - validasi UUID dan cari produk melalui seller terautentikasi
        $idValidator = Validator::make(['id' => $id], ['id' => ['required', 'uuid']]);

        if ($idValidator->fails()) {
            return response()->json(['status' => 422, 'message' => $idValidator->messages()], 422);
        }

        $product = Product::with('images')
            ->where('user_id_seller', $request->user()->id)
            ->where('id', $id)
            ->first();

        if (! $product) {
            return response()->json(['status' => 404, 'message' => 'Product Not Found'], 404);
        }
        // --- step 1 - end - validasi UUID dan cari produk melalui seller terautentikasi

        // --- step 2 - start - validasi data produk dan susun urutan gambar final
        $validator = $this->productValidator($request, false, $product);

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);
        }

        $validate = $validator->validate();
        $orderedImages = $this->resolveImageOrder($request, $product);
        $beforeValues = $this->productValues($product);
        $imageChanges = $this->imageChanges($product, $orderedImages);
        $storedPaths = [];
        // --- step 2 - end - validasi data produk dan susun urutan gambar final

        // --- step 3 - start - simpan file baru dan hitung file lama yang harus dihapus
        try {
            foreach ($orderedImages as $index => $orderedImage) {
                if (isset($orderedImage['file'])) {
                    $storedPath = $orderedImage['file']->store('product-imgs');

                    if ($storedPath === false) {
                        throw new RuntimeException('Failed to store a product image.');
                    }

                    $storedPaths[$index] = $storedPath;
                    $orderedImages[$index]['path'] = $storedPath;
                }
            }

            $keptIds = collect($orderedImages)->pluck('id')->filter()->all();
            $keptPaths = collect($orderedImages)->pluck('path')->filter()->all();
            $deletedPaths = $product->images
                ->whereNotIn('id', $keptIds)
                ->pluck('path')
                ->diff($keptPaths)
                ->all();

            // --- step 4 - start - bangun ulang posisi gambar dan sinkronkan cover dalam transaksi
            $product = DB::transaction(function () use (
                $request,
                $product,
                $validate,
                $orderedImages,
                $beforeValues,
                $imageChanges
            ) {
                $product->images()->delete();

                foreach ($orderedImages as $index => $orderedImage) {
                    ProductImage::create([
                        'id' => $orderedImage['id'] ?? null,
                        'product_id' => $product->id,
                        'path' => $orderedImage['path'],
                        'position' => $index + 1,
                    ]);
                }

                $product->name = $validate['name'];
                $product->price = $validate['price'];
                $product->stock = $validate['stock'];
                $product->img = $orderedImages[0]['path'];
                $product->save();

                $product->load('images');
                $this->auditLogService->recordProductUpdated(
                    $request->user(),
                    $product,
                    $request,
                    $this->productChanges($beforeValues, $product),
                    $imageChanges,
                );

                return $product;
            });
            // --- step 4 - end - bangun ulang posisi gambar dan sinkronkan cover dalam transaksi
        } catch (Throwable $exception) {
            Storage::delete($storedPaths);
            throw $exception;
        }
        // --- step 3 - end - simpan file baru dan hitung file lama yang harus dihapus

        // --- step 5 - start - hapus file lama setelah transaksi database berhasil
        Storage::delete($deletedPaths);
        // --- step 5 - end - hapus file lama setelah transaksi database berhasil

        return response()->json(['status' => 200, 'message' => 'Update Product Success', 'product' => $product]);
    }

    /**
     * Menonaktifkan produk dengan soft delete agar item keranjang lama tetap dapat dijelaskan.
     *
     * Produk milik seller dinonaktifkan menggunakan soft delete agar keranjang lama masih dapat
     * menjelaskan itemnya. Snapshot audit disimpan dalam transaksi yang sama, sedangkan file gambar
     * dipertahankan untuk konteks historis.
     *
     * @param  string  $user_id_seller  ID seller pemilik produk atau transaksi.
     * @param  string  $id  Identifier record yang menjadi target operasi.
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function delete(string $user_id_seller, string $id, Request $request): JsonResponse
    {
        // --- step 1 - start - validasi UUID seller dan produk
        $validator = Validator::make(
            ['user_id_seller' => $user_id_seller, 'id' => $id],
            ['user_id_seller' => ['required', 'uuid'], 'id' => ['required', 'uuid']]
        );

        if ($validator->fails()) {
            return response()->json(['status' => 402, 'message' => $validator->messages()], 422);
        }

        $validate = $validator->validate();
        // --- step 1 - end - validasi UUID seller dan produk

        // --- step 2 - start - pastikan seller hanya menghapus produknya sendiri
        if ($request->user()->id !== $validate['user_id_seller']) {
            return response()->json(['status' => 403, 'message' => 'Forbidden'], 403);
        }

        $product = Product::with('images')
            ->where('user_id_seller', $validate['user_id_seller'])
            ->where('id', $validate['id'])
            ->first();

        if (! $product) {
            return response()->json(['status' => 404, 'message' => 'Product Not Found'], 404);
        }
        // --- step 2 - end - pastikan seller hanya menghapus produknya sendiri

        // --- step 3 - start - simpan snapshot audit sebelum produk dinonaktifkan
        $snapshot = [
            'name' => (string) $product->name,
            ...$this->auditLogService->productSnapshot($product),
        ];
        // --- step 3 - end - simpan snapshot audit sebelum produk dinonaktifkan

        // --- step 4 - start - soft-delete produk tanpa menghapus keranjang dan gambar
        DB::transaction(function () use ($request, $product, $snapshot) {
            $product->delete();
            $this->auditLogService->recordProductDeleted($request->user(), $product, $request, $snapshot);
        });
        // --- step 4 - end - soft-delete produk tanpa menghapus keranjang dan gambar

        return response()->json(['status' => 200, 'message' => 'Delete Product Success'], 200);
    }

    /**
     * Membuat validator product dan memastikan manifest gambar konsisten dengan file upload.
     *
     * Aturan create dan update dibedakan berdasarkan kebutuhan gambar serta field produk. Callback
     * tambahan memvalidasi manifest urutan, kepemilikan gambar lama, dan korespondensi setiap upload
     * sebelum controller menyentuh storage atau database.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     * @param  bool  $creating  Nilai creating yang diperlukan untuk menjalankan proses ini.
     * @param  Product|null  $product  Model produk yang menjadi target atau sumber data.
     *
     * @return ValidationValidator  Validator berisi aturan dasar dan pemeriksaan lanjutan untuk payload.
     */
    private function productValidator(Request $request, bool $creating, ?Product $product = null): ValidationValidator
    {
        // --- step 1 - start - susun aturan dasar untuk create atau update
        $rules = [
            'name' => ['required', 'min:3'],
            'price' => ['required', 'integer', 'min:1'],
            'stock' => ['required', 'integer', $creating ? 'min:1' : 'min:0'],
            'images' => [$creating ? 'required' : 'nullable', 'array', 'max:5'],
            'images.*' => ['image', 'file', 'max:1024'],
            'image_order' => ['required', 'array', 'min:1', 'max:5'],
            'image_order.*' => ['required', 'string', 'distinct'],
        ];

        if ($creating) {
            $rules['user_id_seller'] = ['required', 'uuid'];
        }
        // --- step 1 - end - susun aturan dasar untuk create atau update

        // --- step 2 - start - tambahkan validasi lintas-field untuk manifest gambar
        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request, $product) {
            try {
                $this->resolveImageOrder($request, $product);
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('images', $exception->getMessage());
            }
        });
        // --- step 2 - end - tambahkan validasi lintas-field untuk manifest gambar

        return $validator;
    }

    /**
     * Mengubah image_order menjadi daftar gambar final dan menolak referensi yang tidak valid.
     *
     * Setiap token manifest dipetakan ke upload baru atau gambar lama milik produk yang sama. Function
     * menolak duplikasi, referensi asing, upload yang tidak dicantumkan, serta jumlah gambar di luar
     * batas sebelum file atau database diubah.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     * @param  Product|null  $product  Model produk yang menjadi target atau sumber data.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function resolveImageOrder(Request $request, ?Product $product = null): array
    {
        // --- step 1 - start - ambil manifest kiriman dan gambar lama milik produk
        $imageOrder = $request->input('image_order', []);
        $uploadedImages = $request->file('images', []);
        $existingImages = $product ? $product->images->keyBy('id') : collect();
        $usedNewIndexes = [];
        $orderedImages = [];
        // --- step 1 - end - ambil manifest kiriman dan gambar lama milik produk

        // --- step 2 - start - tolak collection malformed sebelum dilakukan iterasi
        if (! is_array($imageOrder) || ! is_array($uploadedImages)) {
            throw new InvalidArgumentException('Image order and uploaded images must be arrays.');
        }

        if (count($imageOrder) < 1 || count($imageOrder) > 5) {
            throw new InvalidArgumentException('Product must have between 1 and 5 images.');
        }
        // --- step 2 - end - tolak collection malformed sebelum dilakukan iterasi

        // --- step 3 - start - ubah setiap token menjadi upload baru atau gambar lama yang valid
        foreach ($imageOrder as $token) {
            if (! is_string($token)) {
                throw new InvalidArgumentException('Image order contains an invalid image reference.');
            }

            if (preg_match('/^new:(\d+)$/', $token, $matches)) {
                $index = (int) $matches[1];

                if (! isset($uploadedImages[$index]) || in_array($index, $usedNewIndexes, true)) {
                    throw new InvalidArgumentException('Image order contains an invalid new image reference.');
                }

                $usedNewIndexes[] = $index;
                $orderedImages[] = ['file' => $uploadedImages[$index]];

                continue;
            }

            if (! $product || ! $existingImages->has($token)) {
                throw new InvalidArgumentException('Image order contains an image that does not belong to this product.');
            }

            $image = $existingImages->get($token);
            $orderedImages[] = ['id' => $image->id, 'path' => $image->path];
        }
        // --- step 3 - end - ubah setiap token menjadi upload baru atau gambar lama yang valid

        // --- step 4 - start - pastikan semua file upload tercantum dalam manifest final
        if (count($usedNewIndexes) !== count($uploadedImages)) {
            throw new InvalidArgumentException('Every uploaded image must appear exactly once in image order.');
        }
        // --- step 4 - end - pastikan semua file upload tercantum dalam manifest final

        return $orderedImages;
    }

    /**
     * Mengambil nilai produk yang boleh muncul pada before/after audit.
     *
     * @param  Product  $product  Model produk yang menjadi target atau sumber data.
     *
     * @return array{name: string, price: int, stock: int}  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function productValues(Product $product): array
    {
        return [
            'name' => (string) $product->name,
            'price' => (int) $product->price,
            'stock' => (int) $product->stock,
        ];
    }

    /**
     * Menghasilkan daftar perubahan nilai yang sebenarnya saja.
     * Request update identik tetap diaudit dengan array kosong.
     *
     * Nilai sebelum update dibandingkan dengan model setelah refresh menggunakan tipe yang
     * dinormalisasi. Hanya field yang benar-benar berubah dimasukkan ke audit context agar update
     * identik tidak menghasilkan perubahan palsu.
     *
     * @param  array{name: string, price: int, stock: int}  $beforeValues  Nilai before values yang diperlukan untuk menjalankan proses ini.
     * @param  Product  $product  Model produk yang menjadi target atau sumber data.
     *
     * @return array<int, array{field: string, label: string, before: mixed, after: mixed}>  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function productChanges(array $beforeValues, Product $product): array
    {
        // --- step 1 - start - siapkan nilai pembanding dan label audit
        $afterValues = $this->productValues($product);
        $labels = [
            'name' => 'Nama produk',
            'price' => 'Harga',
            'stock' => 'Stok',
        ];
        $changes = [];
        // --- step 1 - end - siapkan nilai pembanding dan label audit

        // --- step 2 - start - kumpulkan field produk yang benar-benar berubah
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
        // --- step 2 - end - kumpulkan field produk yang benar-benar berubah

        return $changes;
    }

    /**
     * Merangkum perubahan gambar tanpa menyimpan path, file, atau id internal.
     * Perubahan urutan hanya membandingkan urutan relatif gambar lama yang dipertahankan.
     *
     * @param  Product  $product  Model produk yang menjadi target atau sumber data.
     * @param  array<int, array{id?: string, path?: string, file?: mixed}>  $orderedImages  Nilai ordered images yang diperlukan untuk menjalankan proses ini.
     *
     * @return array{before_count: int, after_count: int, added_count: int, removed_count: int, cover_changed: bool, order_changed: bool}  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function imageChanges(Product $product, array $orderedImages): array
    {
        // --- step 1 - start - hitung urutan gambar sebelum dan sesudah update
        $beforeIds = $product->images->pluck('id')->values()->all();
        $afterExistingIds = collect($orderedImages)->pluck('id')->filter()->values()->all();
        $beforeRetainedIds = array_values(array_filter(
            $beforeIds,
            static fn (string $id): bool => in_array($id, $afterExistingIds, true)
        ));
        $afterCoverId = $orderedImages[0]['id'] ?? null;
        // --- step 1 - end - hitung urutan gambar sebelum dan sesudah update

        // --- step 2 - start - bentuk ringkasan perubahan gambar untuk audit
        $changes = [
            'before_count' => count($beforeIds),
            'after_count' => count($orderedImages),
            'added_count' => count(array_filter(
                $orderedImages,
                static fn (array $image): bool => ! isset($image['id'])
            )),
            'removed_count' => count($beforeIds) - count($afterExistingIds),
            'cover_changed' => ($beforeIds[0] ?? null) !== $afterCoverId,
            'order_changed' => $beforeRetainedIds !== $afterExistingIds,
        ];
        // --- step 2 - end - bentuk ringkasan perubahan gambar untuk audit

        return $changes;
    }
}
