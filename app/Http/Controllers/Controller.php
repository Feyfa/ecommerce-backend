<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    protected function generateRandomNumber($length)
    {
        $value = '';

        for($i = 0; $i < $length; $i++)
        {
            $value .= rand(0, 9);
        }

        return $value;
    }
}
