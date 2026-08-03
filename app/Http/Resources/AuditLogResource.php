<?php

namespace App\Http\Resources;

use App\Enums\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource user-facing yang mencegah metadata internal audit ikut terekspos.
 */
class AuditLogResource extends JsonResource
{
    /**
     * Membatasi payload audit ke data yang aman untuk pemilik akun.
     * Full IP hanya diberikan oleh endpoint detail yang owner-scoped.
     *
     * Resource hanya mengekspos context yang telah diizinkan dan menyesuaikan detail sensitif dengan
     * mode collection atau detail. Alamat IP penuh tidak dikirim pada daftar, sedangkan metadata
     * produk dan perangkat dinormalisasi menjadi kontrak API yang stabil.
     *
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return array  Data terstruktur yang dihasilkan oleh proses ini.
     */
    public function toArray(Request $request): array
    {
        // --- step 1 - start - normalisasi enum dan context sebelum membentuk contract response
        $event = $this->event instanceof AuditEvent
            ? $this->event
            : AuditEvent::from((string) $this->event);
        $context = is_array($this->context) ? $this->context : [];
        // --- step 1 - end - normalisasi enum dan context sebelum membentuk contract response

        // --- step 2 - start - full IP hanya boleh keluar pada route detail yang sudah owner-scoped
        $data = [
            'id' => $this->id,
            'event' => $event->value,
            'event_label' => $event->label(),
            'category' => $this->category,
            'title' => $event->title(),
            'description' => $event->description(),
            'auth_method' => $context['auth_method'] ?? null,
            'device' => $context['device'] ?? [
                'browser' => null,
                'operating_system' => null,
                'device_type' => null,
            ],
            'ip_address' => $request->routeIs('audit-logs.show')
                ? $this->ip_address
                : $this->maskIpAddress($this->ip_address),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];

        // Payload spesifik domain dipilih melalui category agar penambahan domain audit
        // berikutnya tidak memperpanjang rantai kondisi di function ini.
        $data += match ($event->category()) {
            'product' => $this->productPayload($context),
            'address' => $this->addressPayload($context, $request),
            default => [],
        };
        // --- step 2 - end - full IP hanya boleh keluar pada route detail yang sudah owner-scoped

        return $data;
    }

    /**
     * Membentuk payload tambahan untuk event produk.
     *
     * @param  array<string, mixed>  $context  Context audit yang telah dinormalisasi menjadi array.
     *
     * @return array<string, mixed>  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function productPayload(array $context): array
    {
        return [
            'subject' => [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
                'name' => $context['subject_name'] ?? null,
            ],
            'product_snapshot' => $context['product_snapshot'] ?? null,
            'changes' => $context['changes'] ?? [],
            'image_changes' => $context['image_changes'] ?? null,
        ];
    }

    /**
     * Membentuk payload tambahan untuk event alamat buyer.
     *
     * Nomor telepon dan nama penerima disamarkan pada response collection, sedangkan detail alamat
     * sepenuhnya dihilangkan di sana. Route detail yang sudah owner-scoped menerima nilai penuh
     * mengikuti perlakuan yang sama dengan alamat IP.
     *
     * @param  array<string, mixed>  $context  Context audit yang telah dinormalisasi menjadi array.
     * @param  Request  $request  Request terautentikasi beserta payload dan metadata operasi.
     *
     * @return array<string, mixed>  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function addressPayload(array $context, Request $request): array
    {
        // --- step 1 - start - tentukan mode detail sebelum menyamarkan data alamat
        $isDetailRoute = $request->routeIs('audit-logs.show');
        $snapshot = $context['address_snapshot'] ?? null;
        // --- step 1 - end - tentukan mode detail sebelum menyamarkan data alamat

        // --- step 2 - start - susun payload alamat sesuai mode response
        return [
            'subject' => [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
                'name' => $context['subject_name'] ?? null,
            ],
            'address_snapshot' => is_array($snapshot)
                ? $this->presentAddressSnapshot($snapshot, $isDetailRoute)
                : null,
            'changes' => array_map(
                fn (array $change): array => $this->presentAddressChange($change, $isDetailRoute),
                $context['changes'] ?? []
            ),
            'previous_address' => $this->presentAddressReference($context['previous_address'] ?? null, $isDetailRoute),
            'replacement_address' => $this->presentAddressReference($context['replacement_address'] ?? null, $isDetailRoute),
        ];
        // --- step 2 - end - susun payload alamat sesuai mode response
    }

    /**
     * Menyesuaikan snapshot alamat dengan mode collection atau detail.
     *
     * @param  array<string, mixed>  $snapshot  Snapshot alamat yang tersimpan pada context audit.
     * @param  bool  $isDetailRoute  True ketika response berasal dari route detail owner-scoped.
     *
     * @return array<string, mixed>  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function presentAddressSnapshot(array $snapshot, bool $isDetailRoute): array
    {
        $presented = [
            'place' => $snapshot['place'] ?? null,
            'recipient_name' => $isDetailRoute
                ? ($snapshot['recipient_name'] ?? null)
                : $this->maskRecipientName($snapshot['recipient_name'] ?? null),
            'phone' => $isDetailRoute
                ? ($snapshot['phone'] ?? null)
                : $this->maskPhone($snapshot['phone'] ?? null),
            'formatted_address' => $snapshot['formatted_address'] ?? null,
            'enable' => $snapshot['enable'] ?? null,
        ];

        if ($isDetailRoute) {
            $presented['address_detail'] = $snapshot['address_detail'] ?? null;
        }

        return $presented;
    }

    /**
     * Menyamarkan nilai before/after pada perubahan field yang memuat data pribadi.
     *
     * @param  array{field: string, label: string, before: mixed, after: mixed}  $change  Satu baris perubahan alamat dari context audit.
     * @param  bool  $isDetailRoute  True ketika response berasal dari route detail owner-scoped.
     *
     * @return array{field: string, label: string, before: mixed, after: mixed}  Data terstruktur yang dihasilkan oleh proses ini.
     */
    private function presentAddressChange(array $change, bool $isDetailRoute): array
    {
        if ($isDetailRoute) {
            return $change;
        }

        $field = $change['field'] ?? '';
        $masker = match ($field) {
            'phone' => fn (mixed $value): ?string => $this->maskPhone(is_string($value) ? $value : null),
            'recipient_name' => fn (mixed $value): ?string => $this->maskRecipientName(is_string($value) ? $value : null),
            'address_detail' => static fn (): ?string => null,
            default => null,
        };

        if ($masker === null) {
            return $change;
        }

        return [
            ...$change,
            'before' => $masker($change['before'] ?? null),
            'after' => $masker($change['after'] ?? null),
        ];
    }

    /**
     * Menyesuaikan referensi alamat pendamping dengan mode response.
     *
     * @param  mixed  $reference  Referensi alamat utama sebelumnya atau penggantinya.
     * @param  bool  $isDetailRoute  True ketika response berasal dari route detail owner-scoped.
     *
     * @return array<string, mixed>|null  Data terstruktur yang dihasilkan oleh proses ini, atau null ketika referensinya tidak tersedia.
     */
    private function presentAddressReference(mixed $reference, bool $isDetailRoute): ?array
    {
        if (! is_array($reference)) {
            return null;
        }

        return [
            'id' => $reference['id'] ?? null,
            'place' => $reference['place'] ?? null,
            'recipient_name' => $isDetailRoute
                ? ($reference['recipient_name'] ?? null)
                : $this->maskRecipientName($reference['recipient_name'] ?? null),
        ];
    }

    /**
     * Menyamarkan bagian tengah nomor telepon untuk response collection.
     *
     * Empat digit awal dan tiga digit akhir dipertahankan supaya pemilik akun tetap mengenali
     * nomornya. Nomor yang terlalu pendek disamarkan seluruhnya agar tidak membocorkan pola.
     *
     * @param  string|null  $phone  Nomor telepon penuh yang tersimpan pada context audit.
     *
     * @return string|null  Nilai teks yang telah dinormalisasi, atau null ketika sumber datanya tidak tersedia.
     */
    private function maskPhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        if (mb_strlen($phone) <= 7) {
            return str_repeat('*', mb_strlen($phone));
        }

        return mb_substr($phone, 0, 4).'****'.mb_substr($phone, -3);
    }

    /**
     * Menyingkat nama penerima menjadi nama depan dan inisial untuk response collection.
     *
     * @param  string|null  $name  Nama penerima penuh yang tersimpan pada context audit.
     *
     * @return string|null  Nilai teks yang telah dinormalisasi, atau null ketika sumber datanya tidak tersedia.
     */
    private function maskRecipientName(?string $name): ?string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $segments = preg_split('/\s+/', $name) ?: [];

        if (count($segments) < 2) {
            return $name;
        }

        return $segments[0].' '.mb_strtoupper(mb_substr((string) end($segments), 0, 1)).'.';
    }

    /**
     * Menyamarkan bagian host IPv4/IPv6 untuk response collection.
     *
     * IPv4 disamarkan pada oktet terakhir dan IPv6 dipadatkan sebelum bagian host diganti. Nilai yang
     * tidak dapat dikenali tetap diperlakukan secara defensif agar response collection tidak
     * membocorkan alamat lengkap.
     *
     * @param  string|null  $ipAddress  IP penuh yang hanya disimpan backend.
     *
     * @return string|null  Nilai teks yang telah dinormalisasi, atau null ketika sumber datanya tidak tersedia.
     */
    private function maskIpAddress(?string $ipAddress): ?string
    {
        if (! $ipAddress) {
            return null;
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $segments = explode('.', $ipAddress);

            return "{$segments[0]}.{$segments[1]}.xxx.xxx";
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $expanded = inet_pton($ipAddress);

            if ($expanded !== false) {
                $segments = str_split(bin2hex($expanded), 4);

                return implode(':', array_slice($segments, 0, 4)).':xxxx:xxxx:xxxx:xxxx';
            }
        }

        return 'Alamat IP disembunyikan';
    }
}
