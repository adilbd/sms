<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One family's application for a class in an admission round. The status moves through
 * submitted -> under_review -> test_scheduled -> approved / waitlisted / rejected, and an
 * approved application becomes `admitted` when it is converted to a Student. Files live
 * on the private disk and are never public URLs. Written through
 * App\Services\AdmissionService (submit) and AdmissionApplicationService (review).
 */
class AdmissionApplication extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_TEST_SCHEDULED = 'test_scheduled';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_WAITLISTED = 'waitlisted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ADMITTED = 'admitted';

    public const STATUSES = [
        self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW, self::STATUS_TEST_SCHEDULED, self::STATUS_APPROVED,
        self::STATUS_WAITLISTED, self::STATUS_REJECTED, self::STATUS_ADMITTED,
    ];

    /** The statuses an admin may set (submitted is the start, admitted comes from converting). */
    public const SETTABLE_STATUSES = [
        self::STATUS_UNDER_REVIEW, self::STATUS_TEST_SCHEDULED, self::STATUS_APPROVED,
        self::STATUS_WAITLISTED, self::STATUS_REJECTED,
    ];

    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        self::STATUS_SUBMITTED => [self::STATUS_UNDER_REVIEW, self::STATUS_REJECTED],
        self::STATUS_UNDER_REVIEW => [self::STATUS_TEST_SCHEDULED, self::STATUS_APPROVED, self::STATUS_WAITLISTED, self::STATUS_REJECTED],
        // Scheduling again (a new time or venue) keeps the status.
        self::STATUS_TEST_SCHEDULED => [self::STATUS_TEST_SCHEDULED, self::STATUS_APPROVED, self::STATUS_WAITLISTED, self::STATUS_REJECTED],
        self::STATUS_WAITLISTED => [self::STATUS_APPROVED, self::STATUS_REJECTED],
        self::STATUS_APPROVED => [],
        self::STATUS_REJECTED => [],
        self::STATUS_ADMITTED => [],
    ];

    /** Statuses that hold a seat in the round's class. */
    public const SEAT_STATUSES = [self::STATUS_APPROVED, self::STATUS_ADMITTED];

    public const STATUS_LABELS_BN = [
        self::STATUS_SUBMITTED => 'জমা দেওয়া হয়েছে',
        self::STATUS_UNDER_REVIEW => 'যাচাই চলছে',
        self::STATUS_TEST_SCHEDULED => 'পরীক্ষা/সাক্ষাৎকারের সময় নির্ধারিত',
        self::STATUS_APPROVED => 'অনুমোদিত',
        self::STATUS_WAITLISTED => 'অপেক্ষমাণ তালিকায়',
        self::STATUS_REJECTED => 'বাতিল',
        self::STATUS_ADMITTED => 'ভর্তি সম্পন্ন',
    ];

    public const STATUS_LABELS_EN = [
        self::STATUS_SUBMITTED => 'Submitted',
        self::STATUS_UNDER_REVIEW => 'Under review',
        self::STATUS_TEST_SCHEDULED => 'Test or interview scheduled',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_WAITLISTED => 'Waitlisted',
        self::STATUS_REJECTED => 'Not selected',
        self::STATUS_ADMITTED => 'Admitted',
    ];

    public const FILE_KINDS = ['photo', 'birth_certificate', 'previous_school_doc'];

    /** Mirrors the column defaults in the admission_applications migration. */
    protected $attributes = [
        'status' => self::STATUS_SUBMITTED,
        'nationality' => 'Bangladeshi',
    ];

    protected $fillable = [
        'application_no',
        'round_id',
        'class_id',
        'group',
        'shift_id',
        'name_en',
        'name_bn',
        'date_of_birth',
        'gender',
        'religion',
        'birth_registration_number',
        'blood_group',
        'nationality',
        'previous_school',
        'previous_class',
        'father_name_en',
        'father_name_bn',
        'father_mobile',
        'mother_name_en',
        'mother_name_bn',
        'mother_mobile',
        'guardian_relation',
        'guardian_name',
        'guardian_mobile',
        'guardian_email',
        'present_address',
        'permanent_address',
        'district',
        'photo_path',
        'birth_certificate_path',
        'previous_school_doc_path',
        'status',
        'test_at',
        'test_venue',
        'test_score',
        'admin_note',
        'decided_by',
        'decided_at',
        'student_id',
        'submitted_ip_hash',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'test_at' => 'datetime',
        'test_score' => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    public function round(): BelongsTo
    {
        return $this->belongsTo(AdmissionRound::class, 'round_id');
    }

    public function class(): BelongsTo
    {
        return $this->belongsTo(Classes::class, 'class_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function displayName(): string
    {
        return (string) ($this->name_bn ?: $this->name_en);
    }

    /** The birth registration number with all but the last 4 digits starred, for printed family copies. */
    public function maskedBirthRegistration(): string
    {
        $number = (string) $this->birth_registration_number;

        return str_repeat('*', max(strlen($number) - 4, 0)).substr($number, -4);
    }

    /** The private-disk path for a file kind (photo, birth_certificate, previous_school_doc), or null. */
    public function filePath(string $kind): ?string
    {
        return match ($kind) {
            'photo' => $this->photo_path,
            'birth_certificate' => $this->birth_certificate_path,
            'previous_school_doc' => $this->previous_school_doc_path,
            default => null,
        };
    }
}
