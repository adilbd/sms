<?php

namespace App\Http\Requests\Post\Concerns;

trait NormalizesPostSlug
{
    /**
     * Store slugs lowercase, mirroring Subjects' NormalizesSubjectCode: MySQL's unique
     * index is case-insensitive, SQLite's (used in tests) is case-sensitive.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('slug'))) {
            $this->merge(['slug' => strtolower(trim($this->input('slug')))]);
        }
    }
}
