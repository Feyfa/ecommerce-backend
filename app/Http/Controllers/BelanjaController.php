<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BelanjaController extends Controller
{
    /**
     * Mengambil katalog buyer yang hanya berisi produk dengan stok yang dapat dibeli.
     *
     * Cursor, pencarian, exclusion list, dan sorting divalidasi sebelum scope purchasable diterapkan.
     * Query hanya mengembalikan produk aktif dengan stok serta lokasi seller valid dan menggunakan
     * urutan stabil untuk pagination berikutnya.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function index(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi cursor, pencarian, dan sorting
        $validator = Validator::make(
            [
                'products_current_id' => $request->products_current_id,
                'search_product' => $request->search_product,
                'sort_product' => $request->sort_product,
            ],
            [
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
                'sort_product' => ['nullable', Rule::in(Product::SORT_OPTIONS)],
            ]
        );

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);
        }

        $validate = $validator->validate();
        // --- step 1 - end - validasi cursor, pencarian, dan sorting

        // Identitas token menjadi satu-satunya sumber seller yang dikecualikan dari katalog buyer.
        $authenticatedUserId = $request->user()->id;

        // --- step 2 - start - siapkan parameter query katalog buyer
        $products_current_id = json_decode($validate['products_current_id'], true);
        $search_product = trim($validate['search_product'] ?? '');
        $sort_product = $validate['sort_product'] ?? 'latest';
        // --- step 2 - end - siapkan parameter query katalog buyer

        // --- step 3 - start - ambil produk seller lain yang masih dapat dibeli
        $products = Product::select(
            'products.id as p_id',
            'products.img as p_img',
            'products.name as p_name',
            'products.price as p_price',
            'products.stock as p_stock',
            'users.id as u_id',
            DB::raw("COALESCE(NULLIF(companies.name, ''), users.name) as u_name")
        )
            ->join('users', 'products.user_id_seller', '=', 'users.id')
            ->leftJoin('companies', 'companies.user_id', '=', 'users.id')
            ->where('products.user_id_seller', '<>', $authenticatedUserId)
            ->whereNotIn('products.id', $products_current_id)
            ->purchasable()
            ->where(function ($query) use ($search_product) {
                $searchPattern = '%'.mb_strtolower($search_product).'%';

                $query->whereRaw('LOWER(products.name) LIKE ?', [$searchPattern])
                    ->orWhereRaw("LOWER(COALESCE(NULLIF(companies.name, ''), users.name)) LIKE ?", [$searchPattern]);
            })
            ->withProductSort($sort_product);

        $products = $products->limit(200)->get();
        // --- step 3 - end - ambil produk seller lain yang masih dapat dibeli

        return response()->json(['status' => 200, 'products' => $products], 200);
    }
}
