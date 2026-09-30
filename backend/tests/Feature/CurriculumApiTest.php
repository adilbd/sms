<?php

namespace Tests\Feature;

use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurriculumApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@sms.com')->firstOrFail();
    }

    private function replace(Classes $class, array $subjects)
    {
        return $this->actingAs($this->admin, 'sanctum')->putJson("/api/classes/{$class->id}/subjects", ['subjects' => $subjects]);
    }

    private function row(Subject $subject, ?string $group = null, string $type = 'compulsory'): array
    {
        return ['subject_id' => $subject->id, 'group' => $group, 'type' => $type];
    }

    public function test_requires_authentication(): void
    {
        $class = Classes::factory()->create();

        $this->getJson("/api/classes/{$class->id}/subjects")->assertUnauthorized();
        $this->putJson("/api/classes/{$class->id}/subjects", ['subjects' => []])->assertUnauthorized();
    }

    public function test_a_role_without_edit_classes_can_read_but_not_replace(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');
        $class = Classes::factory()->create();

        $this->actingAs($teacher, 'sanctum')->getJson("/api/classes/{$class->id}/subjects")->assertOk();
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/classes/{$class->id}/subjects", ['subjects' => []])
            ->assertForbidden();
    }

    public function test_get_returns_the_curriculum_in_order_with_subjects(): void
    {
        $class = Classes::factory()->create(['number' => 3]);
        $second = Subject::factory()->create(['name' => 'English']);
        $first = Subject::factory()->create(['name' => 'Bangla']);
        ClassSubject::factory()->create(['class_id' => $class->id, 'subject_id' => $second->id, 'sort_order' => 1]);
        ClassSubject::factory()->create(['class_id' => $class->id, 'subject_id' => $first->id, 'sort_order' => 0]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/classes/{$class->id}/subjects")
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'class_id', 'subject_id', 'subject' => ['id', 'name', 'name_bn', 'is_active'], 'group', 'type', 'sort_order']]])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.subject.name', 'Bangla')
            ->assertJsonPath('data.1.subject.name', 'English');
    }

    public function test_group_filter_returns_common_plus_that_groups_rows(): void
    {
        $class = Classes::factory()->create(['number' => 9]);
        [$common, $sci, $hum, $opt] = Subject::factory()->count(4)->create();
        ClassSubject::factory()->create(['class_id' => $class->id, 'subject_id' => $common->id, 'sort_order' => 0]);
        ClassSubject::factory()->create(['class_id' => $class->id, 'subject_id' => $sci->id, 'group' => 'science', 'sort_order' => 1]);
        ClassSubject::factory()->create(['class_id' => $class->id, 'subject_id' => $hum->id, 'group' => 'humanities', 'sort_order' => 2]);
        ClassSubject::factory()->create(['class_id' => $class->id, 'subject_id' => $opt->id, 'group' => 'science', 'type' => 'optional', 'sort_order' => 3]);

        $ids = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/classes/{$class->id}/subjects?group=science")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->json('data.*.subject_id');

        $this->assertSame([$common->id, $sci->id, $opt->id], $ids);
    }

    public function test_invalid_group_filter_is_rejected(): void
    {
        $class = Classes::factory()->create(['number' => 9]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/classes/{$class->id}/subjects?group=x")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('group');
    }

    public function test_put_replaces_the_list_and_array_order_becomes_sort_order(): void
    {
        $class = Classes::factory()->create(['number' => 9]);
        [$a, $b, $c, $d] = Subject::factory()->count(4)->create();
        ClassSubject::factory()->create(['class_id' => $class->id, 'subject_id' => $a->id, 'sort_order' => 0]);
        ClassSubject::factory()->create(['class_id' => $class->id, 'subject_id' => $b->id, 'sort_order' => 1]);

        // Keeps $b (moved first, now optional), drops $a, adds $c and $d.
        $this->replace($class, [
            $this->row($b, 'science', 'optional'),
            $this->row($c),
            $this->row($d, 'humanities'),
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Curriculum updated successfully')
            ->assertJsonCount(3, 'data');

        $this->assertDatabaseMissing('class_subjects', ['class_id' => $class->id, 'subject_id' => $a->id]);
        $this->assertDatabaseHas('class_subjects', ['class_id' => $class->id, 'subject_id' => $b->id, 'group' => 'science', 'type' => 'optional', 'sort_order' => 0]);
        $this->assertDatabaseHas('class_subjects', ['class_id' => $class->id, 'subject_id' => $c->id, 'group' => null, 'sort_order' => 1]);
        $this->assertDatabaseHas('class_subjects', ['class_id' => $class->id, 'subject_id' => $d->id, 'group' => 'humanities', 'sort_order' => 2]);
        $this->assertSame(3, ClassSubject::where('class_id', $class->id)->count());
    }

    public function test_put_keeps_the_existing_row_when_unchanged_and_isolated_per_class(): void
    {
        $class = Classes::factory()->create(['number' => 9]);
        $other = Classes::factory()->create(['number' => 10]);
        $subject = Subject::factory()->create();
        $existing = ClassSubject::factory()->create(['class_id' => $class->id, 'subject_id' => $subject->id]);
        ClassSubject::factory()->create(['class_id' => $other->id, 'subject_id' => $subject->id]);

        $this->replace($class, [$this->row($subject)])->assertOk();

        $this->assertDatabaseHas('class_subjects', ['id' => $existing->id]);
        $this->assertSame(1, ClassSubject::where('class_id', $other->id)->count());
    }

    public function test_an_empty_list_clears_the_curriculum(): void
    {
        $class = Classes::factory()->create(['number' => 5]);
        ClassSubject::factory()->count(2)->create(['class_id' => $class->id]);

        $this->replace($class, [])->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame(0, ClassSubject::where('class_id', $class->id)->count());
    }

    public function test_an_optional_row_with_a_null_group_is_accepted_on_class_9(): void
    {
        $class = Classes::factory()->create(['number' => 9]);
        $agriculture = Subject::factory()->create();

        $this->replace($class, [$this->row($agriculture, null, 'optional')])
            ->assertOk()
            ->assertJsonPath('data.0.type', 'optional')
            ->assertJsonPath('data.0.group', null);
    }

    public function test_a_group_is_rejected_on_class_8(): void
    {
        $class = Classes::factory()->create(['number' => 8]);
        $subject = Subject::factory()->create();

        $this->replace($class, [$this->row($subject, 'science')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subjects.0.group');
    }

    public function test_an_optional_row_is_rejected_on_class_7(): void
    {
        $class = Classes::factory()->create(['number' => 7]);
        $subject = Subject::factory()->create();

        $this->replace($class, [$this->row($subject, null, 'optional')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subjects.0.type');
    }

    public function test_the_same_subject_twice_for_the_same_group_is_rejected(): void
    {
        $class = Classes::factory()->create(['number' => 9]);
        $subject = Subject::factory()->create();

        $this->replace($class, [$this->row($subject, 'science'), $this->row($subject, 'science', 'optional')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subjects.1.subject_id');

        $this->replace($class, [$this->row($subject), $this->row($subject)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subjects.1.subject_id');

        $this->assertSame(0, ClassSubject::count());
    }

    public function test_a_subject_cannot_be_both_common_and_group_specific(): void
    {
        $class = Classes::factory()->create(['number' => 9]);
        $subject = Subject::factory()->create();

        $this->replace($class, [$this->row($subject), $this->row($subject, 'science')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subjects.1.group');
    }

    public function test_an_inactive_subject_cannot_be_added(): void
    {
        $class = Classes::factory()->create(['number' => 5]);
        $subject = Subject::factory()->create(['is_active' => false]);

        $this->replace($class, [$this->row($subject)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subjects.0.subject_id');
    }

    public function test_a_soft_deleted_subject_cannot_be_added(): void
    {
        $class = Classes::factory()->create(['number' => 5]);
        $subject = Subject::factory()->create();
        $subject->delete();

        $this->replace($class, [$this->row($subject)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subjects.0.subject_id');
    }

    public function test_an_unknown_subject_group_and_type_are_rejected(): void
    {
        $class = Classes::factory()->create(['number' => 9]);
        $subject = Subject::factory()->create();

        $this->replace($class, [['subject_id' => 999999, 'group' => 'arts', 'type' => 'extra']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subjects.0.subject_id', 'subjects.0.group', 'subjects.0.type']);

        $this->replace($class, [['subject_id' => $subject->id, 'group' => null]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subjects.0.type');
    }

    public function test_the_subjects_key_is_required(): void
    {
        $class = Classes::factory()->create(['number' => 9]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/classes/{$class->id}/subjects", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subjects');
    }

    public function test_an_inactive_subject_already_in_the_curriculum_can_be_read_and_kept(): void
    {
        $class = Classes::factory()->create(['number' => 5]);
        $subject = Subject::factory()->create();
        ClassSubject::factory()->create(['class_id' => $class->id, 'subject_id' => $subject->id]);
        $subject->update(['is_active' => false]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/classes/{$class->id}/subjects")
            ->assertOk()
            ->assertJsonPath('data.0.subject.is_active', false);

        $this->replace($class, [$this->row($subject)])->assertOk();
    }

    public function test_a_non_numeric_class_id_returns_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/classes/1abc/subjects')->assertNotFound();
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/classes/1abc/subjects', ['subjects' => []])->assertNotFound();
    }

    public function test_an_unknown_class_returns_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/classes/999999/subjects')->assertNotFound();
    }

    public function test_deleting_a_subject_used_in_a_curriculum_returns_409(): void
    {
        $class = Classes::factory()->create(['number' => 5]);
        $subject = Subject::factory()->create();
        ClassSubject::factory()->create(['class_id' => $class->id, 'subject_id' => $subject->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/subjects/{$subject->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Subject is part of a class curriculum and cannot be deleted.');

        $this->assertDatabaseHas('subjects', ['id' => $subject->id, 'deleted_at' => null]);
    }

    public function test_deleting_a_class_removes_its_curriculum_rows(): void
    {
        $class = Classes::factory()->create(['number' => 5]);
        $other = Classes::factory()->create(['number' => 6]);
        ClassSubject::factory()->count(2)->create(['class_id' => $class->id]);
        ClassSubject::factory()->create(['class_id' => $other->id]);

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/classes/{$class->id}")->assertNoContent();

        $this->assertSame(0, ClassSubject::where('class_id', $class->id)->count());
        $this->assertSame(1, ClassSubject::where('class_id', $other->id)->count());
    }

    public function test_lowering_a_number_below_9_is_rejected_with_group_or_optional_rows(): void
    {
        $withGroup = Classes::factory()->create(['number' => 9]);
        ClassSubject::factory()->create(['class_id' => $withGroup->id, 'group' => 'science']);
        $withOptional = Classes::factory()->create(['number' => 10]);
        ClassSubject::factory()->create(['class_id' => $withOptional->id, 'type' => 'optional']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/classes/{$withGroup->id}", ['number' => 8])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('number');
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/classes/{$withOptional->id}", ['number' => 7])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('number');
    }

    public function test_lowering_a_number_is_allowed_with_only_common_compulsory_rows(): void
    {
        $class = Classes::factory()->create(['number' => 9]);
        ClassSubject::factory()->create(['class_id' => $class->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/classes/{$class->id}", ['number' => 8])
            ->assertOk()
            ->assertJsonPath('data.number', 8);
    }
}
