<?php

namespace App\Repositories\Contracts;

interface SettingRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  list<string>  $keys
     * @return array<string, ?string> key => value, only for keys present in the table
     */
    public function valuesFor(array $keys): array;

    /**
     * Insert or update one row per key. A null value is stored as null, not skipped.
     *
     * @param  array<string, ?string>  $pairs
     */
    public function upsertMany(array $pairs): void;
}
