<?php

namespace Tests\Unit;

use App\Models\Classes;
use App\Repositories\Contracts\ClassSubjectRepositoryInterface;
use App\Services\CurriculumService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Services are unit-tested against a mocked repository interface, no database.
 */
class CurriculumServiceTest extends TestCase
{
    private function mockRepo(callable $expect): void
    {
        $this->mock(ClassSubjectRepositoryInterface::class, function (MockInterface $mock) use ($expect) {
            $mock->shouldReceive('lockClass')->andReturnUsing(fn ($c) => $c)->byDefault();
            $mock->shouldReceive('unusableRowIndexes')->andReturn([])->byDefault();
            $mock->shouldReceive('savedPaperGroups')->andReturn([])->byDefault();
            $mock->shouldReceive('assignedSubjects')->andReturn([])->byDefault();
            $expect($mock);
        });
    }

    /** @return array<string, list<string>> */
    private function errorsFor(Classes $class, array $rows): array
    {
        try {
            app(CurriculumService::class)->sync($class, $rows);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            return $e->errors();
        }
    }

    public function test_get_without_a_group_lists_the_whole_curriculum(): void
    {
        $class = new Classes(['number' => 9]);
        $this->mockRepo(function (MockInterface $mock) use ($class) {
            $mock->shouldReceive('forClass')->once()->with($class)->andReturn(new Collection);
            $mock->shouldNotReceive('forClassAndGroup');
        });

        app(CurriculumService::class)->get($class);
    }

    public function test_get_with_a_group_lists_the_effective_list(): void
    {
        $class = new Classes(['number' => 9]);
        $this->mockRepo(function (MockInterface $mock) use ($class) {
            $mock->shouldReceive('forClassAndGroup')->once()->with($class, 'science')->andReturn(new Collection);
            $mock->shouldNotReceive('forClass');
        });

        app(CurriculumService::class)->get($class, 'science');
    }

    public function test_a_group_is_refused_below_class_9(): void
    {
        $class = new Classes(['number' => 8]);
        $this->mockRepo(function (MockInterface $mock) {
            $mock->shouldNotReceive('sync');
        });

        $errors = $this->errorsFor($class, [
            ['subject_id' => 1, 'type' => 'compulsory'],
            ['subject_id' => 2, 'group' => 'science', 'type' => 'compulsory'],
        ]);

        $this->assertSame(['subjects.1.group'], array_keys($errors));
    }

    public function test_an_optional_row_is_refused_below_class_9(): void
    {
        $class = new Classes(['number' => 7]);
        $this->mockRepo(function (MockInterface $mock) {
            $mock->shouldNotReceive('sync');
        });

        $errors = $this->errorsFor($class, [['subject_id' => 1, 'group' => null, 'type' => 'optional']]);

        $this->assertSame(['subjects.0.type'], array_keys($errors));
    }

    public function test_the_same_subject_twice_for_the_same_group_is_refused(): void
    {
        $class = new Classes(['number' => 9]);
        $this->mockRepo(function (MockInterface $mock) {
            $mock->shouldNotReceive('sync');
        });

        $errors = $this->errorsFor($class, [
            ['subject_id' => 1, 'group' => 'science', 'type' => 'compulsory'],
            ['subject_id' => 1, 'group' => 'science', 'type' => 'optional'],
        ]);

        $this->assertSame(['subjects.1.subject_id'], array_keys($errors));
    }

    public function test_the_same_subject_for_different_groups_is_allowed(): void
    {
        $class = new Classes(['number' => 9]);
        $rows = [
            ['subject_id' => 1, 'group' => 'science', 'type' => 'compulsory'],
            ['subject_id' => 1, 'group' => 'humanities', 'type' => 'compulsory'],
        ];
        $this->mockRepo(function (MockInterface $mock) use ($class, $rows) {
            $mock->shouldReceive('sync')->once()->with($class, $rows);
            $mock->shouldReceive('forClass')->once()->andReturn(new Collection);
        });

        app(CurriculumService::class)->sync($class, $rows);
    }

    public function test_a_common_subject_cannot_also_be_listed_for_a_group(): void
    {
        $class = new Classes(['number' => 9]);
        $this->mockRepo(function (MockInterface $mock) {
            $mock->shouldNotReceive('sync');
        });

        $errors = $this->errorsFor($class, [
            ['subject_id' => 1, 'group' => 'science', 'type' => 'compulsory'],
            ['subject_id' => 1, 'group' => null, 'type' => 'compulsory'],
        ]);

        $this->assertSame(['subjects.0.group'], array_keys($errors));
    }

    public function test_a_subject_that_is_not_usable_is_refused(): void
    {
        $class = new Classes(['number' => 9]);
        $this->mockRepo(function (MockInterface $mock) {
            $mock->shouldReceive('unusableRowIndexes')->once()->andReturn([1]);
            $mock->shouldNotReceive('sync');
        });

        $errors = $this->errorsFor($class, [
            ['subject_id' => 1, 'type' => 'compulsory'],
            ['subject_id' => 2, 'type' => 'compulsory'],
        ]);

        $this->assertSame(['subjects.1.subject_id'], array_keys($errors));
    }

    public function test_an_optional_common_row_is_accepted_from_class_9_and_normalised(): void
    {
        $class = new Classes(['number' => 9]);
        $this->mockRepo(function (MockInterface $mock) use ($class) {
            $mock->shouldReceive('sync')->once()->with($class, [['subject_id' => 5, 'group' => null, 'type' => 'optional']]);
            $mock->shouldReceive('forClass')->once()->andReturn(new Collection);
        });

        app(CurriculumService::class)->sync($class, [['subject_id' => '5', 'type' => 'optional']]);
    }

    public function test_an_empty_list_clears_the_curriculum(): void
    {
        $class = new Classes(['number' => 3]);
        $this->mockRepo(function (MockInterface $mock) use ($class) {
            $mock->shouldReceive('sync')->once()->with($class, []);
            $mock->shouldReceive('forClass')->once()->andReturn(new Collection);
        });

        app(CurriculumService::class)->sync($class, []);
    }

    public function test_the_class_is_locked_before_validating_and_syncing(): void
    {
        $class = new Classes(['number' => 9]);
        $rows = [['subject_id' => 1, 'group' => null, 'type' => 'compulsory']];

        $this->mock(ClassSubjectRepositoryInterface::class, function (MockInterface $mock) use ($class, $rows) {
            $mock->shouldReceive('lockClass')->once()->with($class)->ordered()->andReturn($class);
            $mock->shouldReceive('unusableRowIndexes')->once()->ordered()->andReturn([]);
            $mock->shouldReceive('assignedSubjects')->andReturn([]);
            $mock->shouldReceive('savedPaperGroups')->andReturn([]);
            $mock->shouldReceive('sync')->once()->with($class, $rows)->ordered();
            $mock->shouldReceive('forClass')->once()->andReturn(new Collection);
        });

        app(CurriculumService::class)->sync($class, $rows);
    }

    public function test_validation_failures_happen_after_the_lock_and_before_any_write(): void
    {
        $class = new Classes(['number' => 5]);

        $this->mock(ClassSubjectRepositoryInterface::class, function (MockInterface $mock) use ($class) {
            $mock->shouldReceive('lockClass')->once()->with($class)->andReturn($class);
            $mock->shouldReceive('unusableRowIndexes')->andReturn([]);
            $mock->shouldReceive('assignedSubjects')->andReturn([]);
            $mock->shouldReceive('savedPaperGroups')->andReturn([]);
            $mock->shouldNotReceive('sync');
        });

        $this->errorsFor($class, [['subject_id' => 1, 'group' => 'science', 'type' => 'compulsory']]);
    }

    public function test_validation_uses_the_freshly_locked_class_not_the_stale_one(): void
    {
        $stale = new Classes(['number' => 9]);
        $fresh = new Classes(['number' => 8]);

        $this->mock(ClassSubjectRepositoryInterface::class, function (MockInterface $mock) use ($stale, $fresh) {
            $mock->shouldReceive('lockClass')->once()->with($stale)->andReturn($fresh);
            $mock->shouldReceive('unusableRowIndexes')->andReturn([]);
            $mock->shouldReceive('assignedSubjects')->andReturn([]);
            $mock->shouldReceive('savedPaperGroups')->andReturn([]);
            $mock->shouldNotReceive('sync');
        });

        $errors = $this->errorsFor($stale, [
            ['subject_id' => 1, 'group' => 'science', 'type' => 'optional'],
        ]);

        $this->assertArrayHasKey('subjects.0.group', $errors);
        $this->assertArrayHasKey('subjects.0.type', $errors);
    }

    /** @return array{subject_id: int, group: ?string, type: string} */
    private function row(int $id, array $extra = [], ?string $group = null, string $type = 'compulsory'): array
    {
        return ['subject_id' => $id, 'group' => $group, 'type' => $type, ...$extra];
    }

    private function refuses(array $rows, array $expectedKeys, int $number = 9): void
    {
        $this->mockRepo(function (MockInterface $mock) {
            $mock->shouldNotReceive('sync');
        });

        $this->assertSame($expectedKeys, array_keys($this->errorsFor(new Classes(['number' => $number]), $rows)));
    }

    public function test_a_row_with_part_fields_but_no_part_set_is_refused(): void
    {
        $this->refuses([$this->row(1, ['written_full' => null, 'written_pass' => null])], ['subjects.0.written_full']);
    }

    public function test_a_pass_mark_without_its_full_mark_is_refused(): void
    {
        $this->refuses([$this->row(1, ['written_full' => 70, 'written_pass' => 23, 'mcq_pass' => 8])], ['subjects.0.mcq_full']);
    }

    public function test_a_full_mark_without_its_pass_mark_is_refused(): void
    {
        $this->refuses([$this->row(1, ['written_full' => 70, 'written_pass' => 23, 'practical_full' => 25])], ['subjects.0.practical_pass']);
    }

    public function test_pass_above_full_is_refused(): void
    {
        $this->refuses([$this->row(1, ['written_full' => 50, 'written_pass' => 51])], ['subjects.0.written_pass']);
    }

    public function test_a_full_mark_below_one_is_refused(): void
    {
        $this->refuses([$this->row(1, ['written_full' => 0, 'written_pass' => 0])], ['subjects.0.written_full']);
    }

    public function test_every_part_set_is_accepted_and_passed_through(): void
    {
        $class = new Classes(['number' => 9]);
        $rows = [$this->row(1, [
            'written_full' => 50, 'written_pass' => 17, 'mcq_full' => 25, 'mcq_pass' => 8,
            'practical_full' => 25, 'practical_pass' => 8, 'paper_group' => null,
        ])];
        $this->mockRepo(function (MockInterface $mock) use ($class, $rows) {
            $mock->shouldReceive('sync')->once()->with($class, $rows);
            $mock->shouldReceive('forClass')->once()->andReturn(new Collection);
        });

        app(CurriculumService::class)->sync($class, [[...$rows[0], 'written_full' => '50']]);
    }

    public function test_a_row_without_part_fields_sends_none_to_the_repository(): void
    {
        $class = new Classes(['number' => 9]);
        $this->mockRepo(function (MockInterface $mock) use ($class) {
            // Only the keys sent are passed on, so the repository keeps saved marks.
            $mock->shouldReceive('sync')->once()->with($class, [$this->row(1)]);
            $mock->shouldReceive('forClass')->once()->andReturn(new Collection);
        });

        app(CurriculumService::class)->sync($class, [$this->row(1)]);
    }

    public function test_a_pair_of_two_matching_rows_is_accepted(): void
    {
        $class = new Classes(['number' => 9]);
        $rows = [$this->row(1, ['paper_group' => 'bangla']), $this->row(2, ['paper_group' => 'bangla'])];
        $this->mockRepo(function (MockInterface $mock) use ($class, $rows) {
            $mock->shouldReceive('sync')->once()->with($class, $rows);
            $mock->shouldReceive('forClass')->once()->andReturn(new Collection);
        });

        app(CurriculumService::class)->sync($class, $rows);
    }

    public function test_a_third_row_in_a_paper_group_is_refused(): void
    {
        $this->refuses([
            $this->row(1, ['paper_group' => 'bangla']),
            $this->row(2, ['paper_group' => 'bangla']),
            $this->row(3, ['paper_group' => 'bangla']),
        ], ['subjects.2.paper_group']);
    }

    public function test_a_pair_mixing_compulsory_and_optional_is_refused(): void
    {
        $this->refuses([
            $this->row(1, ['paper_group' => 'bangla']),
            $this->row(2, ['paper_group' => 'bangla'], null, 'optional'),
        ], ['subjects.1.paper_group']);
    }

    public function test_a_pair_across_different_groups_is_refused(): void
    {
        $this->refuses([
            $this->row(1, ['paper_group' => 'bangla'], 'science'),
            $this->row(2, ['paper_group' => 'bangla'], 'humanities'),
        ], ['subjects.1.paper_group']);
    }

    public function test_a_saved_pairing_counts_when_a_row_omits_paper_group(): void
    {
        $this->mockRepo(function (MockInterface $mock) {
            $mock->shouldReceive('savedPaperGroups')->andReturn(['1|' => 'bangla', '2|' => 'bangla']);
            $mock->shouldNotReceive('sync');
        });

        // Row 3 joins the saved pair; rows 1 and 2 omit paper_group and keep it.
        $errors = $this->errorsFor(new Classes(['number' => 9]), [
            $this->row(1), $this->row(2), $this->row(3, ['paper_group' => 'bangla']),
        ]);

        $this->assertSame(['subjects.2.paper_group'], array_keys($errors));
    }

    public function test_removing_a_subject_that_has_assignments_is_refused(): void
    {
        $this->mockRepo(function (MockInterface $mock) {
            $mock->shouldReceive('assignedSubjects')->andReturn([
                ['subject_id' => 1, 'name' => 'Physics', 'group' => null],
                ['subject_id' => 2, 'name' => 'Chemistry', 'group' => null],
            ]);
            $mock->shouldNotReceive('sync');
        });

        $errors = $this->errorsFor(new Classes(['number' => 9]), [$this->row(2)]);

        $this->assertSame(['subjects'], array_keys($errors));
        $this->assertStringContainsString('Physics', $errors['subjects'][0]);
        $this->assertStringContainsString('Unassign', $errors['subjects'][0]);
    }

    public function test_an_assignment_survives_when_a_row_for_its_sections_group_remains(): void
    {
        $this->mockRepo(function (MockInterface $mock) {
            $mock->shouldReceive('assignedSubjects')->andReturn([
                ['subject_id' => 1, 'name' => 'Physics', 'group' => 'science'],
            ]);
            $mock->shouldReceive('sync')->once();
            $mock->shouldReceive('forClass')->andReturn(new Collection);
        });

        app(CurriculumService::class)->sync(new Classes(['number' => 9]), [$this->row(1, [], 'science')]);
    }

    public function test_removing_only_the_row_for_the_assigned_sections_group_is_refused(): void
    {
        $this->mockRepo(function (MockInterface $mock) {
            $mock->shouldReceive('assignedSubjects')->andReturn([
                ['subject_id' => 1, 'name' => 'Physics', 'group' => 'science'],
            ]);
            $mock->shouldNotReceive('sync');
        });

        // A row for another group doesn't cover a Science section's assignment.
        $errors = $this->errorsFor(new Classes(['number' => 9]), [$this->row(1, [], 'humanities')]);

        $this->assertSame(['subjects'], array_keys($errors));
    }

    public function test_an_assignment_in_a_section_without_a_group_survives_any_row_of_the_subject(): void
    {
        $this->mockRepo(function (MockInterface $mock) {
            $mock->shouldReceive('assignedSubjects')->andReturn([
                ['subject_id' => 1, 'name' => 'Physics', 'group' => null],
            ]);
            $mock->shouldReceive('sync')->once();
            $mock->shouldReceive('forClass')->andReturn(new Collection);
        });

        app(CurriculumService::class)->sync(new Classes(['number' => 9]), [$this->row(1, [], 'humanities')]);
    }
}
