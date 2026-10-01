<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student's marks in one exam subject, part by part. `enrolment_id` is the enrolment
 * the marks were entered under. Written through App\Services\ExamMarkService.
 */
class ExamMark extends Model
{
    use HasFactory;

    protected $attributes = [
        'is_absent' => false,
    ];

    protected $fillable = [
        'exam_subject_id',
        'student_id',
        'enrolment_id',
        'written',
        'mcq',
        'practical',
        'is_absent',
        'entered_by',
    ];

    protected $casts = [
        'written' => 'decimal:2',
        'mcq' => 'decimal:2',
        'practical' => 'decimal:2',
        'is_absent' => 'boolean',
    ];

    public function examSubject(): BelongsTo
    {
        return $this->belongsTo(ExamSubject::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrolment::class, 'enrolment_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
