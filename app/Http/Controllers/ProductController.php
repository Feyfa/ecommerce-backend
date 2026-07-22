<?php

namespace App\Http\Controllers;

use App\Models\Keranjang;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class ProductController extends Controller
{
    /**
     * Mengambil daftar produk milik seller dengan filter dan urutan yang dipilih.
     */
    public function index(string $user_id_seller, Request $request)
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
                'stock_filter' => ['nullable', Rule::in(['all', 'available', 'low', 'empty'])],
                'sort_product' => ['nullable', Rule::in(['latest', 'oldest', 'price_highest', 'price_lowest', 'stock_highest', 'stock_lowest', 'name_asc', 'name_desc'])],
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
        $products_current_id = json_decode($request->products_current_id, true);
        $search_product = (isset($request->search_product)) ? trim($request->search_product) : '';
        $stock_filter = $request->stock_filter ?? 'all';
        $sort_product = $request->sort_product ?? 'latest';

        $products = Product::with('images')
            ->where('user_id_seller', $validate['user_id_seller'])
            ->whereNotIn('id', $products_current_id)
            ->where('name', 'ILIKE', "%$search_product%");
        // --- step 3 - end - siapkan query dasar dan parameter pencarian produk

        // --- step 4 - start - terapkan filter kondisi stok
        if ($stock_filter == 'available') {
            $products->where('stock', '>', 0);
        } elseif ($stock_filter == 'low') {
            $products->whereBetween('stock', [1, 5]);
        } elseif ($stock_filter == 'empty') {
            $products->where('stock', '<=', 0);
        }
        // --- step 4 - end - terapkan filter kondisi stok

        // --- step 5 - start - terapkan urutan produk dan batasi hasil response
        if ($sort_product == 'oldest') {
            $products->orderBy('updated_at', 'ASC');
        } elseif ($sort_product == 'price_highest') {
            $products->orderBy('price', 'DESC');
        } elseif ($sort_product == 'price_lowest') {
            $products->orderBy('price', 'ASC');
        } elseif ($sort_product == 'stock_highest') {
            $products->orderBy('stock', 'DESC');
        } elseif ($sort_product == 'stock_lowest') {
            $products->orderBy('stock', 'ASC');
        } elseif ($sort_product == 'name_asc') {
            $products->orderBy('name', 'ASC');
        } elseif ($sort_product == 'name_desc') {
            $products->orderBy('name', 'DESC');
        } else {
            $products->orderBy('updated_at', 'DESC');
        }
        // --- step 5 - end - terapkan urutan produk dan batasi hasil response

        return response()->json(['status' => 200, 'products' => $products->limit(50)->get()], 200);
    }

    /**
     * Mengambil satu produk beserta gambar terurut milik seller terautentikasi.
     */
    public function show(string $user_id_seller, string $id, Request $request)
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
        // --- step 3 - end - ambil produk dan seluruh gambar sesuai urutan

        return response()->json(['status' => 200, 'product' => $product]);
    }

    /**
     * Membuat produk beserta satu sampai lima gambar dalam satu operasi konsisten.
     */
    public function store(Request $request)
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
                    throw new \RuntimeException('Failed to store a product image.');
                }

                $storedPaths[$index] = $storedPath;
            }

            $product = DB::transaction(function () use ($validate, $storedPaths) {
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

                return $product->load('images');
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
     */
    public function update(string $id, Request $request)
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
        $storedPaths = [];
        // --- step 2 - end - validasi data produk dan susun urutan gambar final

        // --- step 3 - start - simpan file baru dan hitung file lama yang harus dihapus
        try {
            foreach ($orderedImages as $index => $orderedImage) {
                if (isset($orderedImage['file'])) {
                    $storedPath = $orderedImage['file']->store('product-imgs');

                    if ($storedPath === false) {
                        throw new \RuntimeException('Failed to store a product image.');
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
            $product = DB::transaction(function () use ($product, $validate, $orderedImages) {
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

                return $product->load('images');
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
     * Menghapus product dan seluruh file gambar setelah perubahan database berhasil.
     */
    public function delete(string $user_id_seller, string $id, Request $request)
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

        // --- step 3 - start - kumpulkan seluruh path gambar sebelum row database dihapus
        $paths = $product->images->pluck('path')->push($product->img)->filter()->unique()->all();
        // --- step 3 - end - kumpulkan seluruh path gambar sebelum row database dihapus

        // --- step 4 - start - hapus dependensi keranjang dan produk dalam transaksi
        DB::transaction(function () use ($product) {
            Keranjang::where('product_id', $product->id)->delete();
            $product->delete();
        });
        // --- step 4 - end - hapus dependensi keranjang dan produk dalam transaksi

        // --- step 5 - start - hapus seluruh file setelah transaksi database berhasil
        Storage::delete($paths);
        // --- step 5 - end - hapus seluruh file setelah transaksi database berhasil

        return response()->json(['status' => 200, 'message' => 'Delete Product Success'], 200);
    }

    /**
     * Membuat validator product dan memastikan manifest gambar konsisten dengan file upload.
     */
    private function productValidator(Request $request, bool $creating, ?Product $product = null)
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
            } catch (\InvalidArgumentException $exception) {
                $validator->errors()->add('images', $exception->getMessage());
            }
        });
        // --- step 2 - end - tambahkan validasi lintas-field untuk manifest gambar

        return $validator;
    }

    /**
     * Mengubah image_order menjadi daftar gambar final dan menolak referensi yang tidak valid.
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
            throw new \InvalidArgumentException('Image order and uploaded images must be arrays.');
        }

        if (count($imageOrder) < 1 || count($imageOrder) > 5) {
            throw new \InvalidArgumentException('Product must have between 1 and 5 images.');
        }
        // --- step 2 - end - tolak collection malformed sebelum dilakukan iterasi

        // --- step 3 - start - ubah setiap token menjadi upload baru atau gambar lama yang valid
        foreach ($imageOrder as $token) {
            if (! is_string($token)) {
                throw new \InvalidArgumentException('Image order contains an invalid image reference.');
            }

            if (preg_match('/^new:(\d+)$/', $token, $matches)) {
                $index = (int) $matches[1];

                if (! isset($uploadedImages[$index]) || in_array($index, $usedNewIndexes, true)) {
                    throw new \InvalidArgumentException('Image order contains an invalid new image reference.');
                }

                $usedNewIndexes[] = $index;
                $orderedImages[] = ['file' => $uploadedImages[$index]];

                continue;
            }

            if (! $product || ! $existingImages->has($token)) {
                throw new \InvalidArgumentException('Image order contains an image that does not belong to this product.');
            }

            $image = $existingImages->get($token);
            $orderedImages[] = ['id' => $image->id, 'path' => $image->path];
        }
        // --- step 3 - end - ubah setiap token menjadi upload baru atau gambar lama yang valid

        // --- step 4 - start - pastikan semua file upload tercantum dalam manifest final
        if (count($usedNewIndexes) !== count($uploadedImages)) {
            throw new \InvalidArgumentException('Every uploaded image must appear exactly once in image order.');
        }
        // --- step 4 - end - pastikan semua file upload tercantum dalam manifest final

        return $orderedImages;
    }
}
