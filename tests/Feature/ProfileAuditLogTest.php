<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Memverifikasi audit perubahan Pengaturan Pengguna dan foto profil tetap owner-scoped.
 */
class ProfileAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /**
     * Menyiapkan user terautentikasi untuk setiap skenario audit profil.
     *
     * @return void Tidak mengembalikan nilai; fixture disimpan pada instance test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        $this->user = User::factory()->create([
            'phone' => '08120000133',
            'tanggal_lahir' => '1995-08-17',
            'jenis_kelamin' => 'Laki-Laki',
        ]);
        $this->actingAs($this->user);
    }

    /**
     * Memverifikasi update Pengaturan Pengguna menyimpan snapshot dan perubahan yang benar.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; assertion menyatakan keberhasilan skenario.
     */
    public function successful_setting_update_records_the_profile_snapshot_and_real_changes(): void
    {
        $this->putJson("/api/user/{$this->user->id}", [
            'phone' => '08129999133',
            'tanggal_lahir' => '1996-01-02',
            'jenis_kelamin' => 'Perempuan',
        ])->assertOk();

        $audit = AuditLog::query()->sole();

        $this->assertSame(AuditEvent::PROFILE_UPDATED, $audit->event);
        $this->assertSame('profile', $audit->category);
        $this->assertSame($this->user->id, $audit->actor_user_id);
        $this->assertSame('user', $audit->subject_type);
        $this->assertSame('08129999133', $audit->context['profile_snapshot']['phone']);
        $this->assertSame(['phone', 'tanggal_lahir', 'jenis_kelamin'], array_column($audit->context['changes'], 'field'));
    }

    /**
     * Memverifikasi simpan identik tetap tercatat tanpa mengarang daftar perubahan.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; assertion menyatakan keberhasilan skenario.
     */
    public function identical_setting_update_is_recorded_without_false_changes(): void
    {
        $this->putJson("/api/user/{$this->user->id}", $this->profilePayload())->assertOk();

        $audit = AuditLog::query()->sole();

        $this->assertSame(AuditEvent::PROFILE_UPDATED, $audit->event);
        $this->assertSame([], $audit->context['changes']);
    }

    /**
     * Memverifikasi collection menyamarkan phone sementara detail owner membuka nilai penuh.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; assertion menyatakan keberhasilan skenario.
     */
    public function collection_masks_profile_phone_while_owner_detail_reveals_it(): void
    {
        $this->putJson("/api/user/{$this->user->id}", $this->profilePayload([
            'phone' => '08129999133',
        ]))->assertOk();

        $audit = AuditLog::query()->sole();

        $this->getJson('/api/audit-logs?event=profile.updated')
            ->assertOk()
            ->assertJsonPath('data.0.profile_snapshot.phone', '0812****133')
            ->assertJsonPath('data.0.changes.0.before', '0812****133')
            ->assertJsonPath('data.0.changes.0.after', '0812****133');

        $this->getJson("/api/audit-logs/{$audit->id}")
            ->assertOk()
            ->assertJsonPath('data.profile_snapshot.phone', '08129999133')
            ->assertJsonPath('data.changes.0.before', '08120000133')
            ->assertJsonPath('data.changes.0.after', '08129999133');
    }

    /**
     * Memverifikasi validasi dan ownership gagal tidak membuat audit baru.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; assertion menyatakan keberhasilan skenario.
     */
    public function invalid_or_foreign_profile_updates_do_not_create_audit_rows(): void
    {
        $otherUser = User::factory()->create();

        $this->putJson("/api/user/{$otherUser->id}", $this->profilePayload())->assertForbidden();
        $this->putJson("/api/user/{$this->user->id}", ['phone' => ''])->assertUnprocessable();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /**
     * Memverifikasi audit gagal membatalkan perubahan data Pengaturan Pengguna.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; assertion menyatakan keberhasilan skenario.
     */
    public function audit_failure_rolls_back_the_profile_update(): void
    {
        $this->partialMock(AuditLogService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('profileSnapshot')->andReturn([
                'phone' => '08120000133',
                'tanggal_lahir' => '1995-08-17',
                'jenis_kelamin' => 'Laki-Laki',
                'has_profile_image' => false,
            ]);
            $mock->shouldReceive('recordProfileUpdated')
                ->once()
                ->andThrow(new RuntimeException('Audit persistence failed.'));
        });

        $this->putJson("/api/user/{$this->user->id}", $this->profilePayload([
            'phone' => '08129999133',
        ]))->assertServerError();

        $this->assertSame('08120000133', $this->user->refresh()->phone);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /**
     * Memverifikasi unggah dan hapus foto menghasilkan event berbeda tanpa path storage pada context.
     *
     * @test
     *
     * @return void Tidak mengembalikan nilai; assertion menyatakan keberhasilan skenario.
     */
    public function profile_image_operations_record_safe_distinct_events(): void
    {
        Storage::fake('public');

        $this->postJson('/api/user/image', [
            'id' => $this->user->id,
            'file' => UploadedFile::fake()->image('profile.png'),
        ])->assertOk();

        $uploadedUser = $this->user->refresh();
        $this->assertNotNull($uploadedUser->img);
        $this->assertSame(AuditEvent::PROFILE_IMAGE_UPLOADED, AuditLog::query()->sole()->event);
        $this->assertStringNotContainsString($uploadedUser->img, json_encode(AuditLog::query()->sole()->context));

        $this->deleteJson('/api/user/image', ['img' => $uploadedUser->img])->assertOk();

        $this->assertSame(
            [AuditEvent::PROFILE_IMAGE_UPLOADED->value, AuditEvent::PROFILE_IMAGE_DELETED->value],
            AuditLog::query()
                ->orderBy('created_at')
                ->get()
                ->map(fn (AuditLog $audit): string => $audit->event->value)
                ->all(),
        );
    }

    /**
     * Membentuk payload Pengaturan Pengguna yang sesuai dengan state fixture.
     *
     * @param  array<string, mixed>  $overrides Nilai yang menimpa payload dasar.
     *
     * @return array<string, mixed> Payload untuk endpoint update profil.
     */
    private function profilePayload(array $overrides = []): array
    {
        return array_merge([
            'phone' => '08120000133',
            'tanggal_lahir' => '1995-08-17',
            'jenis_kelamin' => 'Laki-Laki',
        ], $overrides);
    }
}
