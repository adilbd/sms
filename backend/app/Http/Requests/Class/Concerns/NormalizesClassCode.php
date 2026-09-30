<?php

namespace App\Http\Requests\Class\Concerns;

trait NormalizesClassCode
{
    /**
     * Store codes uppercase so uniqueness behaves the same on MySQL (case-insensitive)
     * and SQLite (case-sensitive, used by the tests). See Subject\Concerns\NormalizesSubjectCode.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }
}
