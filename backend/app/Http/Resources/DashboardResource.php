<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The computed dashboard (DashboardService::forUser()). The service already returns plain
 * values (money and percentages as `decimal:2` strings), so this only wraps them in `data`.
 *
 * @property array<string, mixed> $resource
 */
class DashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
