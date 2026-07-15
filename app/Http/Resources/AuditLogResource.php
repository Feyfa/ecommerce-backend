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
        // --- step 2 - end - full IP hanya boleh keluar pada route detail yang sudah owner-scoped

        return $data;
    }

    /**
     * Menyamarkan bagian host IPv4/IPv6 untuk response collection.
     *
     * @param  string|null  $ipAddress  IP penuh yang hanya disimpan backend.
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
