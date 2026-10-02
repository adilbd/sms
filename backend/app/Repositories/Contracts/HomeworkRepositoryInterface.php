<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;

/**
 * Homework lists. Filters understood by paginate() and forSectionSubjects(), each checked
 * with filled(): `academic_year_id`, `section_id`, `subject_id`, `from` and `to` (a range on
 * `due_on`, Y-m-d), `due` (`upcoming` is due today or later, `past` is due before today; needs
 * `today`, the Asia/Dhaka date) and `scope_section_ids` (internal: the sections a teacher is
 * limited to, built from the signed-in user and never from input).
 */
interface HomeworkRepositoryInterface extends RepositoryInterface
{
    /**
     * The homework of one section in one year for the given subjects (nearest due date
     * first), with its subject, staff and section loaded. An empty $subjectIds gives an
     * empty collection.
     *
     * @param  list<int>  $subjectIds
     * @param  array<string, mixed>  $filters
     */
    public function forSectionSubjects(int $sectionId, int $academicYearId, array $subjectIds, array $filters = []): Collection;
}
