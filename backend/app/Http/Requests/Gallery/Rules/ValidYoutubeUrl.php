<?php

namespace App\Http\Requests\Gallery\Rules;

use App\Support\YouTube;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts only a URL App\Support\YouTube recognizes (youtube.com/watch?v=, youtu.be/,
 * youtube.com/embed/, youtube.com/shorts/ and m.youtube.com). Everything else,
 * including non-YouTube hosts and malformed ids, fails validation.
 */
class ValidYoutubeUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || YouTube::extractId($value) === null) {
            $fail('The :attribute must be a valid YouTube video URL.');
        }
    }
}
