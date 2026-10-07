<?php

namespace App\Models\Concerns;

use App\Services\ActivityLogger;

/**
 * Records created / updated / deleted events in the audit log.
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(fn ($model) => app(ActivityLogger::class)->log('created', $model, [
            'attributes' => $model->auditable($model->getAttributes()),
        ]));

        static::updated(function ($model) {
            $changed = $model->auditable($model->getChanges());
            if ($changed) {
                app(ActivityLogger::class)->log('updated', $model, [
                    'old' => array_intersect_key($model->getOriginal(), $changed),
                    'new' => $changed,
                ]);
            }
        });

        static::deleted(fn ($model) => app(ActivityLogger::class)->log('deleted', $model));
    }

    protected function auditable(array $attributes): array
    {
        $ignored = array_merge($this->getHidden(), ['updated_at', 'created_at', 'remember_token', 'password']);

        return array_diff_key($attributes, array_flip($ignored));
    }

    public function activityLabel(): string
    {
        return class_basename($this).' #'.$this->getKey();
    }
}
