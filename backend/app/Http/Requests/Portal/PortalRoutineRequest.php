<?php

namespace App\Http\Requests\Portal;

/** The routine page prints A4 landscape always, so `language` is its only print option. */
class PortalRoutineRequest extends PortalPageRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'language' => ['sometimes', 'nullable', 'in:bn,en']];
    }
}
