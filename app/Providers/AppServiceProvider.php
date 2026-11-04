<?php

namespace App\Providers;

use App\Services\Hours\MonthlyReferenceProvider;
use App\Services\Hours\MonthlyReferenceResolver;
use App\Services\Hours\NullMonthlyReferenceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // PC1-34 ne crée aucun stockage persistant de référence individuelle.
        // Le fournisseur par défaut ne retourne aucun changement ; une
        // implémentation historisée pourra remplacer ce binding sans modifier
        // les calculateurs.
        $this->app->singleton(MonthlyReferenceProvider::class, NullMonthlyReferenceProvider::class);

        $this->app->bind(MonthlyReferenceResolver::class, function ($app): MonthlyReferenceResolver {
            $minutes = (int) $app['config']->get(
                'planning.hours.monthly_reference_minutes',
                MonthlyReferenceResolver::DEFAULT_REFERENCE_MINUTES,
            );

            return new MonthlyReferenceResolver(
                $app->make(MonthlyReferenceProvider::class),
                $minutes,
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(function (): Password {
            $password = Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols();

            return app()->isProduction()
                ? $password->uncompromised()
                : $password;
        });
    }
}
