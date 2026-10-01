<?php

namespace App\Http\Requests\Portal;

/** The marksheet print options: `page` is one of a4, legal. */
class PortalMarksheetRequest extends PortalPageRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'page' => ['sometimes', 'nullable', 'in:a4,legal']];
    }
}
