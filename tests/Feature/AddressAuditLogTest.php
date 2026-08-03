<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Models\Alamat;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Memverifikasi audit alamat buyer tersimpan owner-scoped tanpa membocorkan koordinat pinpoint.
 */
class AddressAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    /**
     * Menyiapkan fixture dan dependency sebelum setiap pengujian.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        config(['services.geoapify.key' => 'geoapify-test-key']);
        $this->fakeIndonesiaVerification();
        $this->buyer = User::factory()->create();
        $this->actingAs($this->buyer);
    }

    /**
     * Memverifikasi bahwa penambahan alamat menyimpan snapshot owner-scoped tanpa koordinat.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function successful_create_records_an_owner_scoped_address_snapshot(): void
    {
        $this->postJson('/api/alamat/buyer', $this->addressPayload(['enable' => true]))->assertOk();

        $alamat = Alamat::query()->sole();
        $audit = AuditLog::query()->sole();

        $this->assertSame(AuditEvent::ADDRESS_CREATED, $audit->event);
        $this->assertSame($this->buyer->id, $audit->actor_user_id);
        $this->assertSame('address', $audit->subject_type);
        $this->assertSame($alamat->id, $audit->subject_id);
        $this->assertSame('Rumah', $audit->context['subject_name']);
        $this->assertSame([
            'place' => 'Rumah',
            'recipient_name' => 'Rafeyfa Zulfiyani',
            'phone' => '091818828282',
            'formatted_address' => 'Jalan Medan Merdeka, Gambir, Jakarta Pusat 10110, Indonesia',
            'address_detail' => 'Blok B No. 12',
            'enable' => true,
        ], $audit->context['address_snapshot']);
    }

    /**
     * Memverifikasi bahwa koordinat pinpoint tidak pernah masuk ke context maupun response audit.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function coordinates_are_never_stored_or_exposed(): void
    {
        $this->postJson('/api/alamat/buyer', $this->addressPayload(['enable' => true]))->assertOk();

        $audit = AuditLog::query()->sole();
        $context = json_encode($audit->context, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('latitude', $context);
        $this->assertStringNotContainsString('longitude', $context);
        $this->assertStringNotContainsString('geoapify', $context);

        $detail = $this->getJson("/api/audit-logs/{$audit->id}")->assertOk();
        $this->assertStringNotContainsString('latitude', $detail->getContent());
        $this->assertStringNotContainsString('longitude', $detail->getContent());
    }

    /**
     * Memverifikasi bahwa update hanya mencatat field alamat yang benar-benar berubah.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function update_records_only_real_value_changes(): void
    {
        $alamat = $this->existingAddress();

        $this->putJson("/api/alamat/buyer/{$alamat->id}", $this->addressPayload([
            'phone' => '098765432100',
            'address_detail' => 'Blok C No. 20',
        ]))->assertOk();

        $audit = AuditLog::query()->where('event', AuditEvent::ADDRESS_UPDATED->value)->sole();
        $changedFields = array_column($audit->context['changes'], 'field');

        sort($changedFields);
        $this->assertSame(['address_detail', 'phone'], $changedFields);
        $this->assertSame([
            'field' => 'phone',
            'label' => 'Nomor Telepon',
            'before' => '091818828282',
            'after' => '098765432100',
        ], $audit->context['changes'][0]);
    }

    /**
     * Memverifikasi bahwa update identik tetap tercatat tanpa mengarang perubahan.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function identical_update_is_recorded_without_false_changes(): void
    {
        $alamat = $this->existingAddress();

        $this->putJson("/api/alamat/buyer/{$alamat->id}", $this->addressPayload())->assertOk();

        $audit = AuditLog::query()->where('event', AuditEvent::ADDRESS_UPDATED->value)->sole();

        $this->assertSame([], $audit->context['changes']);
    }

    /**
     * Memverifikasi bahwa snapshot terakhir tetap terbaca setelah alamatnya dihapus.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function delete_keeps_the_last_snapshot_after_the_address_is_gone(): void
    {
        $alamat = $this->existingAddress();

        $this->deleteJson("/api/alamat/buyer/{$alamat->id}")->assertOk();

        $this->assertDatabaseCount('alamats', 0);
        $audit = AuditLog::query()->where('event', AuditEvent::ADDRESS_DELETED->value)->sole();

        $this->assertSame($alamat->id, $audit->subject_id);
        $this->assertSame('Rafeyfa Zulfiyani', $audit->context['address_snapshot']['recipient_name']);
        $this->assertArrayNotHasKey('replacement_address', $audit->context);
    }

    /**
     * Memverifikasi bahwa penghapusan alamat utama mencatat alamat pengganti yang dipilih sistem.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function delete_records_the_replacement_address_chosen_by_the_system(): void
    {
        $active = $this->existingAddress();
        $fallback = $this->existingAddress(['place' => 'Kantor', 'enable' => false]);

        $this->deleteJson("/api/alamat/buyer/{$active->id}")->assertOk();

        $audit = AuditLog::query()->where('event', AuditEvent::ADDRESS_DELETED->value)->sole();

        $this->assertSame($fallback->id, $audit->context['replacement_address']['id']);
        $this->assertSame('Kantor', $audit->context['replacement_address']['place']);
    }

    /**
     * Memverifikasi bahwa perpindahan alamat utama mencatat alamat sebelumnya.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function selecting_a_main_address_records_the_previous_one(): void
    {
        $previous = $this->existingAddress();
        $target = $this->existingAddress(['place' => 'Kantor', 'enable' => false]);

        $this->putJson("/api/alamat-enable/buyer/{$target->id}")->assertOk();

        $audit = AuditLog::query()->where('event', AuditEvent::ADDRESS_SELECTED->value)->sole();

        $this->assertSame($target->id, $audit->subject_id);
        $this->assertSame($previous->id, $audit->context['previous_address']['id']);
        $this->assertSame('Rumah', $audit->context['previous_address']['place']);
    }

    /**
     * Memverifikasi bahwa collection menyamarkan data pribadi sementara detail membukanya.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function collection_masks_personal_data_while_detail_reveals_it(): void
    {
        $this->postJson('/api/alamat/buyer', $this->addressPayload(['enable' => true]))->assertOk();
        $audit = AuditLog::query()->sole();

        $this->getJson('/api/audit-logs?event=address.created')
            ->assertOk()
            ->assertJsonPath('data.0.subject.name', 'Rumah')
            ->assertJsonPath('data.0.address_snapshot.recipient_name', 'Rafeyfa Z.')
            ->assertJsonPath('data.0.address_snapshot.phone', '0918****282')
            ->assertJsonMissingPath('data.0.address_snapshot.address_detail')
            ->assertJsonMissingPath('data.0.context');

        $this->getJson("/api/audit-logs/{$audit->id}")
            ->assertOk()
            ->assertJsonPath('data.address_snapshot.recipient_name', 'Rafeyfa Zulfiyani')
            ->assertJsonPath('data.address_snapshot.phone', '091818828282')
            ->assertJsonPath('data.address_snapshot.address_detail', 'Blok B No. 12');
    }

    /**
     * Memverifikasi bahwa nilai before/after pada collection ikut disamarkan.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function collection_masks_change_rows_that_contain_personal_data(): void
    {
        $alamat = $this->existingAddress();

        $this->putJson("/api/alamat/buyer/{$alamat->id}", $this->addressPayload([
            'phone' => '098765432100',
        ]))->assertOk();

        $this->getJson('/api/audit-logs?event=address.updated')
            ->assertOk()
            ->assertJsonPath('data.0.changes.0.before', '0918****282')
            ->assertJsonPath('data.0.changes.0.after', '0987****100');
    }

    /**
     * Memverifikasi bahwa alamat milik user lain dan payload gagal validasi tidak membuat audit.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function foreign_address_and_failed_validation_do_not_create_audit_rows(): void
    {
        $otherBuyer = User::factory()->create();
        $foreignAddress = $this->existingAddress(['user_id' => $otherBuyer->id]);

        $this->putJson("/api/alamat/buyer/{$foreignAddress->id}", $this->addressPayload())->assertStatus(400);
        $this->deleteJson("/api/alamat/buyer/{$foreignAddress->id}")->assertStatus(400);
        $this->postJson('/api/alamat/buyer', ['place' => 'Rumah'])->assertStatus(422);

        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame('Rafeyfa Zulfiyani', $foreignAddress->refresh()->name);
    }

    /**
     * Memverifikasi bahwa kegagalan audit membatalkan mutasi alamatnya.
     *
     * @test
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function audit_failure_rolls_back_the_address_mutation(): void
    {
        $alamat = $this->existingAddress();

        $this->partialMock(AuditLogService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('recordAddressUpdated')
                ->once()
                ->andThrow(new RuntimeException('Audit persistence failed.'));
        });

        $this->putJson("/api/alamat/buyer/{$alamat->id}", $this->addressPayload([
            'phone' => '098765432100',
        ]))->assertServerError();

        $this->assertSame('091818828282', $alamat->refresh()->phone);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /**
     * Membentuk payload mutasi alamat buyer yang sudah memuat data pinpoint valid.
     *
     * @param  array<string, mixed>  $overrides  Nilai yang menimpa payload dasar untuk skenario tertentu.
     *
     * @return array<string, mixed>  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function addressPayload(array $overrides = []): array
    {
        return array_merge([
            'place' => 'Rumah',
            'name' => 'Rafeyfa Zulfiyani',
            'phone' => '091818828282',
            'alamat' => '',
            'location_source' => 'map',
            'latitude' => -6.1755043,
            'longitude' => 106.8272634,
            'geoapify_place_id' => 'geoapify-test-place',
            'formatted_address' => 'Jalan Medan Merdeka, Gambir, Jakarta Pusat 10110, Indonesia',
            'address_detail' => 'Blok B No. 12',
        ], $overrides);
    }

    /**
     * Membuat alamat buyer terverifikasi langsung melalui model untuk kebutuhan fixture.
     *
     * @param  array<string, mixed>  $overrides  Nilai yang menimpa atribut alamat dasar.
     *
     * @return Alamat  Model alamat yang berhasil dibuat.
     */
    private function existingAddress(array $overrides = []): Alamat
    {
        return Alamat::create(array_merge([
            'user_id' => $this->buyer->id,
            'type' => 'buyer',
            'place' => 'Rumah',
            'name' => 'Rafeyfa Zulfiyani',
            'phone' => '091818828282',
            'alamat' => 'Blok B No. 12, Jalan Medan Merdeka, Gambir, Jakarta Pusat 10110, Indonesia',
            'latitude' => -6.1755043,
            'longitude' => 106.8272634,
            'geoapify_place_id' => 'geoapify-test-place',
            'formatted_address' => 'Jalan Medan Merdeka, Gambir, Jakarta Pusat 10110, Indonesia',
            'address_detail' => 'Blok B No. 12',
            'location_source' => 'map',
            'enable' => true,
        ], $overrides));
    }

    /**
     * Memalsukan response verifikasi Geoapify agar pengujian tidak bergantung pada provider.
     *
     * @return void  Tidak mengembalikan nilai; fake dipasang pada HTTP client aplikasi.
     */
    private function fakeIndonesiaVerification(): void
    {
        Http::fake(fn () => Http::response(['results' => [[
            'country_code' => 'id',
            'formatted' => 'Jalan Medan Merdeka, Gambir, Jakarta Pusat 10110, Indonesia',
            'place_id' => 'verified-place-id',
        ]]], 200));
    }
}
