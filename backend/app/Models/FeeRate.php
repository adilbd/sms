<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one fee head costs for a class in an academic year, optionally for one group (Class
 * 9+; a null group is the whole class, and a group's own rate wins over it). `amount` is
 * BDT, `decimal:2`. `due_day` (1-28) is the day of the month a monthly due falls on.
 * Written through App\Services\FeeRateService.
 */
class FeeRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'fee_head_id',
        'class_id',
        'academic_year_id',
        'group',
        'amount',
        'due_day',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_day' => 'integer',
    ];

    public function head(): BelongsTo
    {
        return $this->belongsTo(FeeHead::class, 'fee_head_id');
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(Classes::class, 'class_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
