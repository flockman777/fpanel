<?php
declare(strict_types=1);
namespace Fpanel\Support;

use Fpanel\Database\Connection;

final class Audit
{
    public static function log(
        string $action,
        ?int $actorId = null,
        string $actorName = 'system',
        ?string $targetType = null,
        string|int|null $targetId = null,
        string $ip = '',
        string $result = 'ok',
        array $meta = []
    ): void {
        Connection::insert('audit_log', [
            'actor_id'    => $actorId,
            'actor_name'  => $actorName,
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId === null ? null : (string)$targetId,
            'ip'          => $ip !== '' ? $ip : null,
            'result'      => $result,
            'meta'        => json_encode($meta, JSON_UNESCAPED_UNICODE) ?: '{}',
        ]);
    }
}
