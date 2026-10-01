<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A student's own fees (FeeReportService::ownFees()/childFees()): every due, every payment
 * (cancelled ones flagged) and the outstanding total as a `decimal:2` string.
 *
 * @property array{student: \App\Models\Student, outstanding_total: string, dues: \Illuminate\Support\Collection, payments: \Illuminate\Support\Collection} $resource
 */
class MyFeesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'student' => new FeeStudentResource($this->resource['student']),
            'outstanding_total' => $this->resource['outstanding_total'],
            'dues' => FeeDueResource::collection($this->resource['dues']),
            'payments' => FeePaymentResource::collection($this->resource['payments']),
        ];
    }
}
