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

use Throwable;

use function realpath;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

class Util
{
    /**
     * @param $path1
     * @param $path2
     *
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
        } catch (Throwable $e) {
            return '';
        }

        return '';
    }
}
