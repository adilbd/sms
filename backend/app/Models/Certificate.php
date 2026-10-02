<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A certificate issued to a student (register entry). `data` is the snapshot of every
 * printed field taken at issue time; the print view only reads it. Written only through
 * App\Services\CertificateService. No soft deletes: a certificate is cancelled, never
 * deleted, and its serial number is never reused. `issued_on` is an Asia/Dhaka date.
 */
class Certificate extends Model
{
    use HasFactory;

    public const TYPE_TESTIMONIAL = 'testimonial';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_STUDY = 'study';

    public const TYPE_CHARACTER = 'character';

    public const TYPES = [self::TYPE_TESTIMONIAL, self::TYPE_TRANSFER, self::TYPE_STUDY, self::TYPE_CHARACTER];

    public const PREFIXES = [
        self::TYPE_TESTIMONIAL => 'TES',
        self::TYPE_TRANSFER => 'TC',
        self::TYPE_STUDY => 'STU',
        self::TYPE_CHARACTER => 'CHR',
    ];

    public const STATUS_ISSUED = 'issued';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'type',
        'serial_no',
        'student_id',
        'enrolment_id',
        'academic_year_id',
        'issued_on',
        'issued_by',
        'data',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $casts = [
        'issued_on' => 'date:Y-m-d',
        'data' => 'array',
        'cancelled_at' => 'datetime',
    ];

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrolment::class, 'enrolment_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class)->withTrashed();
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
