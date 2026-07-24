<?php

declare(strict_types=1);

namespace App\Domains\Local\Database;

final class MysqlConnection
{
    /**
     * `mysql`/`mysqldump` connection flags (`-h -P -u <db>`) resolved from the mysql
     * connection config, with every value passed through escapeshellarg() since they
     * land in a shell command line. The password is deliberately absent — it would be
     * visible in the process table; feed it out-of-band via {@see passwordEnv()}.
     */
    public static function args(): string
    {
        $connection = config('database.connections.mysql');

        return sprintf(
            '-h %s -P %s -u %s %s',
            escapeshellarg((string) $connection['host']),
            escapeshellarg((string) $connection['port']),
            escapeshellarg((string) $connection['username']),
            escapeshellarg((string) $connection['database']),
        );
    }

    /**
     * The password as a child-process env entry, so `mysql`/`mysqldump` read it via
     * MYSQL_PWD instead of a command-line flag that leaks into the process table.
     * Empty when no password is configured, so no env var is set in that case.
     *
     * @return array<string, string>
     */
    public static function passwordEnv(): array
    {
        $password = (string) (config('database.connections.mysql.password') ?? '');

        return $password === '' ? [] : ['MYSQL_PWD' => $password];
    }
}
