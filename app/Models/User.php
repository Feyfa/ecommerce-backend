<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, HasUuids, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'clerk_user_id',
        'img',
        'name',
        'email',
        'phone',
        'jenis_kelamin',
        'tanggal_lahir',
        // 'alamat',
    ];

    /**
     * Mengambil seluruh produk yang dimiliki user sebagai seller.
     *
     * @return HasMany  Relasi Eloquent menuju seluruh model turunan yang terkait.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'user_id_seller');
    }

    /**
     * Mengambil seluruh item keranjang milik user sebagai buyer.
     *
     * @return HasMany  Relasi Eloquent menuju seluruh model turunan yang terkait.
     */
    public function buyerKeranjangs(): HasMany
    {
        return $this->hasMany(Keranjang::class, 'user_id_buyer');
    }

    /**
     * Mengambil seluruh item keranjang yang menjual produk user.
     *
     * @return HasMany  Relasi Eloquent menuju seluruh model turunan yang terkait.
     */
    public function sellerKeranjangs(): HasMany
    {
        return $this->hasMany(Keranjang::class, 'user_id_seller');
    }

    /**
     * Mengambil seluruh alamat milik user.
     *
     * @return HasMany  Relasi Eloquent menuju seluruh model turunan yang terkait.
     */
    public function alamats(): HasMany
    {
        return $this->hasMany(Alamat::class, 'user_id');
    }

    /**
     * Mengambil profil toko milik user.
     *
     * @return HasOne  Relasi Eloquent menuju satu model turunan yang terkait.
     */
    public function company(): HasOne
    {
        return $this->hasOne(Company::class, 'user_id');
    }

    /**
     * Mengambil saldo yang dimiliki user.
     *
     * @return HasOne  Relasi Eloquent menuju satu model turunan yang terkait.
     */
    public function saldoUser(): HasOne
    {
        return $this->hasOne(SaldoUser::class, 'user_id');
    }

    /**
     * Mengambil seluruh audit yang dimiliki user sebagai actor.
     *
     * @return HasMany  Relasi Eloquent menuju seluruh model turunan yang terkait.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_user_id');
    }
}
