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

namespace Laravel\Ripple\Built\Events;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Ripple\Built\Coroutine\ContextManager;
use Laravel\Ripple\Built\Listeners\FlushAuthenticationState;
use Laravel\Ripple\Built\Listeners\FlushQueuedCookies;
use Laravel\Ripple\Built\Listeners\FlushSessionState;
use Ripple\Runtime\Support\Stdin;

class RequestReceived
{
    public const LISTENERS = [
        FlushQueuedCookies::class,
        FlushSessionState::class,
        FlushAuthenticationState::class,
    ];

    /**
     * @param Application $app
     * @param Application $sandbox
     * @param Request $request
     */
    public function __construct(public Application $app, public Application $sandbox, public Request $request)
    {
        foreach (RequestReceived::LISTENERS as $listener) {
            try {
                $app->make($listener)->handle($this);
            } catch (BindingResolutionException $e) {
                Stdin::println($e->getMessage());
            }
        }

        ContextManager::bind($sandbox);
    }
}
