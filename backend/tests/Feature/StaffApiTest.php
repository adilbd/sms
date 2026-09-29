<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaffApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@sms.com')->firstOrFail();

        Storage::fake('public', ['url' => config('filesystems.disks.public.url')]);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/staff')->assertUnauthorized();
    }

    public function test_teacher_role_is_forbidden_on_every_action(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');
        $staff = Staff::factory()->create();

        $this->actingAs($teacher, 'sanctum')->getJson('/api/staff')->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->getJson("/api/staff/{$staff->id}")->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->postJson('/api/staff', [])->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->putJson("/api/staff/{$staff->id}", [])->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->deleteJson("/api/staff/{$staff->id}")->assertForbidden();
    }

    public function test_store_with_a_photo_shifts_and_educations(): void
    {
        $shift = Shift::factory()->create();
        $photo = UploadedFile::fake()->image('photo.jpg');

        $response = $this->actingAs($this->admin, 'sanctum')->post('/api/staff', [
            'name_en' => 'Md. Karim',
            'name_bn' => 'মো. করিম',
            'category' => 'teacher',
            'position' => 'teacher',
            'designation' => 'Assistant Teacher',
            'status' => 'active',
            'shift_ids' => [$shift->id],
            'photo' => $photo,
            'educations' => [
                ['degree' => 'B.A', 'board_university' => 'Dhaka University', 'passing_year' => '2000'],
                ['degree' => 'M.A', 'board_university' => 'Dhaka University', 'passing_year' => '2002'],
            ],
        ]);

        $response->assertCreated()->assertJsonStructure([
            'data' => ['id', 'name_en', 'name_bn', 'photo_url', 'shifts', 'educations', 'trainings'],
        ]);

        $path = Staff::first()->photo;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        $this->assertCount(2, $response->json('data.educations'));
        $this->assertCount(1, $response->json('data.shifts'));
    }

    public function test_update_with_a_new_photo_deletes_the_old_one_and_remove_photo_clears_it(): void
    {
        $shift = Shift::factory()->create();
        $staff = Staff::factory()->create(['photo' => null]);
        $staff->shifts()->attach($shift);

        Storage::disk('public')->put('staff/old.jpg', 'old-contents');
        $staff->update(['photo' => 'staff/old.jpg']);

        $newPhoto = UploadedFile::fake()->image('new.jpg');

        $this->actingAs($this->admin, 'sanctum')
            ->post("/api/staff/{$staff->id}", ['_method' => 'PUT', 'photo' => $newPhoto])
            ->assertOk();

        Storage::disk('public')->assertMissing('staff/old.jpg');
        $newPath = $staff->fresh()->photo;
        Storage::disk('public')->assertExists($newPath);

        $this->actingAs($this->admin, 'sanctum')
            ->post("/api/staff/{$staff->id}", ['_method' => 'PUT', 'remove_photo' => '1'])
            ->assertOk()
            ->assertJsonPath('data.photo_url', null);

        Storage::disk('public')->assertMissing($newPath);
        $this->assertNull($staff->fresh()->photo);
    }

    public function test_update_reorders_and_trims_educations(): void
    {
        $shift = Shift::factory()->create();
        $staff = Staff::factory()->create();
        $staff->shifts()->attach($shift);
        $keep = $staff->educations()->create(['degree' => 'B.A', 'sort_order' => 0]);
        $drop = $staff->educations()->create(['degree' => 'M.A', 'sort_order' => 1]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/staff/{$staff->id}", [
                'educations' => [
                    ['degree' => 'PhD'],
                    ['id' => $keep->id, 'degree' => 'B.A (updated)'],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('staff_educations', ['id' => $drop->id]);
        $this->assertDatabaseHas('staff_educations', ['id' => $keep->id, 'degree' => 'B.A (updated)', 'sort_order' => 1]);
        $this->assertDatabaseHas('staff_educations', ['degree' => 'PhD', 'sort_order' => 0]);
    }

    public function test_index_filters_by_status_position_shift_and_search(): void
    {
        $shift = Shift::factory()->create();
        $otherShift = Shift::factory()->create();

        $match = Staff::factory()->create(['name_en' => 'Karim Teacher', 'position' => Staff::POSITION_TEACHER, 'status' => Staff::STATUS_RETIRED, 'leaving_date' => now()]);
        $match->shifts()->attach($shift);

        $noMatchActive = Staff::factory()->create(['name_en' => 'Karim Active', 'position' => Staff::POSITION_TEACHER]);
        $noMatchActive->shifts()->attach($shift);

        $noMatchShift = Staff::factory()->create(['name_en' => 'Karim Other Shift', 'position' => Staff::POSITION_TEACHER, 'status' => Staff::STATUS_RETIRED, 'leaving_date' => now()]);
        $noMatchShift->shifts()->attach($otherShift);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/staff?'.http_build_query(['is_active' => '0', 'position' => 'teacher', 'shift' => $shift->id, 'search' => 'Karim']))
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total']]);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEquals([$match->id], $ids->all());
    }

    public function test_destroy_soft_deletes(): void
    {
        $staff = Staff::factory()->create();

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/staff/{$staff->id}")->assertNoContent();

        $this->assertSoftDeleted('staff', ['id' => $staff->id]);
    }

    public static function invalidStorePayloads(): array
    {
        return [
            'missing shift_ids' => [['name_en' => 'X', 'category' => 'teacher', 'position' => 'teacher'], 'shift_ids'],
            'bad position' => [['name_en' => 'X', 'category' => 'teacher', 'position' => 'nope', 'shift_ids' => [1]], 'position'],
            'bad blood group' => [['name_en' => 'X', 'category' => 'teacher', 'position' => 'teacher', 'shift_ids' => [1], 'blood_group' => 'ZZ'], 'blood_group'],
        ];
    }

    /** @dataProvider invalidStorePayloads */
    public function test_store_validation_errors(array $payload, string $field): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/staff', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public function test_store_missing_both_names_is_rejected(): void
    {
        $shift = Shift::factory()->create();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/staff', [
            'category' => 'teacher', 'position' => 'teacher', 'shift_ids' => [$shift->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('name_en');
    }

    public function test_store_rejects_an_inactive_shift_id(): void
    {
        $shift = Shift::factory()->inactive()->create();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/staff', [
            'name_en' => 'X',
            'category' => 'teacher',
            'position' => 'teacher',
            'shift_ids' => [$shift->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('shift_ids.0');
    }

    public function test_store_rejects_a_non_image_photo(): void
    {
        $shift = Shift::factory()->create();
        $file = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');

        $this->actingAs($this->admin, 'sanctum')->post('/api/staff', [
            'name_en' => 'X',
            'category' => 'teacher',
            'position' => 'teacher',
            'shift_ids' => [$shift->id],
            'photo' => $file,
        ])->assertUnprocessable()->assertJsonValidationErrors('photo');
    }

    public function test_status_retired_without_leaving_date_is_rejected_including_on_partial_update(): void
    {
        $shift = Shift::factory()->create();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/staff', [
            'name_en' => 'X', 'category' => 'teacher', 'position' => 'teacher', 'status' => 'retired', 'shift_ids' => [$shift->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('leaving_date');

        $staff = Staff::factory()->create(['status' => 'active']);

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/staff/{$staff->id}", ['status' => 'retired'])
            ->assertUnprocessable()->assertJsonValidationErrors('leaving_date');
    }

    public function test_leaving_date_before_joining_date_is_rejected(): void
    {
        $shift = Shift::factory()->create();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/staff', [
            'name_en' => 'X', 'category' => 'teacher', 'position' => 'teacher', 'status' => 'retired',
            'joining_date' => '2020-01-01', 'leaving_date' => '2019-01-01', 'shift_ids' => [$shift->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('leaving_date');
    }

    public function test_second_active_head_in_same_shift_is_rejected_and_archiving_first_allows_a_new_one(): void
    {
        $shift = Shift::factory()->create();
        $head = Staff::factory()->create(['position' => Staff::POSITION_HEAD, 'status' => 'active']);
        $head->shifts()->attach($shift);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/staff', [
            'name_en' => 'New Head', 'category' => 'teacher', 'position' => 'head', 'status' => 'active', 'shift_ids' => [$shift->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('position');

        // Different shift is fine.
        $otherShift = Shift::factory()->create();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/staff', [
            'name_en' => 'Other Shift Head', 'category' => 'teacher', 'position' => 'head', 'status' => 'active', 'shift_ids' => [$otherShift->id],
        ])->assertCreated();

        // Archive the old head, then a new one for the same shift succeeds.
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/staff/{$head->id}", [
            'status' => 'retired', 'leaving_date' => now()->toDateString(),
        ])->assertOk();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/staff', [
            'name_en' => 'Promoted Head', 'category' => 'teacher', 'position' => 'head', 'status' => 'active', 'shift_ids' => [$shift->id],
        ])->assertCreated();
    }

    public function test_not_found(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/staff/1abc')->assertNotFound();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/staff/999999')
            ->assertNotFound()
            ->assertJson(['message' => 'Record not found.']);
    }
}
