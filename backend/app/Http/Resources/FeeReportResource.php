<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A computed fee report (FeeReportService::dues(), collection() or ledger()). The service
 * already returns plain values with money as `decimal:2` strings, so this only wraps them
 * in `data`.
 *
 * @property array<string, mixed> $resource
 */
class FeeReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
