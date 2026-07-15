<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Menyediakan read-only audit API yang selalu dibatasi ke user aktif.
 */
class AuditLogController extends Controller
{
    /**
     * Menampilkan timeline audit milik user aktif memakai cursor pagination.
     */
    public function index(Request $request): JsonResponse
    {
        // --- step 1 - start - validasi filter dan batasi ukuran halaman sebelum query dijalankan
        $validated = $request->validate([
            'event' => ['nullable', 'string', Rule::in(array_column(AuditEvent::cases(), 'value'))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'cursor' => [
                'bail',
                'nullable',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (! $this->isValidCursor((string) $value)) {
                        $fail('The cursor field is invalid.');
                    }
                },
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        // --- step 1 - end - validasi filter dan batasi ukuran halaman sebelum query dijalankan

        // --- step 2 - start - susun query owner-scoped dengan order cursor yang deterministik
        $user = $request->user();
        $perPage = (int) ($validated['per_page'] ?? 20);
        $from = isset($validated['from']) ? $this->startOfApplicationDay($validated['from']) : null;
        $to = isset($validated['to']) ? $this->endOfApplicationDay($validated['to']) : null;
        $query = AuditLog::query()
            ->where('actor_user_id', $user->id)
            ->when($validated['event'] ?? null, fn ($query, string $event) => $query->where('event', $event))
            ->when($from, fn ($query, string $boundary) => $query->where('occurred_at', '>=', $boundary))
            ->when($to, fn ($query, string $boundary) => $query->where('occurred_at', '<=', $boundary))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
        // --- step 2 - end - susun query owner-scoped dengan order cursor yang deterministik

        // --- step 3 - start - jalankan cursor pagination dan bentuk metadata load-more frontend
        $paginator = $query->cursorPaginate($perPage);
        $responseData = [
            'status' => 200,
            'data' => AuditLogResource::collection($paginator->getCollection())->resolve($request),
            'meta' => [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
                'per_page' => $perPage,
            ],
        ];
        // --- step 3 - end - jalankan cursor pagination dan bentuk metadata load-more frontend

        return response()->json($responseData, 200);
    }

    /**
     * Menampilkan satu audit milik user aktif. Query ownership dilakukan
     * sebelum resource mengizinkan full IP masuk ke response detail.
     */
    public function show(Request $request, string $auditLog): JsonResponse
    {
        // --- step 1 - start - resolve row hanya melalui actor user aktif agar id user lain menghasilkan 404
        $audit = AuditLog::query()
            ->where('actor_user_id', $request->user()->id)
            ->whereKey($auditLog)
            ->firstOrFail();
        // --- step 1 - end - resolve row hanya melalui actor user aktif agar id user lain menghasilkan 404

        // --- step 2 - start - resource detail boleh mengembalikan full IP setelah ownership terbukti
        $responseData = [
            'status' => 200,
            'data' => AuditLogResource::make($audit)->resolve($request),
        ];
        // --- step 2 - end - resource detail boleh mengembalikan full IP setelah ownership terbukti

        return response()->json($responseData, 200);
    }

    /**
     * Mengubah tanggal filter menjadi awal hari pada timezone aplikasi.
     * Offset dipertahankan agar PostgreSQL tidak bergantung pada timezone koneksi.
     *
     * @param  string  $date  Tanggal valid berformat YYYY-MM-DD.
     */
    private function startOfApplicationDay(string $date): string
    {
        $boundary = CarbonImmutable::parse($date, config('app.timezone'))->startOfDay();

        return $this->formatDatabaseBoundary($boundary);
    }

    /**
     * Mengubah tanggal filter menjadi akhir hari pada timezone aplikasi.
     * Offset dipertahankan agar seluruh aktivitas hari terakhir tetap masuk.
     *
     * @param  string  $date  Tanggal valid berformat YYYY-MM-DD.
     */
    private function endOfApplicationDay(string $date): string
    {
        $boundary = CarbonImmutable::parse($date, config('app.timezone'))->endOfDay();

        return $this->formatDatabaseBoundary($boundary);
    }

    /**
     * PostgreSQL menerima offset timezone secara eksplisit, sedangkan SQLite
     * dan MySQL membutuhkan format tanggal biasa untuk perbandingan stabil.
     *
     * @param  CarbonImmutable  $boundary  Batas waktu pada timezone aplikasi.
     */
    private function formatDatabaseBoundary(CarbonImmutable $boundary): string
    {
        $driver = AuditLog::query()->getConnection()->getDriverName();

        return $driver === 'pgsql'
            ? $boundary->toIso8601String()
            : $boundary->format('Y-m-d H:i:s');
    }

    /**
     * Memastikan cursor hanya memuat parameter order timeline yang dibuat
     * Laravel sehingga payload rusak tidak jatuh menjadi first page atau 500.
     *
     * @param  string  $encodedCursor  Cursor URL-safe dari response sebelumnya.
     */
    private function isValidCursor(string $encodedCursor): bool
    {
        // --- step 1 - start - decode base64 URL-safe secara ketat sebelum membaca JSON
        $normalizedCursor = strtr($encodedCursor, '-_', '+/');
        $paddingLength = (4 - strlen($normalizedCursor) % 4) % 4;
        $decodedCursor = base64_decode($normalizedCursor.str_repeat('=', $paddingLength), true);

        if ($decodedCursor === false) {
            return false;
        }

        $parameters = json_decode($decodedCursor, true);

        if (! is_array($parameters)) {
            return false;
        }
        // --- step 1 - end - decode base64 URL-safe secara ketat sebelum membaca JSON

        // --- step 2 - start - validasi shape dan nilai tie-breaker cursor timeline
        $expectedKeys = ['occurred_at', 'id', '_pointsToNextItems'];

        if (array_diff(array_keys($parameters), $expectedKeys) !== []
            || array_diff($expectedKeys, array_keys($parameters)) !== []) {
            return false;
        }

        if (! is_string($parameters['occurred_at'])
            || ! is_string($parameters['id'])
            || ! Str::isUuid($parameters['id'])
            || ! is_bool($parameters['_pointsToNextItems'])) {
            return false;
        }

        try {
            $occurredAt = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $parameters['occurred_at']);

            if ($occurredAt === false || $occurredAt->format('Y-m-d H:i:s') !== $parameters['occurred_at']) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }
        // --- step 2 - end - validasi shape dan nilai tie-breaker cursor timeline

        return true;
    }
}
