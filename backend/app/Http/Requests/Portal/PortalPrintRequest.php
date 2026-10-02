<?php

namespace App\Http\Requests\Portal;

/**
 * The print options of the portal's printable pages (marksheet, receipt, routine):
 * `language`, `orientation` and the paper `page`, which is `a4` here and narrowed or widened
 * by the marksheet and receipt requests.
 */
class PortalPrintRequest extends PortalPageRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'language' => ['sometimes', 'nullable', 'in:bn,en'],
            'page' => ['sometimes', 'nullable', 'in:a4'],
            'orientation' => ['sometimes', 'nullable', 'in:portrait,landscape'],
        ];
    }
}
