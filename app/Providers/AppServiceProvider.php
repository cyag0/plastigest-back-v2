<?php

namespace App\Providers;

use App\Models\Admin\Location;
use App\Policies\LocationPolicy;
use App\Support\Activity\ActivityObserver;
use App\Support\Activity\ActivitySubjects;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Location::class => LocationPolicy::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register policies
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Bitácora ("Mi actividad"): cada alta, edición y baja de estos modelos.
        foreach (array_keys(ActivitySubjects::all()) as $model) {
            $model::observe(ActivityObserver::class);
        }
    }
}
