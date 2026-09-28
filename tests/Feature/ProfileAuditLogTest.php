<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Mockery;
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
     * Memastikan profil dapat dibaca oleh user lokal dan menghasilkan 404 bila user tidak tersedia.
     *
     * @return void Tidak mengembalikan nilai; assertion menjaga kontrak response profil.
     *
     * @test
     */
    public function profile_show_handles_missing_authenticated_user(): void
    {
        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.id', $this->user->id);

        Auth::logout();

        $this->getJson('/api/user')
            ->assertNotFound()
            ->assertJsonPath('message', 'User Not Found');
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

        $this->putJson('/api/user/not-a-uuid', ['phone' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('result', 'error');
        $this->putJson("/api/user/{$otherUser->id}", ['phone' => ''])
            ->assertForbidden();
        $this->putJson("/api/user/{$this->user->id}", ['phone' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('result', 'error');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /**
     * Memastikan field profil opsional menerima null dan string kosong sebagai nilai yang dikosongkan.
     *
     * @return void Tidak mengembalikan nilai; assertion memeriksa response dan state profil.
     *
     * @test
     */
    public function optional_profile_fields_accept_null_and_empty_strings(): void
    {
        $this->putJson("/api/user/{$this->user->id}", $this->profilePayload([
            'jenis_kelamin' => null,
            'tanggal_lahir' => null,
        ]))->assertOk()
            ->assertJsonPath('user.jenis_kelamin', null)
            ->assertJsonPath('user.tanggal_lahir', null);

        $this->putJson("/api/user/{$this->user->id}", $this->profilePayload([
            'jenis_kelamin' => '',
            'tanggal_lahir' => '',
        ]))->assertOk()
            ->assertJsonPath('user.jenis_kelamin', null)
            ->assertJsonPath('user.tanggal_lahir', null);

        $this->assertNull($this->user->refresh()->jenis_kelamin);
        $this->assertNull($this->user->tanggal_lahir);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    /**
     * Menolak tipe dan format field profil opsional yang tidak sesuai sebelum mutasi atau audit.
     *
     * @return void Tidak mengembalikan nilai; assertion menjaga data profil lama tetap utuh.
     *
     * @test
     */
    public function invalid_optional_profile_fields_do_not_change_user_or_audit(): void
    {
        $invalidFields = [
            ['jenis_kelamin' => ['Laki-Laki']],
            ['jenis_kelamin' => str_repeat('x', 21)],
            ['tanggal_lahir' => 'not-a-date'],
            ['tanggal_lahir' => ['1995-08-17']],
        ];

        foreach ($invalidFields as $fields) {
            $this->putJson("/api/user/{$this->user->id}", $this->profilePayload($fields))
                ->assertUnprocessable()
                ->assertJsonPath('result', 'error');
        }

        $this->user->refresh();
        $this->assertSame('Laki-Laki', $this->user->jenis_kelamin);
        $this->assertSame('1995-08-17', $this->user->tanggal_lahir);
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
        $disk = Storage::fake('public');

        Carbon::setTestNow('2026-09-28 12:00:00');
        try {
            $this->postJson('/api/user/image', [
                'id' => $this->user->id,
                'file' => UploadedFile::fake()->image('profile.png'),
            ])->assertOk();

            $uploadedUser = $this->user->refresh();
            $this->assertNotNull($uploadedUser->img);
            $firstImage = $uploadedUser->img;
            $disk->assertExists($firstImage);
            $this->assertSame(AuditEvent::PROFILE_IMAGE_UPLOADED, AuditLog::query()->sole()->event);
            $this->assertStringNotContainsString($uploadedUser->img, json_encode(AuditLog::query()->sole()->context));

            $this->postJson('/api/user/image', [
                'id' => $this->user->id,
                'file' => UploadedFile::fake()->image('replacement.png'),
            ])->assertOk();
        } finally {
            Carbon::setTestNow();
        }

        $uploadedUser->refresh();
        $this->assertNotSame($firstImage, $uploadedUser->img);
        $disk->assertMissing($firstImage);
        $disk->assertExists($uploadedUser->img);

        $this->deleteJson('/api/user/image', ['img' => $uploadedUser->img])->assertOk();
        $disk->assertMissing($uploadedUser->img);

        $this->assertEqualsCanonicalizing(
            [AuditEvent::PROFILE_IMAGE_UPLOADED->value, AuditEvent::PROFILE_IMAGE_UPLOADED->value, AuditEvent::PROFILE_IMAGE_DELETED->value],
            AuditLog::query()
                ->get()
                ->map(fn (AuditLog $audit): string => $audit->event->value)
                ->all(),
        );
    }

    /**
     * Menolak foto untuk user lain dan kumpulan file tanpa mengubah profil atau audit.
     *
     * @return void Tidak mengembalikan nilai; assertion menjaga ownership dan kontrak satu file.
     *
     * @test
     */
    public function invalid_profile_image_uploads_do_not_change_user_or_audit(): void
    {
        $disk = Storage::fake('public');
        $otherUser = User::factory()->create();

        $this->postJson('/api/user/image', [
            'id' => $otherUser->id,
            'file' => UploadedFile::fake()->image('foreign.png'),
        ])->assertForbidden();

        $this->postJson('/api/user/image', [
            'id' => $this->user->id,
            'file' => [UploadedFile::fake()->image('multiple.png')],
        ])->assertUnprocessable();

        $this->assertNull($this->user->refresh()->img);
        $this->assertSame([], $disk->allFiles('user-imgs'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /**
     * Menolak SVG dan menyimpan JPEG sesuai isi file meskipun nama kiriman berakhiran HTML.
     *
     * @return void Tidak mengembalikan nilai; assertion menjaga format path publik dan audit.
     *
     * @test
     */
    public function profile_image_upload_uses_detected_extension_and_rejects_svg(): void
    {
        $disk = Storage::fake('public');
        $svg = UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $svgNamedHtml = new UploadedFile($svg->getPathname(), 'image.html', 'image/svg+xml', null, true);

        $this->postJson('/api/user/image', [
            'id' => $this->user->id,
            'file' => $svgNamedHtml,
        ])->assertUnprocessable()->assertJsonValidationErrors(['file'], 'message');

        $this->assertNull($this->user->refresh()->img);
        $this->assertSame([], $disk->allFiles('user-imgs'));
        $this->assertDatabaseCount('audit_logs', 0);

        $jpeg = UploadedFile::fake()->image('photo.jpg');
        $jpegNamedHtml = new UploadedFile($jpeg->getPathname(), 'photo.html', 'image/jpeg', null, true);
        $this->postJson('/api/user/image', [
            'id' => $this->user->id,
            'file' => $jpegNamedHtml,
        ])->assertOk();

        $imagePath = $this->user->refresh()->img;
        $this->assertStringEndsWith('.jpg', $imagePath);
        $disk->assertExists($imagePath);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /**
     * Memastikan storage gagal tidak mengganti referensi dan file foto profil lama.
     *
     * @return void Tidak mengembalikan nilai; assertion menjaga state profil tanpa audit baru.
     *
     * @test
     */
    public function failed_profile_image_storage_keeps_the_previous_image(): void
    {
        $disk = Storage::fake('public');
        $previousImage = 'user-imgs/previous.png';
        $disk->put($previousImage, 'previous image');
        $this->user->img = $previousImage;
        $this->user->save();

        $failedDisk = Mockery::mock(FilesystemAdapter::class);
        $failedDisk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->once()->with('public')->andReturn($failedDisk);

        $this->postJson('/api/user/image', [
            'id' => $this->user->id,
            'file' => UploadedFile::fake()->image('new.png'),
        ])->assertServerError();

        $this->assertSame($previousImage, $this->user->refresh()->img);
        $this->assertSame([$previousImage], $disk->allFiles('user-imgs'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /**
     * Memastikan kegagalan audit membatalkan penggantian foto dan menghapus file baru.
     *
     * @return void Tidak mengembalikan nilai; assertion menjaga referensi dan file lama.
     *
     * @test
     */
    public function failed_profile_image_audit_restores_the_previous_image(): void
    {
        $disk = Storage::fake('public');
        Carbon::setTestNow('2026-09-28 12:00:00');
        try {
            // Match the legacy timestamp path to expose same-second overwrite during rollback.
            $previousImage = 'user-imgs/'.$this->user->id.'-'.Carbon::now()->timestamp.'.png';
            $disk->put($previousImage, 'previous image');
            $this->user->img = $previousImage;
            $this->user->save();

            $this->partialMock(AuditLogService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('recordProfileImageChanged')
                    ->once()
                    ->andThrow(new RuntimeException('Audit persistence failed.'));
            });

            $this->postJson('/api/user/image', [
                'id' => $this->user->id,
                'file' => UploadedFile::fake()->image('new.png'),
            ])->assertServerError();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame($previousImage, $this->user->refresh()->img);
        $this->assertSame([$previousImage], $disk->allFiles('user-imgs'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /**
     * Membentuk payload Pengaturan Pengguna yang sesuai dengan state fixture.
     *
     * @param  array<string, mixed>  $overrides  Nilai yang menimpa payload dasar.
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
