<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicYear extends Model
{
    use HasFactory, SoftDeletes;

    public const MIN_YEAR = 2000;

    public const MAX_YEAR = 2100;

    protected $fillable = [
        'year',
        'name',
        'code',
        'start_date',
        'end_date',
        'is_active',
        'description',
    ];

    protected $casts = [
        'year' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function enrolments(): HasMany
    {
        return $this->hasMany(StudentEnrolment::class);
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }

    public function feeRates(): HasMany
    {
        return $this->hasMany(FeeRate::class);
    }

    /** Fee dues of the year's enrolments (a due belongs to an enrolment, which has the year). */
    public function feeDues(): HasManyThrough
    {
        return $this->hasManyThrough(FeeDue::class, StudentEnrolment::class, 'academic_year_id', 'enrolment_id');
    }

    public function classSections(): HasMany
    {
        return $this->hasMany(ClassSection::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    public function subjectAssignments(): HasMany
    {
        return $this->hasMany(SubjectAssignment::class);
    }

    public function routineSlots(): HasMany
    {
        return $this->hasMany(RoutineSlot::class);
    }
}
