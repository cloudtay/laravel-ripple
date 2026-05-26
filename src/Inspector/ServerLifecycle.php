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

namespace Laravel\Ripple\Inspector;

final class ServerLifecycle
{
    /**
     * @param callable(string):void $log
     * @param callable():void       $stopOld
     * @param callable():void       $waitForOldResourceRelease
     * @param callable():void       $startNew
     * @param callable():void       $waitUntilReady
     */
    public function __construct(
        private readonly mixed $log,
        private readonly mixed $stopOld,
        private readonly mixed $waitForOldResourceRelease,
        private readonly mixed $startNew,
        private readonly mixed $waitUntilReady,
    ) {
    }

    /**
     * @param string $reason
     * @return void
     */
    public function restart(string $reason): void
    {
        $this->transition($reason, 'stopping_old');
        ($this->stopOld)();

        $this->transition($reason, 'old_exited');
        ($this->waitForOldResourceRelease)();

        $this->transition($reason, 'starting_new');
        ($this->startNew)();

        $this->transition($reason, 'ready_check');
        ($this->waitUntilReady)();

        $this->transition($reason, 'ready');
    }

    /**
     * @param string $reason
     * @param string $state
     * @return void
     */
    private function transition(string $reason, string $state): void
    {
        ($this->log)("{$reason}: {$state}");
    }
}
