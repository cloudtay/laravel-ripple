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

namespace Laravel\Ripple;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

use function array_is_list;
use function file_exists;
use function is_array;
use function is_string;
use function realpath;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;
use const UPLOAD_ERR_NO_FILE;
use const UPLOAD_ERR_OK;

class Util
{
    /**
     * @param $path1
     * @param $path2
     * @return string
     */
    public static function getRelativePath($path1, $path2): string
    {
        try {
            $path1 = rtrim(realpath($path1), DIRECTORY_SEPARATOR);
            $path2 = rtrim(realpath($path2), DIRECTORY_SEPARATOR);

            if (!$path1 || !$path2) {
                return '';
            }

            if (str_starts_with($path1, $path2)) {
                return substr($path1, strlen($path2) + 1);
            }
        } catch (Throwable) {
            return '';
        }

        return '';
    }

    public static function normalizeRippleFiles(array $files): array
    {
        $parsed = [];
        foreach ($files as $name => $value) {
            $parsed[$name] = self::normalizeRippleFileValue($value);
        }

        return $parsed;
    }

    private static function normalizeRippleFileValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (
            isset($value['path'])
            && is_string($value['path'])
            && (
                ($value['isFile'] ?? false) === true
                || isset($value['fileName'])
                || isset($value['contentType'])
            )
        ) {
            return new UploadedFile(
                $value['path'],
                $value['fileName'] ?? '',
                $value['contentType'] ?? 'application/octet-stream',
                file_exists($value['path']) ? UPLOAD_ERR_OK : UPLOAD_ERR_NO_FILE,
                true
            );
        }

        if (array_is_list($value)) {
            foreach ($value as $index => $item) {
                $value[$index] = self::normalizeRippleFileValue($item);
            }

            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::normalizeRippleFileValue($item);
        }

        return $value;
    }
}
