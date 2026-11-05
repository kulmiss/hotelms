<?php

namespace App\Models\Concerns;

use App\Services\ActivityLogger;

/**
 * Records created / updated / deleted in the activity log, with the changed
 * fields (old → new). Passwords and tokens are never stored.
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(function ($model) {
            app(ActivityLogger::class)->log('created', class_basename($model).' created: '.$model->activityName(), $model, [
                'new' => $model->activityAttributes($model->getAttributes()),
            ]);
        });

        static::updated(function ($model) {
            $changes = $model->activityAttributes($model->getChanges());
            if ($changes === []) {
                return;
            }
            $old = array_intersect_key($model->getOriginal(), $changes);
            app(ActivityLogger::class)->log('updated', class_basename($model).' updated: '.$model->activityName(), $model, [
                'old' => $model->activityAttributes($old),
                'new' => $changes,
            ]);
        });

        static::deleted(function ($model) {
            app(ActivityLogger::class)->log('deleted', class_basename($model).' deleted: '.$model->activityName(), $model);
        });
    }

    public function activityName(): string
    {
        return (string) ($this->getAttribute('name') ?? $this->getAttribute('email') ?? '#'.$this->getKey());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function activityAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip([
            'password', 'remember_token', 'attendance_pin', 'updated_at', 'created_at', 'last_login_at',
        ]));
    }
}
