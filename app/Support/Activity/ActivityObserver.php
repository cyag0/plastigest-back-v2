<?php

namespace App\Support\Activity;

use Illuminate\Database\Eloquent\Model;

/**
 * Observa los modelos de ActivitySubjects y los manda a la bitácora.
 */
class ActivityObserver
{
    public function created(Model $model): void
    {
        ActivityLogger::created($model);
    }

    public function updated(Model $model): void
    {
        ActivityLogger::updated($model);
    }

    public function deleted(Model $model): void
    {
        ActivityLogger::deleted($model);
    }
}
