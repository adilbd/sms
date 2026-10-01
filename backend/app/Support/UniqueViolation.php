<?php

namespace App\Support;

use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Tells which unique index a UniqueConstraintViolationException hit, so a service can
 * report the right field. The exception's own message embeds the whole SQL statement
 * (every column name), so only the driver's message is inspected:
 *
 *  - SQLite: "UNIQUE constraint failed: users.email" (a comma-separated column list)
 *  - MySQL:  "Duplicate entry 'x' for key 'users.users_email_unique'" (8.0.19+) or
 *            "... for key 'users_email_unique'" (older versions and MariaDB)
 */
class UniqueViolation
{
    /**
     * @param  list<string>  $columns
     * @param  string|null  $index  Defaults to Laravel's `{table}_{columns}_unique`.
     */
    public static function is(UniqueConstraintViolationException $e, string $table, array $columns, ?string $index = null): bool
    {
        $message = $e->getPrevious()?->getMessage() ?? ($e->errorInfo[2] ?? '');
        $index ??= $table.'_'.implode('_', $columns).'_unique';

        if (preg_match('/UNIQUE constraint failed: (.+?)\s*$/', $message, $m)) {
            $failed = array_map('trim', explode(',', $m[1]));
            $expected = array_map(fn (string $column) => "{$table}.{$column}", $columns);

            return array_diff($failed, $expected) === [] && array_diff($expected, $failed) === [];
        }

        if (preg_match("/for key '([^']+)'\s*$/", $message, $m)) {
            return $m[1] === $index || $m[1] === "{$table}.{$index}";
        }

        return false;
    }
}
