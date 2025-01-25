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

namespace Laravel\Ripple\Events;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Ripple\Coroutine\ContextManager;
use Laravel\Ripple\Listeners\FlushAuthenticationState;
use Laravel\Ripple\Listeners\FlushQueuedCookies;
use Laravel\Ripple\Listeners\FlushSessionState;
use Ripple\Utils\Output;

class RequestReceived
{
    public const LISTENERS = [
        FlushQueuedCookies::class,
        FlushSessionState::class,
        FlushAuthenticationState::class,
    ];

    /**
     * @param \Illuminate\Foundation\Application $app
     * @param \Illuminate\Foundation\Application $sandbox
     * @param \Illuminate\Http\Request           $request
     */
    public function __construct(public Application $app, public Application $sandbox, public Request $request)
    {
        foreach (RequestReceived::LISTENERS as $listener) {
            try {
                $app->make($listener)->handle($this);
            } catch (BindingResolutionException $e) {
                Output::warning($e->getMessage());
            }
        }

        ContextManager::bind($sandbox);
    }
}
