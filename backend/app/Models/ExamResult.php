<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student's processed result in one exam: totals, GPA, grade, merit positions and the
 * per-unit breakdown in `subjects` (JSON). Written only by App\Services\ResultService, which
 * replaces every row of an exam each time it is processed.
 */
class ExamResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_id',
        'student_id',
        'enrolment_id',
        'class_id',
        'section_id',
        'total_obtained',
        'total_full',
        'gpa',
        'grade',
        'is_pass',
        'failed_count',
        'passed_count',
        'class_position',
        'section_position',
        'subjects',
    ];

    protected $casts = [
        'total_obtained' => 'decimal:2',
        'total_full' => 'decimal:2',
        'gpa' => 'decimal:2',
        'is_pass' => 'boolean',
        'failed_count' => 'integer',
        'passed_count' => 'integer',
        'class_position' => 'integer',
        'section_position' => 'integer',
        'subjects' => 'array',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrolment::class, 'enrolment_id');
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(Classes::class, 'class_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
}
