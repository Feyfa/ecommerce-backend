<?php 

namespace App\Services;

use App\Models\Alamat;
use App\Models\Company;

class CompanyService
{
    public function getCompany(string $user_id = "")
    {
        /* GET COMPANY */
        $company = Company::where('user_id', $user_id)->first();
        $companyFillables = (new Company())->getFillable();

        $companyFormat = [];
        foreach($companyFillables as $field)
        {
            $companyFormat[$field] = $company->$field ?? "";
        }
        /* GET COMPANY */
        
        /* GET ALAMAT */
        $alamat = Alamat::where('user_id', $user_id)
                        ->where('type', 'seller')
                        ->orderBy('id', 'DESC')
                        ->first();
        $companyFormat['alamat'] = $alamat->alamat ?? "";
        /* GET ALAMAT */

        return ['status' => 'success', 'company' => $companyFormat];
    }
}