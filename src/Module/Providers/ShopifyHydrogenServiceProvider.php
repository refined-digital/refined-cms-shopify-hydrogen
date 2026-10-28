<?php

namespace RefinedDigital\ShopifyHydrogen\Module\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use RefinedDigital\CMS\Modules\Media\Models\Media;
use RefinedDigital\ShopifyHydrogen\Commands\Install;
use RefinedDigital\ShopifyHydrogen\Commands\SyncShopifyHydrogenMedia;

class ShopifyHydrogenServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../../config/shopify-hydrogen.php' => config_path('shopify-hydrogen.php'),
        ], 'shopify-hydrogen');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Install::class,
                SyncShopifyHydrogenMedia::class,
            ]);
        }

        // uploads stay on the local disk for the admin, the sync pushes them to Shopify Files
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('refinedCMS:sync-shopify-hydrogen-media')->everyMinute()->withoutOverlapping();
        });

        // a replaced file needs uploading again
        Media::updating(function (Media $media) {
            if ($media->isDirty('file')) {
                $media->external_id = null;
                $media->external_url = null;
            }
        });
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/shopify-hydrogen.php', 'shopify-hydrogen');
    }
}
