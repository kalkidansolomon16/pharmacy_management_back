<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    private static bool $muted = false;

    public function __construct(private TenantContext $tenants) {}

    /** Run bulk work (imports, seeding) without writing audit entries. */
    public static function muted(callable $callback): mixed
    {
        $previous = self::$muted;
        self::$muted = true;

        try {
            return $callback();
        } finally {
            self::$muted = $previous;
        }
    }

    public function log(string $action, ?Model $entity = null, array $properties = [], ?string $description = null): ?ActivityLog
    {
        if (self::$muted) {
            return null;
        }

        $user = Auth::user();

        return ActivityLog::create([
            'tenant_id' => $entity?->getAttribute('tenant_id') ?? $this->tenants->id(),
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => $entity ? class_basename($entity) : null,
            'entity_id' => $entity?->getKey(),
            'description' => $description ?? $this->describe($action, $entity),
            'properties' => $properties ?: null,
            'ip_address' => request()?->ip(),
        ]);
    }

    private function describe(string $action, ?Model $entity): string
    {
        if (! $entity) {
            return ucfirst(str_replace('_', ' ', $action));
        }

        $label = method_exists($entity, 'activityLabel') ? $entity->activityLabel() : class_basename($entity).' #'.$entity->getKey();

        return ucfirst(str_replace('_', ' ', $action)).' '.strtolower(class_basename($entity)).': '.$label;
    }
}
