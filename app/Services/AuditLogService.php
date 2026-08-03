<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Models\Alamat;
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
    /**
     * Menyiapkan dependency yang diperlukan oleh class.
     *
     * @param  UserAgentParserService  $userAgentParserService  Service user agent parser yang digunakan oleh class ini.
     *
     * @return void  Tidak mengembalikan nilai; dependency disimpan pada instance.
     */
    public function __construct(
        protected UserAgentParserService $userAgentParserService
    ) {}

    /**
     * Mencatat register dan sekaligus menandai Clerk session pertama
     * supaya bootstrap berikutnya tidak membuat login yang redundant.
     *
     * Event registration memakai session ID sebagai sumber idempotency dan disimpan bersama metadata
     * request yang telah dibatasi. Session pertama ditandai dengan key yang sama agar bootstrap
     * berikutnya tidak mencatat login tambahan.
     *
     * @param  User  $user  Actor lokal yang baru berhasil dibuat.
     * @param  Request  $request  Request Clerk bootstrap yang sudah diverifikasi.
     *
     * @return AuditLog  Model audit log yang berhasil ditemukan atau dicatat.
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
     * Session Clerk menjadi sumber idempotency sehingga refresh atau bootstrap berulang tidak
     * menghasilkan login ganda. Metadata perangkat dan request ID tetap dicatat untuk session baru
     * yang benar-benar berbeda.
     *
     * @param  User  $user  Actor lokal yang sedang login.
     * @param  Request  $request  Request bootstrap yang memuat Clerk session id.
     *
     * @return AuditLog|null  Model audit log yang berhasil ditemukan atau dicatat.
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
     * Logout hanya dicatat sekali untuk session aktif dan menyimpan alasan user-initiated. Identifier
     * request serta metadata perangkat dipertahankan agar event dapat ditelusuri tanpa menyimpan
     * payload autentikasi.
     *
     * @param  User  $user  Actor lokal yang meminta logout.
     * @param  Request  $request  Request logout sebelum session Clerk ditutup.
     *
     * @return AuditLog|null  Model audit log yang berhasil ditemukan atau dicatat.
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
     *
     * Snapshot harga, stok, jumlah gambar, dan nama produk diambil setelah seluruh persistence
     * berhasil. Audit menggunakan owner sebagai actor dan tidak menyimpan path atau identifier gambar.
     *
     * @param  User  $user  Model user lokal yang menjadi actor atau pemilik data.
     * @param  Product  $product  Model produk yang menjadi target atau sumber data.
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return AuditLog  Model audit log yang berhasil ditemukan atau dicatat.
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
     * Request ID menjadi bagian idempotency key agar retry tidak membuat audit ganda. Snapshot produk,
     * perubahan field, serta metadata gambar disimpan sebagai context allow-listed tanpa path storage.
     *
     * @param  User  $user  Model user lokal yang menjadi actor atau pemilik data.
     * @param  Product  $product  Model produk yang menjadi target atau sumber data.
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     * @param  array<int, array{field: string, label: string, before: mixed, after: mixed}>  $changes  Daftar perubahan field produk yang akan dicatat.
     * @param  array<string, int|bool>  $imageChanges  Ringkasan perubahan gambar yang akan dicatat.
     *
     * @return AuditLog  Model audit log yang berhasil ditemukan atau dicatat.
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
     * Snapshot terakhir diterima sebelum soft delete dan disimpan bersama identitas subject. Detail
     * audit tetap dapat dibaca setelah relasi produk tidak lagi muncul pada query produk aktif.
     *
     * @param  User  $user  Model user lokal yang menjadi actor atau pemilik data.
     * @param  Product  $product  Model produk yang menjadi target atau sumber data.
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     * @param  array{name: string, price: int, stock: int, image_count: int}  $snapshot  Snapshot produk terakhir sebelum produk dinonaktifkan.
     *
     * @return AuditLog  Model audit log yang berhasil ditemukan atau dicatat.
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
     * Mencatat alamat pengiriman buyer yang baru dibuat beserta snapshot awalnya.
     *
     * Snapshot diambil setelah alamat tersimpan sehingga status alamat utama sudah final. Audit
     * memakai pemilik alamat sebagai actor dan tidak menyimpan koordinat pinpoint.
     *
     * @param  User  $user  Model user lokal yang menjadi actor atau pemilik data.
     * @param  Alamat  $alamat  Model alamat yang menjadi target atau sumber data.
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return AuditLog  Model audit log yang berhasil ditemukan atau dicatat.
     */
    public function recordAddressCreated(User $user, Alamat $alamat, Request $request): AuditLog
    {
        return $this->record(
            user: $user,
            request: $request,
            event: AuditEvent::ADDRESS_CREATED,
            idempotencySource: "address-created|{$alamat->id}",
            subjectType: 'address',
            subjectId: (string) $alamat->id,
            extraContext: [
                'subject_name' => (string) $alamat->place,
                'address_snapshot' => $this->addressSnapshot($alamat),
            ],
        );
    }

    /**
     * Mencatat field alamat yang berubah pada satu request update.
     *
     * Request ID menjadi bagian idempotency key agar retry request yang sama tidak membuat audit
     * ganda, sementara dua update berbeda tetap menjadi dua event terpisah. Update yang tidak
     * mengubah nilai apa pun tetap dicatat dengan daftar perubahan kosong.
     *
     * @param  User  $user  Model user lokal yang menjadi actor atau pemilik data.
     * @param  Alamat  $alamat  Model alamat yang menjadi target atau sumber data.
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     * @param  array<int, array{field: string, label: string, before: mixed, after: mixed}>  $changes  Daftar perubahan field alamat yang akan dicatat.
     *
     * @return AuditLog  Model audit log yang berhasil ditemukan atau dicatat.
     */
    public function recordAddressUpdated(
        User $user,
        Alamat $alamat,
        Request $request,
        array $changes
    ): AuditLog {
        $requestId = $this->resolveRequestId($request);

        return $this->record(
            user: $user,
            request: $request,
            event: AuditEvent::ADDRESS_UPDATED,
            idempotencySource: "address-updated|{$alamat->id}|{$requestId}",
            subjectType: 'address',
            subjectId: (string) $alamat->id,
            extraContext: [
                'subject_name' => (string) $alamat->place,
                'address_snapshot' => $this->addressSnapshot($alamat),
                'changes' => array_values($changes),
            ],
            requestId: $requestId,
        );
    }

    /**
     * Menyimpan snapshot terakhir sebelum row alamat dihapus agar detail audit
     * tetap dapat dibaca setelah alamatnya tidak ada lagi.
     *
     * Snapshot diterima dari pemanggil karena diambil sebelum penghapusan. Alamat pengganti dicatat
     * ketika sistem otomatis mengaktifkan alamat lain sebagai alamat utama.
     *
     * @param  User  $user  Model user lokal yang menjadi actor atau pemilik data.
     * @param  Alamat  $alamat  Model alamat yang baru saja dihapus beserta identitasnya.
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     * @param  array<string, mixed>  $snapshot  Snapshot alamat terakhir sebelum row dihapus.
     * @param  array{id: string, place: string, recipient_name: string}|null  $replacement  Alamat utama pengganti ketika sistem memilihnya otomatis.
     *
     * @return AuditLog  Model audit log yang berhasil ditemukan atau dicatat.
     */
    public function recordAddressDeleted(
        User $user,
        Alamat $alamat,
        Request $request,
        array $snapshot,
        ?array $replacement = null
    ): AuditLog {
        return $this->record(
            user: $user,
            request: $request,
            event: AuditEvent::ADDRESS_DELETED,
            idempotencySource: "address-deleted|{$alamat->id}",
            subjectType: 'address',
            subjectId: (string) $alamat->id,
            extraContext: [
                'subject_name' => $snapshot['place'],
                'address_snapshot' => $snapshot,
                'replacement_address' => $replacement,
            ],
        );
    }

    /**
     * Mencatat perpindahan alamat pengiriman utama milik buyer.
     *
     * Alamat utama sebelumnya ikut disimpan agar pemilik akun dapat menelusuri perpindahan tujuan
     * pengiriman. Request ID menjadi bagian idempotency key sehingga memilih ulang alamat yang sama
     * pada request berbeda tetap menjadi event terpisah.
     *
     * @param  User  $user  Model user lokal yang menjadi actor atau pemilik data.
     * @param  Alamat  $alamat  Model alamat yang menjadi target atau sumber data.
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     * @param  array{id: string, place: string, recipient_name: string}|null  $previous  Alamat utama sebelum perpindahan, atau null ketika belum ada.
     *
     * @return AuditLog  Model audit log yang berhasil ditemukan atau dicatat.
     */
    public function recordAddressSelected(
        User $user,
        Alamat $alamat,
        Request $request,
        ?array $previous = null
    ): AuditLog {
        $requestId = $this->resolveRequestId($request);

        return $this->record(
            user: $user,
            request: $request,
            event: AuditEvent::ADDRESS_SELECTED,
            idempotencySource: "address-selected|{$alamat->id}|{$requestId}",
            subjectType: 'address',
            subjectId: (string) $alamat->id,
            extraContext: [
                'subject_name' => (string) $alamat->place,
                'address_snapshot' => $this->addressSnapshot($alamat),
                'previous_address' => $previous,
            ],
            requestId: $requestId,
        );
    }

    /**
     * Membentuk snapshot alamat allow-listed tanpa koordinat pinpoint.
     *
     * Koordinat, place ID Geoapify, dan sumber lokasi sengaja tidak disimpan karena tidak menambah
     * informasi bagi pemilik akun sekaligus merupakan data paling sensitif pada row alamat.
     *
     * @param  Alamat  $alamat  Model alamat yang menjadi target atau sumber data.
     *
     * @return array{place: string, recipient_name: string, phone: string, formatted_address: string, address_detail: string, enable: bool}  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function addressSnapshot(Alamat $alamat): array
    {
        return [
            'place' => (string) $alamat->place,
            'recipient_name' => (string) $alamat->name,
            'phone' => (string) $alamat->phone,
            'formatted_address' => (string) $alamat->formatted_address,
            'address_detail' => (string) $alamat->address_detail,
            'enable' => (bool) $alamat->enable,
        ];
    }

    /**
     * Membentuk snapshot allow-listed tanpa path atau identifier gambar.
     *
     * @param  Product  $product  Model produk yang menjadi target atau sumber data.
     *
     * @return array{price: int, stock: int, image_count: int}  Data terstruktur yang dihasilkan oleh proses ini.
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
     * Payload audit dinormalisasi dan dibatasi pada context yang diizinkan sebelum insert dilakukan.
     * Idempotency key mencegah event duplikat pada request paralel, sedangkan metadata request hanya
     * disimpan dalam bentuk yang dibutuhkan untuk penelusuran.
     *
     * @param  User  $user  Actor lokal pemilik audit.
     * @param  Request  $request  Request sumber metadata audit.
     * @param  AuditEvent  $event  Event domain yang berhasil.
     * @param  string  $idempotencySource  Sumber stabil untuk hash unique.
     * @param  string  $subjectType  Tipe object yang terkena aktivitas.
     * @param  string  $subjectId  Identifier object yang terkena aktivitas.
     * @param  array  $extraContext  Metadata tambahan yang sudah di-allow-list.
     * @param  string|null  $requestId  Correlation ID request untuk idempotensi dan penelusuran.
     *
     * @return AuditLog  Model audit log yang berhasil ditemukan atau dicatat.
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
     *
     * @param  User  $user  Model user lokal yang menjadi actor atau pemilik data.
     * @param  string  $clerkSessionId  ID session Clerk yang terkait dengan request.
     *
     * @return string  Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
     */
    private function authSessionKey(User $user, string $clerkSessionId): string
    {
        return "auth-session|{$user->id}|{$clerkSessionId}";
    }

    /**
     * Mengambil Clerk session id yang telah diverifikasi middleware auth.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return string  Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
     */
    private function resolveClerkSessionId(Request $request): string
    {
        return trim((string) $request->attributes->get('clerk_session_id', ''));
    }

    /**
     * Mengambil request id valid atau membuat fallback UUID untuk pemanggilan internal.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return string  Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
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
     *
     * @return string  Nilai teks yang telah dinormalisasi untuk kebutuhan pemanggil.
     */
    private function formatDatabaseTimestamp(CarbonInterface $timestamp): string
    {
        $driver = AuditLog::query()->getConnection()->getDriverName();

        return $driver === 'pgsql'
            ? $timestamp->toIso8601String()
            : $timestamp->format('Y-m-d H:i:s');
    }
}
