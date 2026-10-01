<?php

namespace App\Http\Resources;

use App\Models\FeePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment. The receipt data (`allocations`, each with its due, head and enrolment, plus
 * the student and collector) is only present when those relations are loaded, which the
 * show/store/cancel responses do (FeePaymentRepository::loadReceipt()); lists leave them out.
 *
 * @mixin FeePayment
 */
class FeePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_no' => $this->receipt_no,
            'student_id' => $this->student_id,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'method' => $this->method,
            'transaction_id' => $this->transaction_id,
            'amount' => $this->amount,
            'collected_by' => $this->collected_by,
            'collected_by_name' => $this->whenLoaded('collector', fn () => $this->collector?->name),
            'note' => $this->note,
            'is_cancelled' => $this->isCancelled(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => $this->cancelled_by,
            'cancelled_by_name' => $this->whenLoaded('canceller', fn () => $this->canceller?->name),
            'cancel_reason' => $this->cancel_reason,
            'student' => new FeeStudentResource($this->whenLoaded('student')),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($allocation) => [
                'fee_due_id' => $allocation->fee_due_id,
                'amount' => $allocation->amount,
                'due' => new FeeDueResource($allocation->due),
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
