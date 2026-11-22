<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Auditoría append-only (los triggers de MySQL impiden UPDATE/DELETE sobre audit_log). */
final class AuditService
{
    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     * @param array<string,mixed>|null $related
     */
    public function log(
        string $entity, ?string $entityId, string $action,
        ?string $spaceId = null, ?string $userId = null, ?string $deviceId = null,
        ?array $before = null, ?array $after = null, ?string $reason = null,
        ?array $related = null, string $source = 'mobile',
    ): void {
        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid(),
            'space_id' => $spaceId,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'entity' => $entity,
            'entity_id' => $entityId,
            'action' => $action,
            'before_state' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_state' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'related' => $related === null ? null : json_encode($related, JSON_THROW_ON_ERROR),
            'source' => $source,
            'created_at' => now()->utc()->format('Y-m-d H:i:s.v'),
        ]);
    }
}
