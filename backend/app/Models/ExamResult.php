<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_schedule_id',
        'student_id',
        'marks_obtained',
        'marks_total',
        'grade',
        'remarks',
        'is_absent',
        'marked_by',
    ];

    protected $casts = [
        'marks_obtained' => 'decimal:2',
        'marks_total' => 'decimal:2',
        'is_absent' => 'boolean',
    ];

    public function examSchedule(): BelongsTo
    {
        return $this->belongsTo(ExamSchedule::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}

