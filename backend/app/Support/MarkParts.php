<?php

namespace App\Support;

use App\Models\ClassSubject;

/**
 * The rules for a marks scheme (up to three parts, each with a full and a pass mark),
 * shared by the curriculum and an exam's subject schedule. Stateless, like Seo.
 */
class MarkParts
{
    /**
     * At least one part is set, each part's full and pass are set together, full >= 1 and
     * pass <= full. Keyed by the field to blame, one message each.
     *
     * @param  array<string, mixed>  $values  `{part}_full` / `{part}_pass` keys; a missing key counts as null
     * @return array<string, string>
     */
    public static function errors(array $values): array
    {
        $errors = [];
        $anySet = false;

        foreach (ClassSubject::PARTS as $part) {
            $full = $values["{$part}_full"] ?? null;
            $pass = $values["{$part}_pass"] ?? null;

            if ($full === null && $pass === null) {
                continue;
            }

            $anySet = true;

            if ($full === null) {
                $errors["{$part}_full"] = "The {$part} full marks are required when the pass marks are set.";
            } elseif ($pass === null) {
                $errors["{$part}_pass"] = "The {$part} pass marks are required when the full marks are set.";
            } elseif ($full < 1) {
                $errors["{$part}_full"] = "The {$part} full marks must be at least 1.";
            } elseif ($pass > $full) {
                $errors["{$part}_pass"] = "The {$part} pass marks cannot be more than the full marks.";
            }
        }

        if (! $anySet) {
            $errors['written_full'] = 'At least one marks part (written, MCQ or practical) must be set.';
        }

        return $errors;
    }
}
