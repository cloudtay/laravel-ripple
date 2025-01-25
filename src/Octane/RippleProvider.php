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

namespace Laravel\Ripple\Octane;

use Illuminate\Support\ServiceProvider;
use Laravel\Ripple\Octane\Commands\RippleReloadCommand;
use Laravel\Ripple\Octane\Commands\RippleStartCommand;
use Laravel\Ripple\Octane\Commands\RippleStatusCommand;
use Laravel\Ripple\Octane\Commands\RippleStopCommand;

class RippleProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        $this->commands([
            RippleStartCommand::class,
            RippleStatusCommand::class,
            RippleReloadCommand::class,
            RippleStopCommand::class,
        ]);
    }
}
