<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Memverifikasi audit perubahan Profil Toko dan foto toko tetap owner-scoped.
 */
class CompanyAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /**
     * Menyiapkan user terautentikasi dan stub verifikasi lokasi untuk setiap skenario.
     *
     * @return void Tidak mengembalikan nilai; fixture disimpan pada instance test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        config(['services.geoapify.key' => 'geoapify-test-key']);
        $this->fakeIndonesiaVerification();
        $this->user = User::factory()->create([
            'email' => 'seller@example.com',
            'phone' => '08120000133',
        ]);
        $this->actingAs($this->user);
    }

    /**
     * Memverifikasi update Profil Toko menyimpan snapshot dan perubahan yang benar.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; assertion menyatakan keberhasilan skenario.
     */
    public function successful_company_update_records_the_snapshot_and_real_changes(): void
    {
        $this->putJson('/api/company', $this->companyPayload([
            'name' => 'Toko Baru',
            'phone' => '08129999133',
            'description' => 'Deskripsi toko',
            'address_detail' => 'Lantai 2',
        ]))->assertOk();

        $audit = AuditLog::query()->sole();

        $this->assertSame(AuditEvent::COMPANY_UPDATED, $audit->event);
        $this->assertSame('company', $audit->category);
        $this->assertSame($this->user->id, $audit->actor_user_id);
        $this->assertSame('company', $audit->subject_type);
        $this->assertSame('Toko Baru', $audit->context['company_snapshot']['name']);
        $this->assertSame('08129999133', $audit->context['company_snapshot']['phone']);
        $this->assertSame(
            'Jalan Medan Merdeka, Gambir, Jakarta Pusat 10110, Indonesia',
            $audit->context['company_snapshot']['formatted_address'],
        );
        $this->assertSame('Lantai 2', $audit->context['company_snapshot']['address_detail']);
        $this->assertContains('name', array_column($audit->context['changes'], 'field'));
        $this->assertContains('phone', array_column($audit->context['changes'], 'field'));
        $this->assertArrayNotHasKey('latitude', $audit->context['company_snapshot']);
        $this->assertArrayNotHasKey('geoapify_place_id', $audit->context['company_snapshot']);
    }

    /**
     * Memverifikasi simpan identik tetap tercatat tanpa mengarang daftar perubahan.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; assertion menyatakan keberhasilan skenario.
     */
    public function identical_company_update_is_recorded_without_false_changes(): void
    {
        $this->putJson('/api/company', $this->companyPayload())->assertOk();
        AuditLog::query()->delete();

        $this->putJson('/api/company', $this->companyPayload())->assertOk();

        $audit = AuditLog::query()->sole();

        $this->assertSame(AuditEvent::COMPANY_UPDATED, $audit->event);
        $this->assertSame([], $audit->context['changes']);
    }

    /**
     * Memverifikasi collection menyamarkan phone sementara detail owner membuka nilai penuh.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; assertion menyatakan keberhasilan skenario.
     */
    public function collection_masks_company_phone_while_owner_detail_reveals_it(): void
    {
        $this->putJson('/api/company', $this->companyPayload())->assertOk();
        AuditLog::query()->delete();

        $this->putJson('/api/company', $this->companyPayload([
            'phone' => '08129999133',
        ]))->assertOk();

        $audit = AuditLog::query()->sole();

        $this->getJson('/api/audit-logs?event=company.updated')
            ->assertOk()
            ->assertJsonPath('data.0.company_snapshot.phone', '0812****133')
            ->assertJsonPath('data.0.changes.0.field', 'phone')
            ->assertJsonPath('data.0.changes.0.before', '0812****133')
            ->assertJsonPath('data.0.changes.0.after', '0812****133');

        $this->getJson("/api/audit-logs/{$audit->id}")
            ->assertOk()
            ->assertJsonPath('data.company_snapshot.phone', '08129999133')
            ->assertJsonPath('data.changes.0.before', '08120000133')
            ->assertJsonPath('data.changes.0.after', '08129999133');
    }

    /**
     * Memverifikasi validasi gagal tidak membuat audit baru.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; assertion menyatakan keberhasilan skenario.
     */
    public function invalid_company_updates_do_not_create_audit_rows(): void
    {
        $this->putJson('/api/company', [
            'name' => '',
            'email' => 'bukan-email',
            'phone' => '',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /**
     * Memverifikasi audit gagal membatalkan perubahan data Profil Toko.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; assertion menyatakan keberhasilan skenario.
     */
    public function audit_failure_rolls_back_the_company_update(): void
    {
        Company::query()->create([
            'user_id' => $this->user->id,
            'name' => 'Toko Awal',
            'email' => 'seller@example.com',
            'phone' => '08120000133',
            'description' => 'Deskripsi awal',
        ]);

        $this->partialMock(AuditLogService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('companySnapshot')->andReturn([
                'name' => 'Toko Awal',
                'email' => 'seller@example.com',
                'phone' => '08120000133',
                'description' => 'Deskripsi awal',
                'formatted_address' => 'Jalan Medan Merdeka, Gambir, Jakarta Pusat 10110, Indonesia',
                'address_detail' => 'Blok B No. 12',
                'has_company_image' => false,
            ]);
            $mock->shouldReceive('recordCompanyUpdated')
                ->once()
                ->andThrow(new RuntimeException('Audit persistence failed.'));
        });

        $this->putJson('/api/company', $this->companyPayload([
            'name' => 'Toko Gagal',
            'phone' => '08129999133',
        ]))->assertServerError();

        $company = Company::query()->where('user_id', $this->user->id)->firstOrFail();
        $this->assertSame('Toko Awal', $company->name);
        $this->assertSame('08120000133', $company->phone);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /**
     * Memverifikasi unggah dan hapus foto menghasilkan event berbeda tanpa path storage pada context.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; assertion menyatakan keberhasilan skenario.
     */
    public function company_image_operations_record_safe_distinct_events(): void
    {
        Storage::fake('public');

        $this->postJson('/api/company/image', [
            'file' => UploadedFile::fake()->image('store.png'),
        ])->assertOk();

        $company = Company::query()->where('user_id', $this->user->id)->firstOrFail();
        $this->assertNotNull($company->img);
        $this->assertSame(AuditEvent::COMPANY_IMAGE_UPLOADED, AuditLog::query()->sole()->event);
        $this->assertStringNotContainsString($company->img, json_encode(AuditLog::query()->sole()->context));

        $this->deleteJson('/api/company/image')->assertOk();

        $this->assertSame(
            [AuditEvent::COMPANY_IMAGE_UPLOADED->value, AuditEvent::COMPANY_IMAGE_DELETED->value],
            AuditLog::query()
                ->orderBy('created_at')
                ->get()
                ->map(fn (AuditLog $audit): string => $audit->event->value)
                ->all(),
        );
    }

    /**
     * Membentuk payload Profil Toko yang valid untuk endpoint update.
     *
     * @param  array<string, mixed>  $overrides Nilai yang menimpa payload dasar.
     *
     * @return array<string, mixed> Payload untuk endpoint update profil toko.
     */
    private function companyPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Toko Awal',
            'email' => 'seller@example.com',
            'phone' => '08120000133',
            'description' => 'Deskripsi awal',
            'alamat' => '',
            'location_source' => 'map',
            'latitude' => -6.1755043,
            'longitude' => 106.8272634,
            'geoapify_place_id' => 'geoapify-test-place',
            'formatted_address' => 'Client Address Should Be Ignored',
            'address_detail' => 'Blok B No. 12',
        ], $overrides);
    }

    /**
     * Memalsukan response reverse-geocoding Indonesia dengan metadata server yang deterministik.
     *
     * @return void Tidak mengembalikan nilai; stub HTTP disiapkan untuk verifikasi lokasi.
     */
    private function fakeIndonesiaVerification(): void
    {
        Http::fake(function () {
            return Http::response(['results' => [[
                'country_code' => 'id',
                'formatted' => 'Jalan Medan Merdeka, Gambir, Jakarta Pusat 10110, Indonesia',
                'place_id' => 'verified-place-id',
            ]]], 200);
        });
    }
}
