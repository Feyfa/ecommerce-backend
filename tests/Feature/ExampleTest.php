<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Memverifikasi kontrak health root backend dan diagnostik negara tanpa akses Cloudflare langsung.
 */
class ExampleTest extends TestCase
{
    /**
     * Memastikan root tetap mengembalikan health JSON dan negara null tanpa header Cloudflare.
     *
     * @return void Kontrak health dan larangan penyimpanan cache diperiksa melalui assertion.
     */
    public function test_the_root_endpoint_returns_backend_health_response(): void
    {
        // --- step 1 - start - request health tanpa header negara
        $response = $this->get('/');
        // --- step 1 - end - request health tanpa header negara

        // --- step 2 - start - verifikasi health, negara kosong, dan kebijakan cache
        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'service' => 'backend',
            ])
            ->assertJsonStructure([
                'status',
                'service',
                'timestamp',
                'cf_ipcountry',
            ])
            ->assertJsonPath('cf_ipcountry', null);

        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        // --- step 2 - end - verifikasi health, negara kosong, dan kebijakan cache
    }

    /**
     * Memastikan nilai negara biasa, kode khusus, dan teks mentah diteruskan tanpa normalisasi.
     *
     * Header pada test dibuat secara lokal, sehingga hasilnya hanya membuktikan pembacaan oleh
     * Laravel. JSON tetap terbatas pada field health dan negara yang diterima.
     *
     * @return void Nilai header, kontrak JSON, dan larangan penyimpanan cache diperiksa melalui assertion.
     */
    public function test_the_root_endpoint_preserves_received_country_headers(): void
    {
        // --- step 1 - start - siapkan waktu tetap dan variasi header diagnostik
        $this->freezeTime();

        $countryHeaders = [
            'ID',
            'XX',
            'T1',
            ' id ',
        ];
        // --- step 1 - end - siapkan waktu tetap dan variasi header diagnostik

        // --- step 2 - start - verifikasi setiap header pada response root
        foreach ($countryHeaders as $countryHeader) {
            $response = $this->get('/', [
                'CF-IPCountry' => $countryHeader,
            ]);

            $response->assertOk()
                ->assertExactJson([
                    'status' => 'ok',
                    'service' => 'backend',
                    'timestamp' => now()->toIso8601String(),
                    'cf_ipcountry' => $countryHeader,
                ]);

            $this->assertTrue($response->headers->hasCacheControlDirective('private'));
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        }
        // --- step 2 - end - verifikasi setiap header pada response root
    }
}
