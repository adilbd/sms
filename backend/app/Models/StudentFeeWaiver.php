<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A discount on one fee head for one student in one academic year: a `percent` of the due
 * (0-100) or a `fixed_amount` in BDT, never both. It applies to dues generated after it
 * is saved. Written through App\Services\FeeWaiverService.
 */
class StudentFeeWaiver extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'academic_year_id',
        'fee_head_id',
        'percent',
        'fixed_amount',
        'reason',
        'approved_by',
    ];

    protected $casts = [
        'percent' => 'decimal:2',
        'fixed_amount' => 'decimal:2',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(FeeHead::class, 'fee_head_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
