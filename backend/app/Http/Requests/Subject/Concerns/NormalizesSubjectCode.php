<?php

namespace App\Http\Requests\Subject\Concerns;

trait NormalizesSubjectCode
{
    /**
     * Store codes uppercase so uniqueness behaves the same on MySQL (case-insensitive)
     * and SQLite (case-sensitive, used by the tests).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }
}
