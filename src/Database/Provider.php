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

namespace Laravel\Ripple\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Ripple\Database\PDO;
use Ripple\Database\PDOPool;

class Provider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        Connection::resolverFor('mysql-ripple', function ($connection, $database, $prefix, $config) {
            // user config
            $host = $config['host'] ?? '127.0.0.1';
            $port = $config['port'] ?? 3306;
            $dbname = $config['database'] ?? '';
            $charset = $config['charset'] ?? 'utf8mb4';
            $username = $config['username'] ?? 'root';
            $password = $config['password'] ?? '';
            $options = $config['options'] ?? [];
            $options = [Config::get('ripple.PDO_POOL_OPTION'), ... $options];

            // apply ripple config
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
            $config['options'] = $options;
            $pdoClass = (Config::get('ripple.PDO_POOL_ENABLE') ?? true)
                ? PDOPool::class
                : PDO::class;

            // create
            return new Connection(
                static fn () => new $pdoClass($dsn, $username, $password),
                $database,
                $prefix,
                $config
            );
        });
    }
}
