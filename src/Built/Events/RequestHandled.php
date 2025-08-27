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
use Symfony\Component\HttpFoundation\Response;

/**
 *
 */
class RequestHandled
{
    /**
     * @param Application $app
     * @param Application $sandbox
     * @param Request $request
     * @param Response $response
     */
    public function __construct(public Application $app, public Application $sandbox, public Request $request, public Response $response)
    {
    }
}
