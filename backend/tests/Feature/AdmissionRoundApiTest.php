<?php

namespace Tests\Feature;

use App\Models\AdmissionRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAdmissions;
use Tests\TestCase;

class AdmissionRoundApiTest extends TestCase
{
    use BuildsAdmissions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAdmissions();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'academic_year_id' => $this->year->id,
            'name_en' => 'Admission 2027',
            'name_bn' => 'ভর্তি ২০২৭',
            'opens_at' => '2026-11-01',
            'closes_at' => '2026-11-30',
            'is_published' => true,
            'classes' => [
                ['class_id' => $this->class6->id, 'seats' => 40],
                ['class_id' => $this->class9->id, 'seats' => null],
            ],
        ], $overrides);
    }

    public function test_an_admin_creates_a_round_with_its_classes(): void
    {
        $this->as($this->admin)->postJson('/api/admission-rounds', $this->payload(['instructions_bn' => '<p>নিয়ম</p><script>alert(1)</script>']))
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'academic_year_id', 'name_en', 'name_bn', 'opens_at', 'closes_at', 'is_published', 'is_open', 'classes' => [['class_id', 'seats', 'requires_group', 'class']], 'applications_count'], 'message'])
            ->assertJsonPath('data.is_open', false)
            ->assertJsonPath('data.classes.0.seats', 40)
            ->assertJsonPath('data.applications_count', 0);

        $round = AdmissionRound::latest('id')->firstOrFail();
        $this->assertStringNotContainsString('script', $round->instructions_bn);
        $this->assertSame(2, $round->classes()->count());
    }

    public function test_the_payload_is_validated(): void
    {
        $this->as($this->admin)->postJson('/api/admission-rounds', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['academic_year_id', 'opens_at', 'closes_at', 'classes']);
        $this->as($this->admin)->postJson('/api/admission-rounds', $this->payload(['closes_at' => '2026-10-31']))
            ->assertUnprocessable()->assertJsonValidationErrors(['closes_at']);
        $this->as($this->admin)->postJson('/api/admission-rounds', $this->payload(['name_en' => null, 'name_bn' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors(['name_en']);
        $this->as($this->admin)->postJson('/api/admission-rounds', $this->payload(['classes' => [['class_id' => $this->class6->id], ['class_id' => $this->class6->id]]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['classes.0.class_id']);
        $this->as($this->admin)->postJson('/api/admission-rounds', $this->payload(['classes' => [['class_id' => 9999]]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['classes.0.class_id']);
        $this->as($this->admin)->postJson('/api/admission-rounds', $this->payload(['classes' => [['class_id' => $this->class6->id, 'seats' => 0]]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['classes.0.seats']);
    }

    public function test_a_partial_update_checks_the_window_against_the_saved_dates(): void
    {
        $this->as($this->admin)->putJson("/api/admission-rounds/{$this->round->id}", ['closes_at' => '2000-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors(['closes_at']);
        $this->as($this->admin)->putJson("/api/admission-rounds/{$this->round->id}", ['is_published' => false])
            ->assertOk()->assertJsonPath('data.is_published', false);
    }

    public function test_update_replaces_the_classes(): void
    {
        $this->as($this->admin)->putJson("/api/admission-rounds/{$this->round->id}", ['classes' => [['class_id' => $this->class9->id, 'seats' => 10]]])
            ->assertOk()->assertJsonCount(1, 'data.classes')->assertJsonPath('data.classes.0.seats', 10);
    }

    public function test_a_class_with_applications_cannot_be_dropped(): void
    {
        $this->application();

        $this->as($this->admin)->putJson("/api/admission-rounds/{$this->round->id}", ['classes' => [['class_id' => $this->class9->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['classes']);
        $this->as($this->admin)->putJson("/api/admission-rounds/{$this->round->id}", ['academic_year_id' => \App\Models\AcademicYear::factory()->create(['year' => 2027])->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['academic_year_id']);
    }

    public function test_index_and_show(): void
    {
        $this->application();

        $this->as($this->office)->getJson('/api/admission-rounds')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name_en']], 'links', 'meta' => ['total']])
            ->assertJsonPath('data.0.applications_count', 1);
        $this->as($this->office)->getJson("/api/admission-rounds/{$this->round->id}")->assertOk()->assertJsonPath('data.id', $this->round->id);
        $this->as($this->admin)->getJson('/api/admission-rounds/9999')->assertNotFound();
        $this->as($this->admin)->getJson('/api/admission-rounds/1abc')->assertNotFound();
    }

    public function test_deleting_a_round_with_applications_is_a_conflict(): void
    {
        $this->application();

        $this->as($this->admin)->deleteJson("/api/admission-rounds/{$this->round->id}")->assertStatus(409);

        $empty = AdmissionRound::factory()->create(['academic_year_id' => $this->year->id]);
        $this->as($this->admin)->deleteJson("/api/admission-rounds/{$empty->id}")->assertNoContent();
    }

    public function test_permissions(): void
    {
        $this->getJson('/api/admission-rounds')->assertUnauthorized();
        $this->as($this->teacher)->getJson('/api/admission-rounds')->assertOk();
        $this->as($this->teacher)->postJson('/api/admission-rounds', $this->payload())->assertForbidden();
        $this->as($this->teacher)->putJson("/api/admission-rounds/{$this->round->id}", ['is_published' => false])->assertForbidden();
        $this->as($this->office)->postJson('/api/admission-rounds', $this->payload())->assertCreated();
        $this->as($this->office)->deleteJson("/api/admission-rounds/{$this->round->id}")->assertForbidden();
        $this->as($this->userWithRole('student'))->getJson('/api/admission-rounds')->assertForbidden();
    }
}
