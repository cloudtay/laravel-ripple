<?php declare(strict_types=1);

namespace Laravel\Ripple;

use Closure;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_keys;
use function file_exists;
use function is_dir;
use function is_file;
use function ltrim;
use function strtolower;
use function time;
use function call_user_func;
use function filemtime;
use function pathinfo;

use const PATHINFO_EXTENSION;

class Monitor
{
    public ?Closure $onCreate = null;
    public ?Closure $onModify = null;
    public ?Closure $onDelete = null;

    /**
     * @var array<string, array{mtime:int,ext:string,isFile:bool}>
     */
    private array $watched = [];

    /**
     * @var array<string,int> 文件快照
     */
    private array $lastState = [];

    /**
     * @param string $path
     * @param string|null $ext
     */
    public function add(string $path, ?string $ext = null): void
    {
        if (is_file($path)) {
            $this->watched[$path] = [
                'mtime' => time(),
                'ext' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                'isFile' => true,
            ];
            $this->lastState[$path] = file_exists($path) ? filemtime($path) : 0;
            return;
        }

        if (!is_dir($path)) {
            throw new InvalidArgumentException("path {$path} is not a valid file or directory");
        }

        $this->watched[$path] = [
            'mtime' => time(),
            'ext' => $ext ? strtolower(ltrim($ext, '.')) : null,
            'isFile' => false,
        ];

        $this->seedDirectory($path, $this->watched[$path]['ext']);
    }

    /**
     * 初始化目录文件快照
     */
    private function seedDirectory(string $dir, ?string $ext): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            if (!$fileInfo->isFile()) {
                continue;
            }
            if ($ext !== null && strtolower($fileInfo->getExtension()) !== $ext) {
                continue;
            }
            $this->lastState[$fileInfo->getPathname()] = $fileInfo->getMTime();
        }
    }

    /**
     * tick
     */
    public function tick(): void
    {
        foreach ($this->watched as $path => $info) {
            if ($info['isFile']) {
                $mtime = file_exists($path) ? filemtime($path) : null;

                if (!isset($this->lastState[$path]) && $mtime !== null) {
                    $this->onCreate && call_user_func($this->onCreate, $path);
                } elseif ($mtime !== null && $this->lastState[$path] !== $mtime) {
                    $this->onModify && call_user_func($this->onModify, $path);
                } elseif ($mtime === null && isset($this->lastState[$path])) {
                    $this->onDelete && call_user_func($this->onDelete, $path);
                }

                if ($mtime !== null) {
                    $this->lastState[$path] = $mtime;
                } else {
                    unset($this->lastState[$path]);
                }
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileInfo) {
                /** @var SplFileInfo $fileInfo */
                if (!$fileInfo->isFile()) {
                    continue;
                }

                $fPath = $fileInfo->getPathname();
                $mtime = $fileInfo->getMTime();

                if ($info['ext'] !== null && strtolower($fileInfo->getExtension()) !== $info['ext']) {
                    continue;
                }

                if (!isset($this->lastState[$fPath])) {
                    $this->onCreate && call_user_func($this->onCreate, $fPath);
                } elseif ($this->lastState[$fPath] !== $mtime) {
                    $this->onModify && call_user_func($this->onModify, $fPath);
                }

                $this->lastState[$fPath] = $mtime;
            }
        }

        // check delete
        foreach (array_keys($this->lastState) as $fPath) {
            if (!file_exists($fPath)) {
                $this->onDelete && call_user_func($this->onDelete, $fPath);
                unset($this->lastState[$fPath]);
            }
        }
    }
}
