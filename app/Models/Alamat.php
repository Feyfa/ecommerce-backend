<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'latitude',
        'longitude',
        'geoapify_place_id',
        'formatted_address',
        'address_detail',
        'location_source',
        'enable',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'enable' => 'boolean',
    ];

    /**
     * Mengambil user pemilik alamat.
     *
     * @return BelongsTo  Relasi Eloquent menuju model induk yang terkait.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
