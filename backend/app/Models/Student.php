<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A student's profile plus the guardian's details. Class, section, group and roll live in
 * StudentEnrolment (one per academic year). `user_id` is the student's own login
 * (username = student_id); `guardian_user_id` is the guardian's login (username =
 * guardian mobile), shared by siblings. See docs/tasks/students-module.md.
 */
class Student extends Model
{
    use HasFactory, SoftDeletes;

    public const GENDERS = ['male', 'female', 'other'];

    public const RELIGIONS = ['islam', 'hinduism', 'buddhism', 'christianity', 'other'];

    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LEFT = 'left';

    public const STATUS_GRADUATED = 'graduated';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_LEFT, self::STATUS_GRADUATED];

    public const GUARDIAN_RELATIONS = ['father', 'mother', 'other'];

    /** Mirrors the column defaults in the students migration. */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'nationality' => 'Bangladeshi',
    ];

    protected $fillable = [
        'student_id',
        'name_en',
        'name_bn',
        'date_of_birth',
        'gender',
        'religion',
        'blood_group',
        'birth_registration_number',
        'nationality',
        'mobile',
        'email',
        'present_address',
        'permanent_address',
        'district',
        'photo',
        'admission_date',
        'status',
        'leaving_date',
        'father_name_en',
        'father_name_bn',
        'father_mobile',
        'father_occupation',
        'mother_name_en',
        'mother_name_bn',
        'mother_mobile',
        'mother_occupation',
        'guardian_relation',
        'guardian_name',
        'guardian_mobile',
        'guardian_user_id',
        'user_id',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'admission_date' => 'date',
        'leaving_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function guardianUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_user_id');
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(StudentEnrolment::class);
    }

    /**
     * Not a fixed relation: callers eager-load it constrained to the academic year they
     * mean (the active year by default), see StudentRepository.
     */
    public function currentEnrolment(): HasOne
    {
        return $this->hasOne(StudentEnrolment::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function examMarks(): HasMany
    {
        return $this->hasMany(ExamMark::class);
    }

    public function examResults(): HasMany
    {
        return $this->hasMany(ExamResult::class);
    }

    public function feeDues(): HasMany
    {
        return $this->hasMany(FeeDue::class);
    }

    public function feePayments(): HasMany
    {
        return $this->hasMany(FeePayment::class);
    }

    public function feeWaivers(): HasMany
    {
        return $this->hasMany(StudentFeeWaiver::class);
    }

    public function displayName(): string
    {
        return $this->name_en ?: (string) $this->name_bn;
    }

    public function photoUrl(): ?string
    {
        return $this->photo ? Storage::disk('public')->url($this->photo) : null;
    }
}
