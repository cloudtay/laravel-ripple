<?php declare(strict_types=1);

use Laravel\Ripple\Inspector\ServerLifecycle;
use PHPUnit\Framework\TestCase;

final class ServerLifecycleTest extends TestCase
{
    public function test_restart_runs_lifecycle_in_deterministic_order(): void
    {
        $events = [];

        $lifecycle = new ServerLifecycle(
            static function (string $message) use (&$events): void {
                $events[] = $message;
            },
            static function () use (&$events): void {
                $events[] = 'stop-old';
            },
            static function () use (&$events): void {
                $events[] = 'wait-release';
            },
            static function () use (&$events): void {
                $events[] = 'start-new';
            },
            static function () use (&$events): void {
                $events[] = 'wait-ready';
            },
        );

        $lifecycle->restart('restart');

        self::assertSame([
            'restart: stopping_old',
            'stop-old',
            'restart: old_exited',
            'wait-release',
            'restart: starting_new',
            'start-new',
            'restart: ready_check',
            'wait-ready',
            'restart: ready',
        ], $events);
    }

    public function test_restart_stops_before_starting_new_process(): void
    {
        $events = [];

        $lifecycle = new ServerLifecycle(
            static function (string $message) use (&$events): void {
                $events[] = $message;
            },
            static function (): void {
                throw new RuntimeException('old process did not exit');
            },
            static function () use (&$events): void {
                $events[] = 'wait-release';
            },
            static function () use (&$events): void {
                $events[] = 'start-new';
            },
            static function () use (&$events): void {
                $events[] = 'wait-ready';
            },
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('old process did not exit');

        try {
            $lifecycle->restart('reload');
        } finally {
            self::assertSame([
                'reload: stopping_old',
            ], $events);
        }
    }
}
