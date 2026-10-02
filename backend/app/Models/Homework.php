<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\URL;

/**
 * Homework assigned to one section for one subject in an academic year. Written only through
 * App\Services\HomeworkService, which enforces who may assign and edit it. `assigned_on` and
 * `due_on` are Asia/Dhaka calendar dates; the attachment lives on the private disk.
 */
class Homework extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'homework';

    protected $fillable = [
        'academic_year_id',
        'section_id',
        'subject_id',
        'staff_id',
        'title',
        'details',
        'assigned_on',
        'due_on',
        'attachment_path',
        'attachment_name',
    ];

    protected $casts = [
        'assigned_on' => 'date:Y-m-d',
        'due_on' => 'date:Y-m-d',
    ];

    /**
     * A short-lived signed link to the attachment for students and guardians (the portal and
     * `/api/my/homework`), or null when there is none. Only mint it for homework the reader
     * is allowed to see: the signature is the only check on the route.
     */
    public function signedAttachmentUrl(int $minutes = 10): ?string
    {
        return $this->attachment_path === null
            ? null
            : URL::temporarySignedRoute('portal.homework.attachment', now()->addMinutes($minutes), ['homework' => $this->id]);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class)->withTrashed();
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class)->withTrashed();
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }
}
