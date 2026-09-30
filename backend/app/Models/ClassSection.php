<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A section's class teacher (staff) for one academic year. Managed through
 * App\Services\ClassTeacherService, not a standalone CRUD endpoint.
 */
class ClassSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_id',
        'section_id',
        'academic_year_id',
        'staff_id',
    ];

    public function class(): BelongsTo
    {
        return $this->belongsTo(Classes::class, 'class_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
