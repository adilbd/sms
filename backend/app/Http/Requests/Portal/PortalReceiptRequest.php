<?php

namespace App\Http\Requests\Portal;

/** The receipt print options: `page` is one of a4, a5. */
class PortalReceiptRequest extends PortalPageRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'page' => ['sometimes', 'nullable', 'in:a4,a5']];
    }
}
