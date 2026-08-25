<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\BuyerProductSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class BelanjaController extends Controller
{
    /**
     * Menyiapkan controller dengan service pencarian katalog buyer.
     *
     * @param  BuyerProductSearchService  $buyerProductSearchService  Service yang menjalankan query Meilisearch.
     */
    public function __construct(protected BuyerProductSearchService $buyerProductSearchService) {}

    /**
     * Mengambil katalog buyer yang hanya berisi produk dengan stok yang dapat dibeli.
     *
     * Keyword, filter, sorting, dan pagination bernomor divalidasi sebelum dikirim ke Meilisearch.
     * Meilisearch hanya menyimpan proyeksi produk yang sudah dapat dibeli, sedangkan database tetap
     * menjadi sumber kebenaran untuk operasi cart dan checkout.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return JsonResponse  Respons JSON yang memuat hasil operasi atau detail kegagalan yang aman untuk client.
     */
    public function index(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi pagination, pencarian, filter katalog, dan sorting
        $validator = Validator::make(
            [
                'page' => $request->page,
                'per_page' => $request->per_page,
                'search_product' => $request->search_product,
                'min_price' => $request->min_price,
                'max_price' => $request->max_price,
                'added_within' => $request->added_within,
                'sort_product' => $request->sort_product,
            ],
            [
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('buyer_product_search.max_per_page')],
                'search_product' => ['nullable', 'string', 'max:255'],
                'min_price' => ['nullable', 'integer', 'min:0'],
                'max_price' => ['nullable', 'integer', 'min:0'],
                'added_within' => ['nullable', Rule::in(Product::RECENTLY_ADDED_FILTER_OPTIONS)],
                'sort_product' => ['nullable', Rule::in([...Product::SORT_OPTIONS, 'relevance'])],
            ]
        );

        $validator->after(function ($validator) use ($request) {
            $minPrice = filter_var($request->min_price, FILTER_VALIDATE_INT);
            $maxPrice = filter_var($request->max_price, FILTER_VALIDATE_INT);

            if ($minPrice !== false && $maxPrice !== false && $minPrice > $maxPrice) {
                $validator->errors()->add('max_price', 'The max price field must be greater than or equal to min price.');
            }
        });

        if ($validator->fails()) {
            return response()->json(['status' => 422, 'message' => $validator->messages()], 422);
        }

        $validate = $validator->validate();
        // --- step 1 - end - validasi pagination, pencarian, filter katalog, dan sorting

        // Identitas token menjadi satu-satunya sumber seller yang dikecualikan dari katalog buyer.
        $authenticatedUserId = $request->user()->id;

        // --- step 2 - start - siapkan parameter query katalog buyer
        $page = (int) ($validate['page'] ?? 1);
        $perPage = (int) ($validate['per_page'] ?? config('buyer_product_search.per_page'));
        $search_product = trim($validate['search_product'] ?? '');
        $min_price = $validate['min_price'] ?? null;
        $max_price = $validate['max_price'] ?? null;
        $added_within = $validate['added_within'] ?? null;
        $sort_product = $validate['sort_product'] ?? ($search_product === '' ? 'latest' : 'relevance');
        // --- step 2 - end - siapkan parameter query katalog buyer

        // --- step 3 - start - jalankan query katalog melalui Meilisearch tanpa fallback PostgreSQL
        try {
            $result = $this->buyerProductSearchService->search(
                (string) $authenticatedUserId,
                [
                    'search_product' => $search_product,
                    'min_price' => $min_price,
                    'max_price' => $max_price,
                    'added_within' => $added_within,
                    'sort_product' => $sort_product,
                ],
                $page,
                $perPage,
            );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 503,
                'code' => 'BUYER_PRODUCT_SEARCH_UNAVAILABLE',
                'message' => 'Pencarian produk sedang tidak tersedia. Silakan coba lagi beberapa saat lagi.',
            ], 503);
        }
        // --- step 3 - end - jalankan query katalog melalui Meilisearch tanpa fallback PostgreSQL

        return response()->json(['status' => 200, ...$result], 200);
    }
}
