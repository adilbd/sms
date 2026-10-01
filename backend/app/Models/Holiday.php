<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A listed school holiday (public holidays, closures). Weekly holidays such as Friday are
 * not rows here; they come from the `weekly_holidays` institute setting. `date` is an
 * Asia/Dhaka calendar date kept as a plain `Y-m-d` string, like Attendance::$date.
 */
class Holiday extends Model
{
    /** @use HasFactory<\Database\Factories\HolidayFactory> */
    use HasFactory;

    protected $fillable = [
        'date',
        'name_en',
        'name_bn',
        'academic_year_id',
    ];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
