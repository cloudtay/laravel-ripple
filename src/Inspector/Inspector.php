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

use Ripple\Serial\Zx7e;
use RuntimeException;

use function fclose;
use function file_exists;
use function fopen;
use function fwrite;
use function getmypid;
use function json_decode;
use function json_encode;
use function posix_mkfifo;
use function sprintf;
use function storage_path;
use function stream_get_contents;
use function stream_set_blocking;
use function stream_select;
use function uniqid;
use function unlink;

use const JSON_THROW_ON_ERROR;

class Inspector
{
    /**
     *
     */
    public function __construct(public readonly Client $client)
    {
    }

    /**
     * @return bool
     */
    public function serverIsRunning(): bool
    {
        return $this->client->serverIsRunning();
    }

    /**
     * @return void
     */
    public function reloadServer(): void
    {
        if (!$this->serverIsRunning()) {
            return;
        }

        $this->sendCommandAndWait('reload');
    }

    /**
     * @return bool
     */
    public function stopServer(): bool
    {
        if (!$this->serverIsRunning()) {
            return true;
        }

        $this->sendCommandAndWait('stop');
        return true;
    }

    /**
     * @return void
     */
    public function restartServer(): void
    {
        if (!$this->serverIsRunning()) {
            return;
        }

        $this->sendCommandAndWait('restart');
    }

    /**
     * @param string $command
     * @return array{ok:bool,message:string}
     */
    public function sendCommandAndWait(string $command): array
    {
        $ackPath = sprintf(
            '%s/ripple-command-%d-%s.fifo',
            storage_path(),
            getmypid(),
            uniqid('', true)
        );

        if (!posix_mkfifo($ackPath, 0600)) {
            throw new RuntimeException("Unable to create command acknowledgement FIFO: {$ackPath}");
        }

        $ack = fopen($ackPath, 'r+');
        if ($ack === false) {
            unlink($ackPath);
            throw new RuntimeException("Unable to open command acknowledgement FIFO: {$ackPath}");
        }
        stream_set_blocking($ack, false);

        $channel = fopen($this->client->channelPath, 'w');
        fwrite($channel, Zx7e::encode(json_encode([
            'command' => $command,
            'ack' => $ackPath,
        ], JSON_THROW_ON_ERROR)));
        fclose($channel);

        $read = [$ack];
        $write = null;
        $except = null;

        $ready = stream_select($read, $write, $except, Client::COMMAND_TIMEOUT_SECONDS + 1);
        $payload = $ready ? stream_get_contents($ack) : false;

        fclose($ack);
        if (file_exists($ackPath)) {
            unlink($ackPath);
        }

        if (!$ready || $payload === false || $payload === '') {
            throw new RuntimeException("Timed out waiting for ripple:server {$command} acknowledgement.");
        }

        $result = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if (!($result['ok'] ?? false)) {
            throw new RuntimeException((string) ($result['message'] ?? "ripple:server {$command} failed."));
        }

        return $result;
    }
}
