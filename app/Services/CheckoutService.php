<?php 

namespace App\Services;

use App\Models\Alamat;
use App\Models\TransactionInvoice;
use App\Models\Keranjang;
use App\Models\PaymentList;
use App\Models\Product;
use App\Models\TransactionProduct;
use App\Models\TransactionUser;
use App\Models\User;
use Carbon\Carbon;

class CheckoutService
{
    /**
     * untuk ambil alamat buyer
     */
    public function getAlamatBuyer(string $user_id_buyer = "") : array
    {
        /* GET ALAMAT BUYER */
        $alamat = Alamat::where('user_id', $user_id_buyer)
                        ->where('type', 'buyer')
                        ->where('enable', 1)
                        ->first();
        /* GET ALAMAT BUYER */

        return [
            'alamat' => $alamat
        ];
    }

    /**
     * ambil keranjang yang sedang checkout
     */
    public function getKeranjangCheckout(string $user_id_buyer = "") : array
    {
        /* GET KERANJANG CHECKOUT */
        $keranjangs = Keranjang::selectRaw('
                                keranjangs.id as k_id,
                                keranjangs.user_id_seller as k_user_id_seller,
                                keranjangs.total as k_total,
                                (keranjangs.total * products.price) as k_total_price,
                                products.id as p_id,
                                products.name as p_name,
                                products.price as p_price,
                                products.img as p_img,
                                users.name as u_name_seller
                            ')
                            ->join('users', 'keranjangs.user_id_seller', '=', 'users.id')
                            ->join('products', 'keranjangs.product_id', '=', 'products.id')
                            ->where('keranjangs.user_id_buyer', $user_id_buyer)
                            ->where('keranjangs.checkout', 1)
                            ->where('keranjangs.total', '>', 0)
                            ->orderBy('k_user_id_seller', 'ASC')   
                            ->get(); 
        
        $groupKeranjangs = $keranjangs->groupBy('k_user_id_seller')
                                      ->toArray();
        /* GET KERANJANG CHECKOUT */

        /* CALCULATION PRICE */
        $totalPrice = 0;
        foreach($keranjangs as $keranjang)
        {
            $totalPrice += $keranjang->k_total_price;
        }
        /* CALCULATION PRICE */
        
        /* GENERATE FORMAT CHECKOUTS */
        $checkouts = [];
        foreach($groupKeranjangs as $keranjangs)
        {
            /* FORMAT KERANJANG */
            $generateFormatKeranjangs = $this->generateFormatKeranjangs($keranjangs);
            $keranjangsFormat = $generateFormatKeranjangs['keranjangs'];
            /* FORMAT KERANJANG */

            /* FORMAT KURIRS */
            $generateFormatKurirs = $this->generateFormatKurirs();
            $kurirs = $generateFormatKurirs['kurirs'];
            /* FORMAT KURIRS */

            $checkouts[] = [
                'user_id_seller' => $keranjangs[0]['k_user_id_seller'],
                'user_name_seller' => $keranjangs[0]['u_name_seller'],
                'keranjangs' => $keranjangsFormat,
                'kurirs' => $kurirs,
            ];
        }
        /* GENERATE FORMAT CHECKOUTS */
        
        return [
            'checkouts' => $checkouts,
            'totalPrice' => $totalPrice
        ];
    }
    private function generateFormatKeranjangs($keranjangs = [])
    {
        $keranjangsFormat = [];
        foreach($keranjangs as $keranjang)
        {
            $keranjangsFormat[] = [
                'k_id' => $keranjang['k_id'],
                'k_total' => $keranjang['k_total'],
                'k_total_price' => $keranjang['k_total_price'],
                'p_id' => $keranjang['p_id'],
                'p_name' => $keranjang['p_name'],
                'p_price' => $keranjang['p_price'],
                'p_img' => $keranjang['p_img'],
            ];
        }

        return [
            'keranjangs' => $keranjangsFormat
        ];
    }
    private function generateFormatKurirs()
    {
        Carbon::setLocale('id');
        $now = Carbon::now('Asia/Jakarta');
        $startDate = $now->translatedFormat('d F Y');

        $randomDay = 1;
        $kurirsFormat = [];
        $kurirslists = ['JNT', 'Anter Aja', 'Si Cepat Halu'];

        foreach($kurirslists as $kurir)
        {
            $randomDay = mt_rand(1, 3);

            /* SETTING TANGGAL */
            $endDate = $now->copy()->addDays($randomDay)->translatedFormat('d F Y');
            /* SETTING TANGGAL */

            /* SETTING HARGA */
            $price = (-5000 * $randomDay) + 20000;
            /* SETTING HARGA */

            $kurirsFormat[] = [
                'name' => $kurir,
                'price' => $price,
                'estimation' => "{$startDate} - {$endDate}"
            ];
        }

        return [
            'kurirs' => $kurirsFormat
        ];
    }

    /**
     * process save to database in function createVirtualAccount
     */
    public function saveCheckoutToDatabase(string $user_id_buyer = "", array $checkouts = [], array $kurirs = [], array $noteds = [], string $alamat = "", string $payment_method = "", string $payment_slug = "", string $payment_name = "", string $expired_at = "", int $price, array $dataXendit = [])
    {
        /* VALIDATION */
        if(!$user_id_buyer || !$checkouts || !$kurirs || !$noteds || !$alamat || !$payment_method || !$payment_slug || !$payment_name || !$expired_at || !$price || !$dataXendit)
        {
            $message = "";

            if(!$user_id_buyer)
            {
                $message = "Data User Id buyer Empty";
            }
            else if(!$checkouts)
            {
                $message = "Data Checkout Empty";
            }
            else if(!$kurirs)
            {
                $message = "Data Kurirs Empty";
            }
            else if(!$noteds)
            {
                $message = "Data Noteds Empty";
            }
            else if(!$payment_method)
            {
                $message = "Data Payment Method Empty";
            }
            else if(!$payment_slug)
            {
                $message = "Data Payment Slug Empty";
            }
            else if(!$payment_name)
            {
                $message = "Data Payment Name Empty";
            }
            else if(!$expired_at)
            {
                $message = "Expired At Empty";
            }
            else if(!$price)
            {
                $message = "Data Price Empty";
            }
            else if(!$dataXendit)
            {
                $message = "Data Xendit Empty";
            }

            return [
                'status' => 'error',
                'message' => $message
            ];
        }
        /* VALIDATION */
        
        /* SAVE TO TABLE invoice */
        // info(['action' => 'invoice create','user_id_buyer' => $user_id_buyer,'alamat_buyer' => $alamat,'payment_method' => $payment_method,'payment_slug' => $payment_slug,'payment_name' => $payment_name,'payment_account' => $dataXendit['account_number'] ?? "",'payment_reference' => $dataXendit['external_id'] ?? "",'price' => $price,'expired_at' => $expired_at,]);
        $transactionInvoice = TransactionInvoice::create([
            'user_id_buyer' => $user_id_buyer,
            'alamat_buyer' => $alamat,
            'payment_method' => $payment_method,
            'payment_slug' => $payment_slug,
            'payment_name' => $payment_name,
            'payment_account' => $dataXendit['account_number'] ?? "",
            'payment_reference' => $dataXendit['external_id'] ?? "",
            'price' => $price,
            'expired_at' => $expired_at,
        ]);
        /* SAVE TO TABLE invoice */

        /* SAVE TO TABLE */
        foreach($checkouts as $checkout)
        {
            // alamat seller
            $alamat_seller = Alamat::where('user_id', ($checkout['user_id_seller'] ?? ""))
                                   ->where('type', 'seller')
                                   ->value('alamat');
            // alamat seller

            // kurir
            $kurir_type = "";
            $kurir_price = 0;
            $kurir_estimate = "";
            foreach($kurirs as $kurir)
            {
                // info(['k' => $kurir['user_id_seller'],'c' => $checkout['user_id_seller']]);
                if(($kurir['user_id_seller'] ?? "") == ($checkout['user_id_seller'] ?? ""))
                {
                    $kurir_type = $kurir['name'] ?? "";
                    $kurir_price = $kurir['price'] ?? 0;
                    $kurir_estimate = $kurir['estimation'] ?? "";
                    // info('masuk sini kurir', ['kurir' => $kurir,'kurir_type' => $kurir_type,'kurir_price' => $kurir_price,'kurir_estimate' => $kurir_estimate,]);
                    break;
                }
            }
            // kurir

            // noted
            $noted = "";
            foreach($noteds as $item)
            {
                // info(['i' => $kurir['user_id_seller'],'c' => $checkout['user_id_seller']]);
                if(($item['user_id_seller'] ?? "") == ($checkout['user_id_seller'] ?? ""))
                {
                    $noted = $item['noted'] ?? "";
                    // info('masuk sini noted', ['item' => $item,'noted' => $noted]);
                    break;
                }
            }
            // noted

            // save to transaction_users
            $transactionNumber = Carbon::now('Asia/Jakarta')->format('YmdHis') . "-" . ($checkout['user_id_seller'] ?? "") . "-" . $user_id_buyer . "-" . ($transactionInvoice->id ?? "");
            $transactionUser = TransactionUser::create([
                'user_id_seller' => $checkout['user_id_seller'] ?? "",
                'user_id_buyer' => $user_id_buyer,
                'transaction_invoice_id' => $transactionInvoice->id ?? "",
                'transaction_number' => $transactionNumber,
                'alamat_seller' => $alamat_seller,
                'kurir_type' => $kurir_type,
                'kurir_price' => $kurir_price,
                'kurir_estimate' => $kurir_estimate,
                'noted' => $noted,
            ]);
            // save to transaction_users

            // save to transaction
            $totalPriceKeranjang = 0;
            foreach(($checkout['keranjangs'] ?? []) as $keranjang)
            {
                $totalPriceKeranjang += $keranjang['k_total_price'];
                TransactionProduct::create([
                    'user_id_seller' => $checkout['user_id_seller'] ?? "",
                    'user_id_buyer' => $user_id_buyer,
                    'product_id' => $keranjang['p_id'],
                    'transaction_user_id' => $transactionUser->id ?? "",
                    'price' => $keranjang['p_price'] ?? "",
                    'total' => $keranjang['k_total'] ?? "",
                ]);
            }
            // save to transaction

            // uddate total price keranjang to transaction user
            $transactionUser->product_price = $totalPriceKeranjang;
            $transactionUser->save();
            // uddate total price keranjang to transaction user
        }
        /* SAVE TO TABLE */

        return [
            'status' => 'success',
            'message' => 'Save Chekcout To Database Successfully'
        ];
    }

    /**
     * process delete keranjang after checkout
     */
    public function deleteKeranjangAfterCheckout(array $checkouts = [])
    {
        // info(__FUNCTION__);
        /* VALIDATION */
        if(!$checkouts)
        {
            return [
                'status' => 'error',
                'message' => 'Data Checkout Empty'
            ];
        }
        /* VALIDATION */

        /* GET KERANJANG IDS */
        $keranjangIds = [];
        
        foreach($checkouts as $checkout)
        {
            foreach(($checkout['keranjangs'] ?? []) as $keranjang)
            {
                $keranjangIds[] = $keranjang['k_id'] ?? "";
            }
        }
        /* GET KERANJANG IDS */

        /* DELETE KERANJANG */
        if($keranjangIds)
        {
            Keranjang::whereIn('id', $keranjangIds)->delete();
        }
        /* DELETE KERANJANG */

        return [
            'status' => 'success',
            'message' => 'Delete Keranjang Successfully'
        ];
    }

    public function changeStockProductAfterCheckout(array $checkouts = [])
    {
        /* VALIDATION */
        if(!$checkouts)
        {
            return [
                'status' => 'error',
                'message' => 'Data Checkout Empty'
            ];
        }
        /* VALIDATION */
        
        /* PROCESS CHANGE STOCK PRODUCT */
        foreach($checkouts as $checkout)
        {
            foreach(($checkout['keranjangs'] ?? []) as $keranjang)
            {
                $product = Product::where('id', ($keranjang['p_id'] ?? ""))
                                  ->first();
                if(!$product)
                {
                    continue;
                }

                $product->stock = max(0, $product->stock - 1);
                $product->save();
            }
        }
        /* PROCESS CHANGE STOCK PRODUCT */

        return [
            'status' => 'success',
            'message' => 'Change Stock Product Successfully'
        ];
    }
}