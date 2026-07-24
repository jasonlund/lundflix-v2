<?php

declare(strict_types=1);

namespace App\Domains\Local\Database;

final class MysqlConnection
{
    /**
     * `mysql`/`mysqldump` connection flags (`-h -P -u -p <db>`) resolved from the
     * mysql connection config, with every credential passed through
     * escapeshellarg() since they land in a shell command line.
     */
    public static function args(): string
    {
        $connection = config('database.connections.mysql');

        $args = sprintf(
            '-h %s -P %s -u %s',
            escapeshellarg((string) $connection['host']),
            escapeshellarg((string) $connection['port']),
            escapeshellarg((string) $connection['username']),
        );

        if (($connection['password'] ?? '') !== '') {
            $args .= ' -p'.escapeshellarg((string) $connection['password']);
        }

        return $args.' '.escapeshellarg((string) $connection['database']);
    }
}
