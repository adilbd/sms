<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money received from a student: one receipt (`{year}-{000001}`), a method (cash, or
 * bKash/Nagad/Rocket with a hand-entered transaction ID) and allocations to dues. A
 * payment is never deleted: an admin cancels it (`cancelled_at`), which reverses its
 * allocations but keeps the receipt number used. `paid_at` is UTC. Written through
 * App\Services\FeePaymentService.
 */
class FeePayment extends Model
{
    use HasFactory;

    public const METHOD_CASH = 'cash';

    public const METHOD_BKASH = 'bkash';

    public const METHOD_NAGAD = 'nagad';

    public const METHOD_ROCKET = 'rocket';

    public const METHODS = [self::METHOD_CASH, self::METHOD_BKASH, self::METHOD_NAGAD, self::METHOD_ROCKET];

    /**
     * Bangla labels. Cash is "নগদ টাকা" so it isn't mistaken for the Nagad service, which is
     * "নগদ (মোবাইল)". Mirrors PAYMENT_METHODS in resources/js/admin/constants/fees.js.
     */
    public const METHOD_LABELS_BN = [
        self::METHOD_CASH => 'নগদ টাকা',
        self::METHOD_BKASH => 'বিকাশ',
        self::METHOD_NAGAD => 'নগদ (মোবাইল)',
        self::METHOD_ROCKET => 'রকেট',
    ];

    /** Methods that need a transaction ID. */
    public const MOBILE_METHODS = [self::METHOD_BKASH, self::METHOD_NAGAD, self::METHOD_ROCKET];

    protected $fillable = [
        'receipt_no',
        'student_id',
        'paid_at',
        'method',
        'transaction_id',
        'amount',
        'collected_by',
        'note',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'amount' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(FeePaymentAllocation::class);
    }
}
