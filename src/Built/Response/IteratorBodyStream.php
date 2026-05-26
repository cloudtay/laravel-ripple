<?php declare(strict_types=1);

namespace Laravel\Ripple\Built\Response;

use Iterator;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

use function strlen;
use function substr;

use const SEEK_SET;

class IteratorBodyStream implements StreamInterface
{
    private string $buffer = '';

    private bool $started = false;

    public function __construct(private readonly Iterator $iterator)
    {
    }

    public function __toString(): string
    {
        $contents = '';

        while (!$this->eof()) {
            $contents .= $this->read(8192);
        }

        return $contents;
    }

    public function close(): void
    {
        $this->buffer = '';
    }

    public function detach()
    {
        $this->close();
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        throw new RuntimeException('Iterator stream is not seekable.');
    }

    public function eof(): bool
    {
        $this->fillBuffer(1);

        return $this->buffer === '' && $this->started && !$this->iterator->valid();
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Iterator stream is not seekable.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('Iterator stream is not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Iterator stream is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new RuntimeException('Length must be greater than or equal to zero.');
        }

        $this->fillBuffer($length);
        $chunk = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, strlen($chunk));

        return $chunk;
    }

    public function getContents(): string
    {
        $contents = $this->buffer;
        $this->buffer = '';

        while (!$this->eof()) {
            $contents .= $this->read(8192);
        }

        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        $metadata = ['seekable' => false];

        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }

    private function fillBuffer(int $length): void
    {
        if (!$this->started) {
            $this->iterator->rewind();
            $this->started = true;
        }

        while (strlen($this->buffer) < $length && $this->iterator->valid()) {
            try {
                $this->buffer .= (string)$this->iterator->current();
                $this->iterator->next();
            } catch (Throwable $e) {
                throw new RuntimeException($e->getMessage(), (int)$e->getCode(), $e);
            }
        }
    }
}
