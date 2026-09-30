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
            $mock->shouldReceive('unusableSubjectIds')->andReturn([])->byDefault();
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
        $this->mockRepo(function (MockInterface $mock) use ($class) {
            $mock->shouldReceive('unusableSubjectIds')->once()->with($class, [1, 2])->andReturn([2]);
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
}
