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

namespace Laravel\Ripple\Built\Coroutine;

use Fiber;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;

use function is_null;
use function spl_object_hash;

/**
 *
 */
class ContextManager
{
    /*** @var array */
    private static array $applications = [];

    /**
     * @param Container $application
     *
     * @return void
     */
    public static function bind(Container $application): void
    {
        if (!$fiber = Fiber::getCurrent()) {
            return;
        }

        ContextManager::$applications[spl_object_hash($fiber)] = $application;
    }

    /**
     * @return void
     */
    public static function unbind(): void
    {
        if (!$fiber = Fiber::getCurrent()) {
            return;
        }

        unset(ContextManager::$applications[spl_object_hash($fiber)]);
    }

    /**
     * @param string|null $abstract
     * @param array       $parameters
     *
     * @return mixed
     * @throws BindingResolutionException
     */
    public static function app(?string $abstract = null, array $parameters = []): mixed
    {
        $container = ContextManager::current();
        if (is_null($abstract)) {
            return $container;
        }

        return $container->make($abstract, $parameters);
    }

    /**
     * @return Container
     */
    public static function current(): Container
    {
        if (!$fiber = Fiber::getCurrent()) {
            return Container::getInstance();
        }

        return ContextManager::$applications[spl_object_hash($fiber)] ?? Container::getInstance();
    }
}
