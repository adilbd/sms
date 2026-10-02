<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One class teacher (staff) of a section for one academic year: a section has one main
 * teacher and any number of co-teachers (`is_main` false). Managed through
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
        'is_main',
    ];

    protected $casts = [
        'is_main' => 'boolean',
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
