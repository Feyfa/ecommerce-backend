<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Menjalankan seeder referensi yang diperlukan aplikasi pada environment yang sedang dipersiapkan.
     * Urutan pemanggilan dijaga agar tabel induk tersedia sebelum data turunannya dibuat.
     *
     * @return void  Tidak mengembalikan nilai; data referensi ditulis langsung ke database.
     */
    public function run(): void
    {
        // \App\Models\User::factory(10)->create();

        // \App\Models\User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);

        $this->call([
            PaymentListSeeder::class,
        ]);
    }
}
