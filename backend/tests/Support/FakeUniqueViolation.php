<?php

namespace Tests\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use PDOException;

/**
 * Builds the exception the database layer throws for a duplicate key, with a realistic
 * INSERT statement (which names every column, as the real message does) and the
 * driver's own message for SQLite or MySQL.
 */
class FakeUniqueViolation
{
    /**
     * @param  'sqlite'|'mysql'  $driver
     * @param  list<string>  $columns  The columns of the unique index that was hit.
     * @param  list<string>  $insertColumns  Every column of the INSERT statement.
     */
    public static function make(string $driver, string $table, array $columns, array $insertColumns, ?string $index = null): UniqueConstraintViolationException
    {
        $quote = $driver === 'mysql' ? '`' : '"';
        $list = implode(', ', array_map(fn (string $c) => $quote.$c.$quote, $insertColumns));
        $sql = "insert into {$quote}{$table}{$quote} ({$list}) values (".implode(', ', array_fill(0, count($insertColumns), '?')).')';

        if ($driver === 'mysql') {
            $index ??= $table.'_'.implode('_', $columns).'_unique';
            $message = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '20260001' for key '{$table}.{$index}'";
        } else {
            $message = 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: '
                .implode(', ', array_map(fn (string $c) => "{$table}.{$c}", $columns));
        }

        return new UniqueConstraintViolationException(
            $driver,
            $sql,
            array_fill(0, count($insertColumns), 'x'),
            new PDOException($message),
        );
    }

    /**
     * @return list<'sqlite'|'mysql'>
     */
    public static function drivers(): array
    {
        return ['sqlite', 'mysql'];
    }
}
