<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Satu pintu pencatatan audit agar metadata, sanitasi, dan idempotency konsisten.
 */
class AuditLogService
{
    public function __construct(
        protected UserAgentParserService $userAgentParserService
    ) {}

    /**
     * Mencatat register dan sekaligus menandai Clerk session pertama
     * supaya bootstrap berikutnya tidak membuat login yang redundant.
     *
     * @param  User  $user  Actor lokal yang baru berhasil dibuat.
     * @param  Request  $request  Request Clerk bootstrap yang sudah diverifikasi.
     */
    public function recordRegistration(User $user, Request $request): AuditLog
    {
        // --- step 1 - start - gunakan session key bersama register/login untuk menekan login pertama yang redundant
        $clerkSessionId = $this->resolveClerkSessionId($request);
        $idempotencySource = $clerkSessionId !== ''
            ? $this->authSessionKey($user, $clerkSessionId)
            : "auth-register|{$user->id}";
        // --- step 1 - end - gunakan session key bersama register/login untuk menekan login pertama yang redundant

        // --- step 2 - start - simpan event register dengan user sebagai subject utama
        $auditLog = $this->record(
            user: $user,
            request: $request,
            event: AuditEvent::AUTH_REGISTERED,
            idempotencySource: $idempotencySource,
            subjectType: 'user',
            subjectId: (string) $user->id,
        );
        // --- step 2 - end - simpan event register dengan user sebagai subject utama

        return $auditLog;
    }

    /**
     * Mencatat login satu kali untuk setiap Clerk session. Registration
     * memakai session key yang sama sehingga session pertama tidak dobel.
     *
     * @param  User  $user  Actor lokal yang sedang login.
     * @param  Request  $request  Request bootstrap yang memuat Clerk session id.
     */
    public function recordLogin(User $user, Request $request): ?AuditLog
    {
        // --- step 1 - start - login tanpa session id tidak dicatat karena tidak dapat diduplikasi secara aman
        $clerkSessionId = $this->resolveClerkSessionId($request);
        // --- step 1 - end - login tanpa session id tidak dicatat karena tidak dapat diduplikasi secara aman

        if ($clerkSessionId === '') {
            return null;
        }

        // --- step 2 - start - gunakan session sebagai subject dan kunci idempotency
        $auditLog = $this->record(
            user: $user,
            request: $request,
            event: AuditEvent::AUTH_LOGGED_IN,
            idempotencySource: $this->authSessionKey($user, $clerkSessionId),
            subjectType: 'session',
            subjectId: $clerkSessionId,
        );
        // --- step 2 - end - gunakan session sebagai subject dan kunci idempotency

        return $auditLog;
    }

    /**
     * Mencatat logout user-initiated secara idempotent untuk session aktif.
     *
     * @param  User  $user  Actor lokal yang meminta logout.
     * @param  Request  $request  Request logout sebelum session Clerk ditutup.
     */
    public function recordLogout(User $user, Request $request): ?AuditLog
    {
        // --- step 1 - start - logout tanpa session id dilewati karena ownership session tidak lengkap
        $clerkSessionId = $this->resolveClerkSessionId($request);
        // --- step 1 - end - logout tanpa session id dilewati karena ownership session tidak lengkap

        if ($clerkSessionId === '') {
            return null;
        }

        // --- step 2 - start - simpan alasan eksplisit agar event tidak disamakan dengan session expiry
        $auditLog = $this->record(
            user: $user,
            request: $request,
            event: AuditEvent::AUTH_LOGGED_OUT,
            idempotencySource: "auth-logout|{$user->id}|{$clerkSessionId}",
            subjectType: 'session',
            subjectId: $clerkSessionId,
            extraContext: ['reason' => 'user_initiated'],
        );
        // --- step 2 - end - simpan alasan eksplisit agar event tidak disamakan dengan session expiry

        return $auditLog;
    }

    /**
     * Mencatat kondisi awal produk setelah produk dan seluruh gambar berhasil dibuat.
     */
    public function recordProductCreated(User $user, Product $product, Request $request): AuditLog
    {
        return $this->record(
            user: $user,
            request: $request,
            event: AuditEvent::PRODUCT_CREATED,
            idempotencySource: "product-created|{$product->id}",
            subjectType: 'product',
            subjectId: (string) $product->id,
            extraContext: [
                'subject_name' => (string) $product->name,
                'product_snapshot' => $this->productSnapshot($product),
            ],
        );
    }

    /**
     * Mencatat field dan metadata gambar yang berubah pada satu request update.
     * Request id menjadi bagian kunci agar retry request yang sama tetap idempotent.
     *
     * @param  array<int, array{field: string, label: string, before: mixed, after: mixed}>  $changes
     * @param  array<string, int|bool>  $imageChanges
     */
    public function recordProductUpdated(
        User $user,
        Product $product,
        Request $request,
        array $changes,
        array $imageChanges
    ): AuditLog {
        $requestId = $this->resolveRequestId($request);

        return $this->record(
            user: $user,
            request: $request,
            event: AuditEvent::PRODUCT_UPDATED,
            idempotencySource: "product-updated|{$product->id}|{$requestId}",
            subjectType: 'product',
            subjectId: (string) $product->id,
            extraContext: [
                'subject_name' => (string) $product->name,
                'product_snapshot' => $this->productSnapshot($product),
                'changes' => array_values($changes),
                'image_changes' => $imageChanges,
            ],
            requestId: $requestId,
        );
    }

    /**
     * Menyimpan snapshot terakhir sebelum row produk dihapus agar detail audit
     * tetap dapat dibaca tanpa bergantung pada product storage.
     *
     * @param  array{name: string, price: int, stock: int, image_count: int}  $snapshot
     */
    public function recordProductDeleted(
        User $user,
        Product $product,
        Request $request,
        array $snapshot
    ): AuditLog {
        return $this->record(
            user: $user,
            request: $request,
            event: AuditEvent::PRODUCT_DELETED,
            idempotencySource: "product-deleted|{$product->id}",
            subjectType: 'product',
            subjectId: (string) $product->id,
            extraContext: [
                'subject_name' => $snapshot['name'],
                'product_snapshot' => [
                    'price' => $snapshot['price'],
                    'stock' => $snapshot['stock'],
                    'image_count' => $snapshot['image_count'],
                ],
            ],
        );
    }

    /**
     * Membentuk snapshot allow-listed tanpa path atau identifier gambar.
     *
     * @return array{price: int, stock: int, image_count: int}
     */
    public function productSnapshot(Product $product): array
    {
        return [
            'price' => (int) $product->price,
            'stock' => (int) $product->stock,
            'image_count' => $product->relationLoaded('images')
                ? $product->images->count()
                : $product->images()->count(),
        ];
    }

    /**
     * Insert-or-ignore dipakai bersama unique idempotency_key agar request
     * paralel tetap tidak membuat row audit duplikat.
     *
     * @param  User  $user  Actor lokal pemilik audit.
     * @param  Request  $request  Request sumber metadata audit.
     * @param  AuditEvent  $event  Event domain yang berhasil.
     * @param  string  $idempotencySource  Sumber stabil untuk hash unique.
     * @param  string  $subjectType  Tipe object yang terkena aktivitas.
     * @param  string  $subjectId  Identifier object yang terkena aktivitas.
     * @param  array  $extraContext  Metadata tambahan yang sudah di-allow-list.
     */
    private function record(
        User $user,
        Request $request,
        AuditEvent $event,
        string $idempotencySource,
        string $subjectType,
        string $subjectId,
        array $extraContext = [],
        ?string $requestId = null
    ): AuditLog {
        // --- step 1 - start - susun metadata aman dan identifier untuk insert idempotent
        $idempotencyKey = hash('sha256', $idempotencySource);
        $now = $this->formatDatabaseTimestamp(now());
        $userAgent = (string) $request->userAgent();
        $context = [
            'device' => $this->userAgentParserService->parse($userAgent),
            ...$extraContext,
        ];

        if ($event->category() === 'authentication') {
            $context = [
                'provider' => 'clerk',
                'auth_method' => null,
                ...$context,
            ];
        }

        $context = array_filter($context, static fn ($value) => $value !== null);
        // --- step 1 - end - susun metadata aman dan identifier untuk insert idempotent

        // --- step 2 - start - insert atomik dan tangani dua request paralel untuk event yang sama
        AuditLog::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'actor_user_id' => $user->id,
            'actor_clerk_user_id' => $user->clerk_user_id,
            'event' => $event->value,
            'category' => $event->category(),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'user_agent' => $userAgent !== '' ? $userAgent : null,
            'clerk_session_id' => $this->resolveClerkSessionId($request) ?: null,
            'request_id' => $requestId ?? $this->resolveRequestId($request),
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);
        // --- step 2 - end - insert atomik dan tangani dua request paralel untuk event yang sama

        // --- step 3 - start - ambil row baru atau row existing yang memenangkan race
        $auditLog = AuditLog::query()
            ->where('idempotency_key', $idempotencyKey)
            ->firstOrFail();
        // --- step 3 - end - ambil row baru atau row existing yang memenangkan race

        return $auditLog;
    }

    /**
     * Membentuk kunci bersama untuk register pertama dan login per session.
     */
    private function authSessionKey(User $user, string $clerkSessionId): string
    {
        return "auth-session|{$user->id}|{$clerkSessionId}";
    }

    /**
     * Mengambil Clerk session id yang telah diverifikasi middleware auth.
     */
    private function resolveClerkSessionId(Request $request): string
    {
        return trim((string) $request->attributes->get('clerk_session_id', ''));
    }

    /**
     * Mengambil request id valid atau membuat fallback UUID untuk pemanggilan internal.
     */
    private function resolveRequestId(Request $request): string
    {
        $requestId = trim((string) $request->attributes->get('request_id', ''));

        return Str::isUuid($requestId) ? $requestId : (string) Str::uuid();
    }

    /**
     * Mempertahankan offset untuk PostgreSQL timestamptz dan memakai format
     * yang dapat dibandingkan secara stabil oleh SQLite/MySQL.
     *
     * @param  CarbonInterface  $timestamp  Waktu aktivitas pada timezone aplikasi.
     */
    private function formatDatabaseTimestamp(CarbonInterface $timestamp): string
    {
        $driver = AuditLog::query()->getConnection()->getDriverName();

        return $driver === 'pgsql'
            ? $timestamp->toIso8601String()
            : $timestamp->format('Y-m-d H:i:s');
    }
}
