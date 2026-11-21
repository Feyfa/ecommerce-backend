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
        // --- step 1 - request health tanpa header negara - start ---
        $response = $this->get('/');
        // --- step 1 - request health tanpa header negara - end ---

        // --- step 2 - verifikasi health, negara kosong, dan kebijakan cache - start ---
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
        // --- step 2 - verifikasi health, negara kosong, dan kebijakan cache - end ---
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
        // --- step 1 - siapkan waktu tetap dan variasi header diagnostik - start ---
        $this->freezeTime();

        $countryHeaders = [
            'ID',
            'XX',
            'T1',
            ' id ',
        ];
        // --- step 1 - siapkan waktu tetap dan variasi header diagnostik - end ---

        // --- step 2 - verifikasi setiap header pada response root - start ---
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
        // --- step 2 - verifikasi setiap header pada response root - end ---
    }
}
