<?php

namespace App\Support;

/**
 * Logins (usernames and emails) are stored trimmed and lowercased, so an exact match on
 * the lowercased input can use the unique index on both MySQL and SQLite. A staff
 * member's username is their employee ID run through this (e.g. "VHBUB-12" is stored as
 * "vhbub-12" and still signs in as "VHBUB-12"). Stateless helper.
 */
class Username
{
    public static function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
