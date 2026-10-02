<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Section extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'class_id',
        'shift_id',
        'name',
        'code',
        'capacity',
        'group',
        'description',
        'is_active',
    ];

    protected $attributes = [
        'capacity' => 40,
        'is_active' => true,
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'capacity' => 'integer',
    ];

    public function class(): BelongsTo
    {
        return $this->belongsTo(Classes::class, 'class_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(StudentEnrolment::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function mainClassSections(): HasMany
    {
        return $this->hasMany(ClassSection::class)->where('is_main', true);
    }

    public function subjectAssignments(): HasMany
    {
        return $this->hasMany(SubjectAssignment::class);
    }

    public function classSections(): HasMany
    {
        return $this->hasMany(ClassSection::class);
    }

    public function routineSlots(): HasMany
    {
        return $this->hasMany(RoutineSlot::class);
    }
}
