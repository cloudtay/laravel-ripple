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

use Illuminate\Support\Env;

return [
    'HTTP_LISTEN'  => Env::get('RIP_HTTP_LISTEN', 'http://127.0.0.1:8000'),
    'HTTP_WORKERS' => Env::get('RIP_HTTP_WORKERS', 1),
    'WATCH'        => Env::get('RIP_WATCH', 1),
    'WATCH_PATHS' => [
        \base_path('/app'),
        \base_path('/bootstrap'),
        \base_path('/config'),
        \base_path('/routes'),
        \base_path('/resources'),
        \base_path('/.env')
    ],
    'PROCESS_NAMES' => [
        'MONITOR'  => Env::get('RIP_PROCESS_NAME_MONITOR', 'laravel-ware'),
        'VIRTUAL'  => Env::get('RIP_PROCESS_NAME_VIRTUAL', 'laravel-virtual'),
        'HTTP_WORKER' => Env::get('RIP_PROCESS_NAME_HTTP_WORKER', 'laravel-worker.http'),
    ]
];
