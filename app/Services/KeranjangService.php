<?php 

namespace App\Services;

use App\Models\Alamat;
use App\Models\Keranjang;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

class KeranjangService
{
    /**
     * untuk get keranjang
     */
    public function getKeranjangs(string $user_id_buyer = "") : array
    {
        /* GET ITEM IN BASKET */
        $keranjangs = Keranjang::selectRaw('
                                    keranjangs.id as k_id,
                                    keranjangs.user_id_seller as k_user_id_seller,
                                    keranjangs.checked as k_checked,
                                    keranjangs.total as k_total,
                                    (keranjangs.total * products.price) as k_total_price,
                                    users.name as u_seller_name,
                                    products.id as p_id,
                                    products.name as p_name,
                                    products.price as p_price,
                                    products.stock as p_stock,
                                    products.img as p_img
                                ')
                                ->join('users', 'keranjangs.user_id_seller', '=', 'users.id')
                                ->join('products', 'keranjangs.product_id', '=', 'products.id')
                                ->where('keranjangs.user_id_buyer', $user_id_buyer)
                                ->orderBy('k_id', 'DESC')
                                ->get();
        /* GET ITEM IN BASKET */
        
        /* CALCULATION PRICE */
        $totalPrice = 0;
        foreach($keranjangs as $keranjang)
        {
            if($keranjang->k_checked == 1) {
                $totalPrice += $keranjang->k_total_price;
            }
        }
        /* CALCULATION PRICE */

        /* GROUP KERANJANG */
        $groupKeranjangs = $keranjangs->groupBy('k_user_id_seller')
                                      ->toArray();
        /* GROUP KERANJANG */

        return [
            'totalPrice' => $totalPrice,
            'keranjangs' => $groupKeranjangs
        ];
    }

    /**
     * untuk check apakah product sudah sold out atau belum
     */
    public function checkProductSoldOutByIds(array $product_ids = []) : array
    {
        $productSoldOutIds = Product::whereIn('id', $product_ids)
                                    ->where('stock', '<', 1)
                                    ->pluck('id')
                                    ->toArray();

        return [
            'ids' => $productSoldOutIds
        ];
    }

    /**
     * untuk check apakah keranjang user buyer itu tidak ada yang checked
     */
    public function checkKeranjangNotChecked(string $user_id_buyer = "") : array
    {
        $keranjangCheckedExists = Keranjang::where('user_id_buyer', $user_id_buyer)
                                           ->where('checked', 1)
                                           ->where('total', '>', 0)
                                           ->exists();

        return [
            'checked' => $keranjangCheckedExists
        ];
    }

    /**
     * untuk reset semua checkout keranjang, lalu set checkout keranjang yang checked saja
     */
    public function updateCheckoutKeranjang(string $user_id_buyer = "") 
    {
        /* RESET CHECKOUT KERANJANG USER BUYER */
        Keranjang::where('user_id_buyer', $user_id_buyer)
                 ->update([
                    'checkout' => 0
                 ]);
        /* RESET CHECKOUT KERANJANG USER BUYER */

        /* UPDATE CHECKOUT TO 1 KERANJANG WHERE CHECKED 1 */
        Keranjang::where('user_id_buyer', $user_id_buyer)
                 ->where('checked', 1)
                 ->update([
                    'checkout' => 1
                 ]);
        /* UPDATE CHECKOUT TO 1 KERANJANG WHERE CHECKED 1 */
    }

    public function checkAlamatBuyerExist(string $user_id_buyer = "") : array
    {
        /* ALAMAT BUYER EXISTS */
        $alamatExists = Alamat::where('user_id', $user_id_buyer)
                              ->where('enable', 1)
                              ->exists();
        /* ALAMAT BUYER EXISTS */

        return [
            'exists' => $alamatExists
        ];
    }
}