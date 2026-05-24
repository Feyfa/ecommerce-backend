<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Alamat extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'alamats';
    protected $fillable = [
        'user_id',
        'type',
        'place',
        'name',
        'phone',
        'alamat',
        'enable',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
