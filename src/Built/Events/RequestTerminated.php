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

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Ripple\Built\Coroutine\ContextManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class RequestTerminated
 */
class RequestTerminated
{
    /**
     * @param \Illuminate\Foundation\Application         $app
     * @param \Illuminate\Foundation\Application         $sandbox
     * @param \Illuminate\Http\Request                   $request
     * @param \Symfony\Component\HttpFoundation\Response $response
     */
    public function __construct(public Application $app, public Application $sandbox, public Request $request, public Response $response)
    {
        ContextManager::unbind();
    }
}
