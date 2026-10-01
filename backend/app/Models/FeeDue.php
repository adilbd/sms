<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One amount a student owes for one fee head and period (`YYYY-MM`, `one_time` or
 * `exam:{id}`) under one enrolment. `amount` is the rate when generated, `waiver_amount`
 * the waiver then, `net_amount` what is actually owed and `paid_amount` the sum of its
 * allocations from payments that are not cancelled. Money is BDT, `decimal:2`. Written
 * through App\Services\FeeDueService and FeePaymentService.
 */
class FeeDue extends Model
{
    use HasFactory;

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_WAIVED = 'waived';

    public const STATUSES = [self::STATUS_UNPAID, self::STATUS_PARTIAL, self::STATUS_PAID, self::STATUS_WAIVED];

    public const PERIOD_ONE_TIME = 'one_time';

    protected $attributes = [
        'waiver_amount' => '0.00',
        'paid_amount' => '0.00',
        'status' => self::STATUS_UNPAID,
    ];

    protected $fillable = [
        'student_id',
        'enrolment_id',
        'fee_head_id',
        'period',
        'amount',
        'waiver_amount',
        'net_amount',
        'paid_amount',
        'status',
        'due_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'waiver_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_date' => 'date:Y-m-d',
    ];

    public static function examPeriod(int $examId): string
    {
        return "exam:{$examId}";
    }

    /** What is still owed, in paisa. */
    public function outstandingPaisa(): int
    {
        return Money::toPaisa($this->net_amount) - Money::toPaisa($this->paid_amount);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrolment::class, 'enrolment_id');
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(FeeHead::class, 'fee_head_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(FeePaymentAllocation::class, 'fee_due_id');
    }
}
