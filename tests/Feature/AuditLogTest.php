<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Http\Middleware\AuthenticateApiRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Clerk\ClerkBackendClientService;
use App\Services\Clerk\ClerkUserSyncService;
use App\Services\UserAgentParserService;
use Carbon\CarbonImmutable;
use Clerk\Backend\Models\Components\EmailAddress;
use Clerk\Backend\Models\Components\EmailAddressObject;
use Clerk\Backend\Models\Components\User as ClerkUser;
use Clerk\Backend\Models\Components\UserObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Memverifikasi persistence, idempotency, ownership, dan API Audit Log V1.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private AuditLogService $auditLogService;

    /**
     * Memastikan heading event multi-kata memakai Title Case secara konsisten.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_event_titles_use_consistent_title_case(): void
    {
        $this->assertSame('Akun Berhasil Dibuat', AuditEvent::AUTH_REGISTERED->title());
        $this->assertSame('Login', AuditEvent::AUTH_LOGGED_IN->title());
        $this->assertSame('Logout', AuditEvent::AUTH_LOGGED_OUT->title());
        $this->assertSame('Produk Ditambahkan', AuditEvent::PRODUCT_CREATED->title());
        $this->assertSame('Produk Diperbarui', AuditEvent::PRODUCT_UPDATED->title());
        $this->assertSame('Produk Dihapus', AuditEvent::PRODUCT_DELETED->title());
    }

    /**
     * Menyiapkan service audit dan melewati middleware Clerk eksternal.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->auditLogService = $this->app->make(AuditLogService::class);
        $this->withoutMiddleware(AuthenticateApiRequest::class);
    }

    /**
     * Memastikan session register pertama tidak menghasilkan login redundant.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_registration_and_first_session_login_are_not_duplicated(): void
    {
        $user = $this->createUser();
        $request = $this->auditRequest('sess_register');

        $registration = $this->auditLogService->recordRegistration($user, $request);
        $login = $this->auditLogService->recordLogin($user, $request);

        $this->assertSame($registration->id, $login?->id);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'event' => AuditEvent::AUTH_REGISTERED->value,
            'clerk_session_id' => 'sess_register',
        ]);
    }

    /**
     * Memastikan sync Clerk mengirim status create yang dipakai flow register.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_clerk_sync_status_drives_registration_event_once(): void
    {
        $syncService = new ClerkUserSyncService(new ClerkBackendClientService);
        $clerkUser = $this->clerkUser('user_sync_audit', 'audit-sync@example.com');
        $request = $this->auditRequest('sess_sync_register');
        $recordAuthEvent = function (User $user, bool $wasCreated) use ($request): void {
            if ($wasCreated) {
                $this->auditLogService->recordRegistration($user, $request);

                return;
            }

            $this->auditLogService->recordLogin($user, $request);
        };

        $firstSync = $syncService->syncClerkUserWithStatus($clerkUser, $recordAuthEvent);
        $secondSync = $syncService->syncClerkUserWithStatus($clerkUser, $recordAuthEvent);

        $this->assertTrue($firstSync['was_created']);
        $this->assertFalse($secondSync['was_created']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'event' => AuditEvent::AUTH_REGISTERED->value,
            'clerk_session_id' => 'sess_sync_register',
        ]);
    }

    /**
     * Memastikan satu Clerk session hanya menghasilkan satu login.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_login_is_recorded_once_per_clerk_session(): void
    {
        $user = $this->createUser();

        $this->auditLogService->recordLogin($user, $this->auditRequest('sess_first'));
        $this->auditLogService->recordLogin($user, $this->auditRequest('sess_first'));
        $this->auditLogService->recordLogin($user, $this->auditRequest('sess_second'));

        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertSame(
            2,
            AuditLog::query()->where('event', AuditEvent::AUTH_LOGGED_IN->value)->count()
        );
    }

    /**
     * Memastikan logout menyimpan alasan dan metadata perangkat satu kali.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_logout_is_idempotent_and_records_user_initiated_reason(): void
    {
        $user = $this->createUser();
        $request = $this->auditRequest('sess_logout');

        $this->auditLogService->recordLogout($user, $request);
        $this->auditLogService->recordLogout($user, $request);

        $auditLog = AuditLog::query()->sole();

        $this->assertSame(AuditEvent::AUTH_LOGGED_OUT, $auditLog->event);
        $this->assertSame('user_initiated', $auditLog->context['reason']);
        $this->assertSame('Chrome', $auditLog->context['device']['browser']);
        $this->assertSame('macOS', $auditLog->context['device']['operating_system']);
        $this->assertSame('Desktop', $auditLog->context['device']['device_type']);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /**
     * Memastikan collection terisolasi, masked, dan memakai cursor.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_collection_is_owner_scoped_masked_and_cursor_paginated(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser();

        $this->auditLogService->recordLogin($user, $this->auditRequest('sess_owner_first', '103.10.20.30'));
        $this->auditLogService->recordLogout($user, $this->auditRequest('sess_owner_first', '103.10.20.30'));
        $this->auditLogService->recordLogin($otherUser, $this->auditRequest('sess_other', '192.168.1.10'));

        $firstPage = $this->actingAs($user)
            ->getJson('/api/audit-logs?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ip_address', '103.10.xxx.xxx')
            ->assertJsonMissingPath('data.0.actor_clerk_user_id')
            ->assertJsonMissingPath('data.0.user_agent')
            ->assertJsonMissingPath('data.0.clerk_session_id')
            ->assertJsonMissingPath('data.0.request_id')
            ->assertJsonMissingPath('data.0.idempotency_key')
            ->assertJsonPath('meta.has_more', true);

        $cursor = $firstPage->json('meta.next_cursor');

        $this->actingAs($user)
            ->getJson('/api/audit-logs?per_page=1&cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.has_more', false);
    }

    /**
     * Memastikan timeline campuran tetap lengkap dan deterministik ketika
     * batas halaman memotong event autentikasi dan produk.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_mixed_event_cursor_pagination_has_no_missing_or_duplicate_rows(): void
    {
        // --- step 1 - start - prepare mixed events including equal timestamps for the ID tie-breaker
        $user = $this->createUser();
        $events = AuditEvent::cases();
        $baseTime = CarbonImmutable::parse('2026-07-20 12:00:00');

        foreach (range(0, 29) as $index) {
            $event = $events[$index % count($events)];

            $this->createTimelineAudit(
                $user,
                $event,
                $baseTime->subSeconds(intdiv($index, 3)),
                $index
            );
        }

        $expectedIds = AuditLog::query()
            ->where('actor_user_id', $user->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->pluck('id')
            ->all();
        // --- step 1 - end - prepare mixed events including equal timestamps for the ID tie-breaker

        // --- step 2 - start - follow every cursor exactly as the frontend load-more flow does
        $collectedIds = [];
        $collectedEvents = [];
        $cursor = null;
        $pageCount = 0;

        do {
            $query = '/api/audit-logs?per_page=7';
            if ($cursor) {
                $query .= '&cursor='.urlencode($cursor);
            }

            $response = $this->actingAs($user)
                ->getJson($query)
                ->assertOk();
            $pageData = $response->json('data');

            $collectedIds = array_merge($collectedIds, array_column($pageData, 'id'));
            $collectedEvents = array_merge($collectedEvents, array_column($pageData, 'event'));
            $cursor = $response->json('meta.next_cursor');
            $hasMore = $response->json('meta.has_more');
            $pageCount++;

            $this->assertLessThanOrEqual(5, $pageCount, 'Pagination menghasilkan cursor yang tidak berakhir.');
            $this->assertSame($hasMore, $cursor !== null);
        } while ($hasMore);
        // --- step 2 - end - follow every cursor exactly as the frontend load-more flow does

        // --- step 3 - start - verify order, completeness, uniqueness, and event coverage
        $this->assertSame(5, $pageCount);
        $this->assertSame($expectedIds, $collectedIds);
        $this->assertCount(30, $collectedIds);
        $this->assertCount(30, array_unique($collectedIds));

        foreach ($events as $event) {
            $this->assertContains($event->value, $collectedEvents);
        }
        // --- step 3 - end - verify order, completeness, uniqueness, and event coverage
    }

    /**
     * Memastikan full IP hanya bisa dibaca oleh pemilik audit.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_detail_reveals_full_ip_only_to_the_owner(): void
    {
        $owner = $this->createUser();
        $otherUser = $this->createUser();
        $auditLog = $this->auditLogService->recordLogin(
            $owner,
            $this->auditRequest('sess_private_ip', '103.10.20.30')
        );

        $this->actingAs($owner)
            ->getJson("/api/audit-logs/{$auditLog->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Login')
            ->assertJsonPath('data.ip_address', '103.10.20.30')
            ->assertJsonMissingPath('data.actor_clerk_user_id')
            ->assertJsonMissingPath('data.user_agent')
            ->assertJsonMissingPath('data.clerk_session_id')
            ->assertJsonMissingPath('data.request_id')
            ->assertJsonMissingPath('data.idempotency_key');

        $this->actingAs($otherUser)
            ->getJson("/api/audit-logs/{$auditLog->id}")
            ->assertNotFound();
    }

    /**
     * Memastikan request id tersedia pada response dan row audit.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_request_id_is_returned_and_persisted(): void
    {
        $user = $this->createUser();
        $requestId = (string) Str::uuid();
        $auditRequest = $this->auditRequest('sess_request_id');
        $auditRequest->attributes->set('request_id', $requestId);

        $auditLog = $this->auditLogService->recordLogin($user, $auditRequest);

        $this->assertSame($requestId, $auditLog?->request_id);

        $response = $this->actingAs($user)
            ->withHeader('X-Request-ID', $requestId)
            ->getJson('/api/audit-logs');

        $response->assertOk();
        $this->assertSame($requestId, $response->headers->get('X-Request-ID'));
    }

    /**
     * Memastikan event, rentang tanggal, dan ukuran halaman tidak valid ditolak.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_event_date_and_page_size_filters_are_validated(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)
            ->getJson('/api/audit-logs?event=auth.unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event');

        $this->actingAs($user)
            ->getJson('/api/audit-logs?from=2026-07-14&to=2026-07-13')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->actingAs($user)
            ->getJson('/api/audit-logs?per_page=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');

        $this->actingAs($user)
            ->getJson('/api/audit-logs?cursor=not-a-valid-cursor')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');

        $this->actingAs($user)
            ->getJson('/api/audit-logs?cursor=W10')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');

        $this->actingAs($user)
            ->getJson('/api/audit-logs?cursor[]=unexpected')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');

        $invalidTimestampCursor = rtrim(strtr(base64_encode(json_encode([
            'occurred_at' => 'tomorrow',
            'id' => (string) Str::uuid(),
            '_pointsToNextItems' => true,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $this->actingAs($user)
            ->getJson('/api/audit-logs?cursor='.urlencode($invalidTimestampCursor))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');
    }

    /**
     * Memastikan parser tidak menebak perangkat asing sebagai Desktop dan
     * tetap membedakan Android tablet dari Android mobile.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_user_agent_device_type_is_only_returned_when_supported(): void
    {
        $parser = $this->app->make(UserAgentParserService::class);
        $androidTablet = $parser->parse(
            'Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 Chrome/126.0 Safari/537.36'
        );
        $androidMobile = $parser->parse(
            'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 Chrome/126.0 Mobile Safari/537.36'
        );
        $unknownClient = $parser->parse('CustomClient/1.0');

        $this->assertSame('Tablet', $androidTablet['device_type']);
        $this->assertSame('Mobile', $androidMobile['device_type']);
        $this->assertNull($unknownClient['device_type']);
    }

    /**
     * Memastikan filter tanggal memakai batas hari Asia/Jakarta secara inklusif.
     *
     * @return void  Tidak mengembalikan nilai; kegagalan skenario dinyatakan melalui assertion.
     */
    public function test_date_filter_uses_the_application_timezone_day_boundaries(): void
    {
        $user = $this->createUser();

        $this->travelTo(CarbonImmutable::parse('2026-07-13T23:59:59+07:00'));
        $includedAudit = $this->auditLogService->recordLogin(
            $user,
            $this->auditRequest('sess_included_date')
        );

        $this->travelTo(CarbonImmutable::parse('2026-07-14T00:00:00+07:00'));
        $this->auditLogService->recordLogin(
            $user,
            $this->auditRequest('sess_excluded_date')
        );
        $this->travelBack();

        $this->actingAs($user)
            ->getJson('/api/audit-logs?from=2026-07-13&to=2026-07-13')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $includedAudit?->id);
    }

    /**
     * Membuat user lokal dengan Clerk identity unik untuk setiap test.
     *
     * @return User  Model user lokal yang berhasil ditemukan, dibuat, atau disinkronkan.
     */
    private function createUser(): User
    {
        return User::factory()->create([
            'clerk_user_id' => 'user_'.Str::lower(Str::random(16)),
        ]);
    }

    /**
     * Membuat row timeline deterministik tanpa memanggil layanan eksternal.
     *
     * @param  User  $user  Model user lokal yang menjadi actor atau pemilik data.
     * @param  AuditEvent  $event  Jenis event domain yang dicatat.
     * @param  CarbonImmutable  $occurredAt  Waktu event yang digunakan untuk menyusun fixture timeline.
     * @param  int  $sequence  Nomor urut untuk menghasilkan fixture deterministik.
     *
     * @return AuditLog  Model audit log yang berhasil ditemukan atau dicatat.
     */
    private function createTimelineAudit(
        User $user,
        AuditEvent $event,
        CarbonImmutable $occurredAt,
        int $sequence
    ): AuditLog {
        $isProductEvent = $event->category() === 'product';

        return AuditLog::create([
            'actor_user_id' => $user->id,
            'actor_clerk_user_id' => $user->clerk_user_id,
            'event' => $event,
            'category' => $event->category(),
            'subject_type' => $isProductEvent ? 'product' : 'session',
            'subject_id' => $isProductEvent ? (string) Str::uuid() : "session-{$sequence}",
            'context' => $isProductEvent
                ? [
                    'subject_name' => "Produk {$sequence}",
                    'product_snapshot' => ['price' => 12500, 'stock' => 4, 'image_count' => 1],
                ]
                : [],
            'ip_address' => '127.0.0.1',
            'idempotency_key' => hash('sha256', "mixed-pagination|{$sequence}"),
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * Membuat request audit terverifikasi tanpa memanggil layanan Clerk.
     *
     * @param  string  $sessionId  ID session Clerk yang menjadi target operasi.
     * @param  string  $ipAddress  Alamat IP yang digunakan untuk request atau fixture audit.
     *
     * @return Request  Request sintetis yang telah diproses melalui middleware terkait.
     */
    private function auditRequest(string $sessionId, string $ipAddress = '127.0.0.1'): Request
    {
        $request = Request::create('/api/auth/me', 'GET', [], [], [], [
            'REMOTE_ADDR' => $ipAddress,
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/126.0 Safari/537.36',
        ]);
        $request->attributes->set('clerk_session_id', $sessionId);
        $request->attributes->set('request_id', (string) Str::uuid());

        return $request;
    }

    /**
     * Membuat model Clerk minimum untuk menguji local user synchronization.
     *
     * @param  string  $clerkUserId  ID user pada Clerk yang telah berasal dari token terverifikasi.
     * @param  string  $email  Alamat email unik untuk identity fixture.
     *
     * @return ClerkUser  Model identity user yang berhasil diperoleh dari Clerk.
     */
    private function clerkUser(string $clerkUserId, string $email): ClerkUser
    {
        $emailAddressId = 'idn_primary_email';
        $emailAddress = new EmailAddress(
            object: EmailAddressObject::EmailAddress,
            emailAddress: $email,
            reserved: false,
            linkedTo: [],
            createdAt: 1,
            updatedAt: 1,
            id: $emailAddressId,
        );

        return new ClerkUser(
            id: $clerkUserId,
            object: UserObject::User,
            hasImage: false,
            publicMetadata: [],
            emailAddresses: [$emailAddress],
            phoneNumbers: [],
            web3Wallets: [],
            passkeys: [],
            passwordEnabled: true,
            twoFactorEnabled: false,
            totpEnabled: false,
            backupCodeEnabled: false,
            externalAccounts: [],
            samlAccounts: [],
            enterpriseAccounts: [],
            banned: false,
            locked: false,
            updatedAt: 1,
            createdAt: 1,
            deleteSelfEnabled: true,
            createOrganizationEnabled: true,
            primaryEmailAddressId: $emailAddressId,
            firstName: 'Audit',
            lastName: 'User',
        );
    }
}
