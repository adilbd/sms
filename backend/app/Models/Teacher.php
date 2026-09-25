<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Teacher extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'employee_id',
        'joining_date',
        'date_of_birth',
        'gender',
        'blood_group',
        'qualification',
        'designation',
        'department',
        'salary',
        'address',
        'city',
        'state',
        'country',
        'pincode',
        'photo',
        'documents',
        'is_active',
    ];

    protected $casts = [
        'joining_date' => 'date',
        'date_of_birth' => 'date',
        'salary' => 'decimal:2',
        'is_active' => 'boolean',
        'documents' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subjectAssignments(): HasMany
    {
        return $this->hasMany(SubjectAssignment::class);
    }

    public function classSections(): HasMany
    {
        return $this->hasMany(ClassSection::class, 'class_teacher_id');
    }
}

