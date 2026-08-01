<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * Membentuk state default user lokal dengan UUID, nama, email unik, dan kredensial factory. Data
     * yang dihasilkan digunakan sebagai fixture test tanpa bergantung pada identity provider
     * eksternal.
     *
     * @return array<string, mixed>  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
