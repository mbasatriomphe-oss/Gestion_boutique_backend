<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
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
        // TiDB has no SQL triggers. Stock entries and sales already run in the
        // controllers; retain the remaining currency, purchase and return effects.
        \App\Models\devise::created(function ($currency): void {
            if (\App\Support\DatabaseTriggers::usesApplicationFallback()) {
                \App\Models\caisse::firstOrCreate(['id_devise' => $currency->id], ['solde' => 0]);
            }
        });
        \App\Models\ligne_approvisionnements::created(function ($line): void {
            if (\App\Support\DatabaseTriggers::usesApplicationFallback()) {
                \App\Services\TiDbTriggerFallback::purchaseCreated($line);
            }
        });
        \App\Models\ligne_retours::created(function ($line): void {
            if (\App\Support\DatabaseTriggers::usesApplicationFallback()) {
                \App\Services\TiDbTriggerFallback::returnCreated($line);
            }
        });

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });
    }
}
