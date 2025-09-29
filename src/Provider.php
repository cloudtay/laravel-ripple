<?php declare(strict_types=1);
/**
 * Copyright © 2024 cclilshy
 * Email: jingnigg@gmail.com
 *
 * This software is licensed under the MIT License.
 * For full license details, please visit: https://opensource.org/licenses/MIT
 *
 * By using this software, you agree to the terms of the license.
 * Contributions, suggestions, and feedback are always welcome!
 */

namespace Laravel\Ripple;

use Composer\InstalledVersions;
use Illuminate\Support\ServiceProvider;
use Laravel\Ripple\Built\Command;
use Laravel\Ripple\Octane\RippleProvider;
use Laravel\Ripple\Database\Provider as RippleDatabaseProvider;

use function config_path;

class Provider extends ServiceProvider
{
    /**
     * Register any application services.
     * @return void
     */
    public function register(): void
    {
        $this->commands([Command::class]);
        if (InstalledVersions::isInstalled('laravel/octane')) {
            $this->app->register(RippleProvider::class);
        }

        $this->app->register(RippleDatabaseProvider::class);
    }

    /**
     * @return void
     */
    public function boot(): void
    {
        $this->publishes(
            paths: [
                __DIR__ . '/Config/ripple.php' => config_path('ripple.php'),
            ],
            groups: 'ripple-config'
        );
    }
}
