<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OtpLogin extends Model
{
    use HasFactory;

    protected $table = 'otp_login';

    protected $fillable = [
        'otp',
        'email',
        'expired'
    ];
}
