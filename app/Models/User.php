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
     * @return HasMany<Product> Relasi Eloquent menuju seluruh produk milik seller.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'user_id_seller');
    }

    /**
     * Mengambil seluruh item keranjang milik user sebagai buyer.
     *
     * @return HasMany<Keranjang> Relasi Eloquent menuju seluruh item keranjang milik buyer.
     */
    public function buyerKeranjangs(): HasMany
    {
        return $this->hasMany(Keranjang::class, 'user_id_buyer');
    }

    /**
     * Mengambil seluruh item keranjang yang menjual produk user.
     *
     * @return HasMany<Keranjang> Relasi Eloquent menuju seluruh item keranjang yang terkait dengan seller.
     */
    public function sellerKeranjangs(): HasMany
    {
        return $this->hasMany(Keranjang::class, 'user_id_seller');
    }

    /**
     * Mengambil seluruh alamat milik user.
     *
     * @return HasMany<Alamat> Relasi Eloquent menuju seluruh alamat milik user.
     */
    public function alamats(): HasMany
    {
        return $this->hasMany(Alamat::class, 'user_id');
    }

    /**
     * Mengambil profil toko milik user.
     *
     * @return HasOne<Company> Relasi Eloquent menuju profil toko milik user.
     */
    public function company(): HasOne
    {
        return $this->hasOne(Company::class, 'user_id');
    }

    /**
     * Mengambil saldo yang dimiliki user.
     *
     * @return HasOne<SaldoUser> Relasi Eloquent menuju saldo milik user.
     */
    public function saldoUser(): HasOne
    {
        return $this->hasOne(SaldoUser::class, 'user_id');
    }

    /**
     * Mengambil seluruh audit yang dimiliki user sebagai actor.
     *
     * @return HasMany<AuditLog> Relasi Eloquent menuju seluruh audit milik actor.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_user_id');
    }
}
