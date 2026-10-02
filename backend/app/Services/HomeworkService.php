<?php

namespace App\Services;

use App\Models\Homework;
use App\Models\Student;
use App\Models\User;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\ClassSubjectRepositoryInterface;
use App\Repositories\Contracts\HomeworkRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;
use App\Repositories\Contracts\StaffRepositoryInterface;
use App\Repositories\Contracts\SubjectAssignmentRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Support\PostBody;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Homework assigned to a section for a subject. Who may write it:
 *  - create: an admin for any section, otherwise a teacher assigned to that subject in that
 *    section and year (the same "is assigned" rule as mark entry, so every assigned teacher
 *    qualifies and a retired one doesn't);
 *  - update/delete: an admin at any time, otherwise only the author, while the due date
 *    (Asia/Dhaka, inclusive) hasn't passed and the teacher is still assigned.
 * The subject must be in the section's curriculum for its group. Students and guardians read
 * through forStudent(), limited to the subjects the student takes (StudentEnrolment::takes(),
 * which respects the 4th subject and choice pairs); `/api/my/homework` and the portal both
 * call it. Attachments (PDF or image) sit on the private disk and are only read through
 * attachment().
 */
class HomeworkService
{
    public const DISK = 'local';

    private const TIMEZONE = 'Asia/Dhaka';

    public function __construct(
        private HomeworkRepositoryInterface $homework,
        private SectionRepositoryInterface $sections,
        private SubjectAssignmentRepositoryInterface $assignments,
        private ClassSubjectRepositoryInterface $curriculum,
        private AcademicYearRepositoryInterface $years,
        private StaffRepositoryInterface $staff,
        private UserRepositoryInterface $users,
        private TeacherScope $teacherScope,
    ) {}

    /** Today's date in the school's time zone (Y-m-d). */
    public static function today(): string
    {
        return CarbonImmutable::now(self::TIMEZONE)->toDateString();
    }

    /**
     * Homework for staff, newest due date first. A teacher only sees their own sections;
     * the year defaults to the active one (every year when none is active).
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, int $perPage, User $viewer): LengthAwarePaginator
    {
        if (! filled($filters['academic_year_id'] ?? null)) {
            $filters['academic_year_id'] = $this->years->findActive()?->id;
        }

        $scope = $this->teacherScope->sectionIdsFor($viewer, filled($filters['academic_year_id']) ? (int) $filters['academic_year_id'] : null);

        if ($scope !== null) {
            $filters['scope_section_ids'] = $scope;
        }

        $filters['today'] = self::today();

        return $this->homework->paginate($filters, $perPage);
    }

    public function find(Homework $homework, User $viewer): Homework
    {
        $this->ensureVisible($viewer, $homework);

        return $homework->load(['subject', 'staff', 'section.class', 'section.shift']);
    }

    /**
     * @param  array{academic_year_id?: int|null, section_id: int, subject_id: int, title: string, details?: string|null, assigned_on?: string|null, due_on: string}  $data
     */
    public function create(array $data, ?UploadedFile $attachment, User $actor): Homework
    {
        $year = filled($data['academic_year_id'] ?? null)
            ? $this->years->findOrFail((int) $data['academic_year_id'])
            : $this->years->findActive();

        if ($year === null) {
            throw ValidationException::withMessages(['academic_year_id' => ['There is no active academic year.']]);
        }

        $section = $this->sections->findOrFail((int) $data['section_id']);
        $subjectId = (int) $data['subject_id'];

        if (! $this->users->hasRole($actor, 'admin')) {
            abort_unless(
                $this->assignments->userHoldsAssignment($actor->id, $section->id, $subjectId, $year->id),
                403,
                'You are not assigned to teach this subject in this section.'
            );
        }

        if (! in_array($subjectId, $this->assignments->curriculumSubjectIds((int) $section->class_id, $section->group), true)) {
            throw ValidationException::withMessages([
                'subject_id' => ['This subject is not in the curriculum of the section\'s class'.($section->group ? ' for its group' : '').'.'],
            ]);
        }

        $assignedOn = filled($data['assigned_on'] ?? null) ? $data['assigned_on'] : self::today();
        $this->ensureDueNotBeforeAssigned($assignedOn, $data['due_on']);

        $attributes = [
            'academic_year_id' => $year->id,
            'section_id' => $section->id,
            'subject_id' => $subjectId,
            'staff_id' => $this->staff->findByUserId($actor->id)?->id,
            'title' => $data['title'],
            'details' => $this->cleanDetails($data['details'] ?? null),
            'assigned_on' => $assignedOn,
            'due_on' => $data['due_on'],
        ];

        $path = null;

        try {
            if ($attachment !== null) {
                $path = $this->storeFile($attachment);
                $attributes['attachment_path'] = $path;
                $attributes['attachment_name'] = self::safeName($attachment->getClientOriginalName());
            }

            $created = DB::transaction(fn () => $this->homework->create($attributes));
        } catch (Throwable $e) {
            $this->deleteFile($path);

            throw $e;
        }

        return $created->load(['subject', 'staff', 'section.class', 'section.shift']);
    }

    /**
     * Only title, details, the dates and the attachment change; section, subject and year
     * are fixed (create a new one instead). `$removeAttachment` drops the file.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Homework $homework, array $data, ?UploadedFile $attachment, bool $removeAttachment, User $actor): Homework
    {
        $this->ensureCanModify($actor, $homework);

        $changes = array_intersect_key($data, array_flip(['title', 'details', 'assigned_on', 'due_on']));

        if (array_key_exists('details', $changes)) {
            $changes['details'] = $this->cleanDetails($changes['details']);
        }

        $candidate = (clone $homework)->fill($changes);
        $this->ensureDueNotBeforeAssigned($candidate->assigned_on->toDateString(), $candidate->due_on->toDateString());

        $old = $homework->attachment_path;
        $new = null;
        $dropOld = false;

        try {
            if ($attachment !== null) {
                $new = $this->storeFile($attachment);
                $changes['attachment_path'] = $new;
                $changes['attachment_name'] = self::safeName($attachment->getClientOriginalName());
                $dropOld = true;
            } elseif ($removeAttachment && $old !== null) {
                $changes['attachment_path'] = null;
                $changes['attachment_name'] = null;
                $dropOld = true;
            }

            $updated = DB::transaction(fn () => $this->homework->update($homework, $changes));
        } catch (Throwable $e) {
            $this->deleteFile($new);

            throw $e;
        }

        // The old file goes only after the new row is saved.
        if ($dropOld) {
            $this->deleteFile($old);
        }

        return $updated->load(['subject', 'staff', 'section.class', 'section.shift']);
    }

    public function delete(Homework $homework, User $actor): void
    {
        $this->ensureCanModify($actor, $homework);

        DB::transaction(fn () => $this->homework->delete($homework));
        // A soft delete keeps the row, and with it the file, so the attachment stays.
    }

    /**
     * The attachment's location for a staff download: 403 outside a teacher's sections,
     * 404 when there is no file.
     *
     * @return array{disk: string, path: string, name: string}
     */
    public function attachment(Homework $homework, User $viewer): array
    {
        $this->ensureVisible($viewer, $homework);

        return $this->attachmentFile($homework);
    }

    /**
     * The attachment's location, without any access check: the caller has already
     * authorised it (a signed URL minted for a homework the student can see).
     *
     * @return array{disk: string, path: string, name: string}
     */
    public function attachmentFile(Homework $homework): array
    {
        $path = $homework->attachment_path;

        abort_if($path === null || ! Storage::disk(self::DISK)->exists($path), 404);

        return [
            'disk' => self::DISK,
            'path' => $path,
            'name' => $homework->attachment_name ?: basename($path),
        ];
    }

    /**
     * The homework a student sees: their current section and year, only for subjects they
     * take. A student with no enrolment gets none. Filters: `from`, `to`, `due`.
     *
     * @param  array<string, mixed>  $filters
     */
    public function forStudent(Student $student, array $filters = []): Collection
    {
        $enrolment = $student->currentEnrolment;

        if ($enrolment === null) {
            return new Collection;
        }

        $enrolment->loadMissing('class');

        $subjectIds = $this->curriculum->forClass($enrolment->class)
            ->filter(fn ($row) => $enrolment->takes($row))
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $filters['today'] = self::today();

        return $this->homework->forSectionSubjects(
            (int) $enrolment->section_id,
            (int) $enrolment->academic_year_id,
            $subjectIds,
            $filters,
        );
    }

    /**
     * The homework a student has due today or in the next six days (the "due this week"
     * tile and `/api/my/homework?from=..&to=..` agree).
     */
    public function dueThisWeek(Student $student): Collection
    {
        $today = CarbonImmutable::now(self::TIMEZONE);

        return $this->forStudent($student, ['from' => $today->toDateString(), 'to' => $today->addDays(6)->toDateString()]);
    }

    private function ensureVisible(User $viewer, Homework $homework): void
    {
        $sections = $this->teacherScope->sectionIdsFor($viewer, (int) $homework->academic_year_id);

        abort_if($sections !== null && ! in_array((int) $homework->section_id, $sections, true), 403, 'You do not teach or lead this section.');
    }

    private function ensureCanModify(User $actor, Homework $homework): void
    {
        if ($this->users->hasRole($actor, 'admin')) {
            return;
        }

        $staff = $this->staff->findByUserId($actor->id);

        abort_unless($staff !== null && (int) $homework->staff_id === $staff->id, 403, 'Only the teacher who assigned this homework can change it.');
        abort_if(self::today() > $homework->due_on->toDateString(), 403, 'The due date has passed, so only an admin can change this homework.');
        abort_unless(
            $this->assignments->userHoldsAssignment($actor->id, (int) $homework->section_id, (int) $homework->subject_id, (int) $homework->academic_year_id),
            403,
            'You are no longer assigned to teach this subject in this section.'
        );
    }

    private function ensureDueNotBeforeAssigned(string $assignedOn, string $dueOn): void
    {
        if ($dueOn < $assignedOn) {
            throw ValidationException::withMessages(['due_on' => ['The due date cannot be before the assigned date.']]);
        }
    }

    /**
     * Sanitized HTML, or null when none was sent. Details that are empty once sanitized
     * (only tags, say) are a 422.
     */
    private function cleanDetails(?string $details): ?string
    {
        if (! filled($details)) {
            return null;
        }

        $clean = PostBody::sanitize($details);

        if (PostBody::isEmpty($clean)) {
            throw ValidationException::withMessages(['details' => ['The details are empty after removing unsupported content.']]);
        }

        return $clean;
    }

    private function storeFile(UploadedFile $file): string
    {
        return $file->storeAs('homework', Str::uuid().'.'.$file->extension(), self::DISK);
    }

    private function deleteFile(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** Fits an uploaded file name into the string(255) column, keeping the extension. */
    private static function safeName(string $name): string
    {
        if (mb_strlen($name) <= 255) {
            return $name;
        }

        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $suffix = $ext !== '' && mb_strlen($ext) <= 10 ? '.'.$ext : '';
        $base = $suffix !== '' ? mb_substr($name, 0, mb_strlen($name) - mb_strlen($suffix)) : $name;

        return Str::limit($base, 255 - mb_strlen($suffix), '').$suffix;
    }
}
