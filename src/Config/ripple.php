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
    /**
     * HTTP服务 - 监听地址
     * HTTP service - listening address
     */
    'HTTP_LISTEN'  => Env::get('RIP_HTTP_LISTEN', 'http://127.0.0.1:8000'),

    /**
     * HTTP服务 - Worker数
     * HTTP Service - Number of Workers
     */
    'HTTP_WORKERS' => Env::get('RIP_HTTP_WORKERS', 1),

    /**
     * 自动重载模式
     * Automatic reload mode
     */
    'WATCH'        => Env::get('RIP_WATCH', 1),

    /**
     * 自动重载监听文件
     * Automatically reload listening files
     */
    'WATCH_PATHS' => [
        \base_path('/app'),
        \base_path('/bootstrap'),
        \base_path('/config'),
        \base_path('/routes'),
        \base_path('/resources'),
        \base_path('/.env')
    ],

    /**
     * 数据库连接池 - 开关
     * Database Connection Pool - Switch
     */
    'PDO_POOL_ENABLE' => Env::get('RIP_POOL_ENABLE', 1),

    /**
     * 数据库连接池 - 选项
     * Database Connection Pool - Options
     *
     * <相关文档>
     * @docs https://github.com/cloudtay/ripple-database
     */
    'PDO_POOL_OPTION' => Env::get('RIP_POOL_OPTION', [
        'pool_min' => 10,
        'pool_max' => 20,
    ]),
];
