<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\StudentEnrolment;
use App\Models\User;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;
use App\Repositories\Contracts\StudentEnrolmentRepositoryInterface;
use App\Repositories\Contracts\StudentRepositoryInterface;
use App\Support\SchoolHeader;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What a student ID card prints: the active enrolments of the active academic year in a
 * section (by roll), or one student's. Read-only, no register entry. A scoped teacher gets
 * 403 for a section or student outside their TeacherScope sections.
 */
class IdCardService
{
    public function __construct(
        private StudentEnrolmentRepositoryInterface $enrolments,
        private StudentRepositoryInterface $students,
        private SectionRepositoryInterface $sections,
        private AcademicYearRepositoryInterface $years,
        private InstituteSettingsService $institute,
        private StudentService $studentService,
        private TeacherScope $teacherScope,
    ) {}

    /**
     * @return array{academic_year: AcademicYear, school: array<string, mixed>, cards: Collection<int, StudentEnrolment>}
     */
    public function forSection(int $sectionId, User $viewer): array
    {
        $section = $this->sections->findOrFail($sectionId);
        $year = $this->activeYear('section_id');

        $scope = $this->teacherScope->sectionIdsFor($viewer, $year->id);
        abort_if($scope !== null && ! in_array($section->id, $scope, true), 403, 'This section is not one of yours.');

        return $this->sheet($year, collect($this->enrolments->activeInSection($section->id, $year->id)->all()));
    }

    /**
     * @return array{academic_year: AcademicYear, school: array<string, mixed>, cards: Collection<int, StudentEnrolment>}
     */
    public function forStudent(int $studentId, User $viewer): array
    {
        $student = $this->students->findOrFail($studentId);
        $year = $this->activeYear('student_id');

        $this->studentService->ensureInSections($student, $this->teacherScope->sectionIdsFor($viewer, $year->id), $year->id);

        $enrolment = $this->enrolments->forStudentAndYear($student, $year->id);

        if ($enrolment === null || $enrolment->status !== StudentEnrolment::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['student_id' => ['The student has no active enrolment in the active academic year.']]);
        }

        $enrolment->load(['student', 'academicYear', 'class', 'section.shift']);

        return $this->sheet($year, collect([$enrolment]));
    }

    private function activeYear(string $field): AcademicYear
    {
        return $this->years->findActive()
            ?? throw ValidationException::withMessages([$field => ['There is no active academic year.']]);
    }

    private function sheet(AcademicYear $year, Collection $cards): array
    {
        return [
            'academic_year' => $year,
            'school' => SchoolHeader::from($this->institute->profile()),
            'cards' => $cards,
        ];
    }
}
