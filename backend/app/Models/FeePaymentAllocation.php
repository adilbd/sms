<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one payment went to one due. Kept after a payment is cancelled (as history);
 * a due's paid_amount only counts allocations of payments that are not cancelled.
 */
class FeePaymentAllocation extends Model
{
    protected $fillable = [
        'fee_payment_id',
        'fee_due_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FeePayment::class, 'fee_payment_id');
    }

    public function due(): BelongsTo
    {
        return $this->belongsTo(FeeDue::class, 'fee_due_id');
    }
}
