<?php

namespace Tests\Feature;

use App\Models\FeeDue;
use App\Models\FeeHead;
use App\Models\FeePayment;
use App\Models\FeeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsFees;
use Tests\TestCase;

class FeeAuthorizationTest extends TestCase
{
    use BuildsFees, RefreshDatabase;

    private int $studentId;

    private int $paymentId;

    private int $dueId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFees();
        $enrolment = $this->enrolStudents(1)[0];
        $this->studentId = $enrolment->student_id;
        $this->generateDues()->assertOk();
        $this->dueId = FeeDue::firstOrFail()->id;
        $this->paymentId = $this->pay($enrolment, '100.00')->assertCreated()->json('data.id');
    }

    /**
     * Every fee endpoint with a valid-looking payload, so a refusal can only be the
     * permission check.
     *
     * @return list<array{string, string, array<string, mixed>}>
     */
    private function endpoints(): array
    {
        $rateId = FeeRate::firstOrFail()->id;
        $headId = $this->tuition->id;

        return [
            ['GET', '/api/fee-heads', []],
            ['GET', "/api/fee-heads/{$headId}", []],
            ['POST', '/api/fee-heads', ['name_en' => 'X', 'code' => 'XX', 'kind' => 'monthly']],
            ['PUT', "/api/fee-heads/{$headId}", ['name_en' => 'Y']],
            ['DELETE', '/api/fee-heads/'.FeeHead::factory()->create()->id, []],
            ['GET', '/api/fee-rates', []],
            ['GET', "/api/fee-rates/{$rateId}", []],
            ['POST', '/api/fee-rates', ['fee_head_id' => $headId, 'class_id' => $this->class9->id, 'academic_year_id' => $this->year->id, 'amount' => '1']],
            ['PUT', "/api/fee-rates/{$rateId}", ['amount' => '1']],
            ['DELETE', '/api/fee-rates/'.$this->rate($this->tuition, $this->class9, '5')->id, []],
            ['GET', '/api/fee-waivers', []],
            ['POST', '/api/fee-waivers', ['student_id' => $this->studentId, 'academic_year_id' => $this->year->id, 'fee_head_id' => $headId, 'percent' => 5]],
            ['GET', '/api/fee-dues', []],
            ['POST', '/api/fee-dues/generate', ['academic_year_id' => $this->year->id, 'month' => '2026-10']],
            ['GET', '/api/fee-payments', []],
            ['GET', "/api/fee-payments/{$this->paymentId}", []],
            ['POST', '/api/fee-payments', ['student_id' => $this->studentId, 'amount' => '10', 'method' => 'cash']],
            ['POST', "/api/fee-payments/{$this->paymentId}/cancel", ['reason' => 'x']],
            ['GET', "/api/fee-reports/dues?section_id={$this->section10->id}", []],
            ['GET', '/api/fee-reports/collection?from=2026-10-01&to=2026-10-31', []],
            ['GET', "/api/fee-reports/students/{$this->studentId}/ledger", []],
        ];
    }

    public function test_a_guest_gets_401_on_every_fee_endpoint(): void
    {
        // setUp() signed in as the office clerk to collect a payment; sign out.
        foreach ($this->endpoints() as [$method, $uri, $body]) {
            $this->app['auth']->forgetGuards();
            $this->json($method, $uri, $body)->assertUnauthorized();
        }
    }

    public function test_a_teacher_gets_403_on_every_fee_endpoint(): void
    {
        foreach ($this->endpoints() as [$method, $uri, $body]) {
            $this->as($this->teacher)->json($method, $uri, $body)->assertForbidden();
        }
    }

    public function test_a_student_and_a_guardian_get_403_on_every_fee_endpoint(): void
    {
        foreach (['student', 'parent'] as $role) {
            $user = $this->userWithRole($role);

            foreach ($this->endpoints() as [$method, $uri, $body]) {
                $this->as($user)->json($method, $uri, $body)->assertForbidden();
            }
        }
    }

    public function test_the_student_and_parent_roles_no_longer_hold_view_fees(): void
    {
        foreach (['student', 'parent', 'teacher'] as $role) {
            $this->assertFalse(Role::findByName($role, 'web')->hasPermissionTo('view-fees'), $role);
        }
        $this->assertSame([], Role::findByName('teacher', 'web')->permissions->filter(fn ($p) => Str::endsWith($p->name, '-fees'))->pluck('name')->all());
    }

    public function test_the_office_role_reads_collects_and_generates_but_cannot_edit_or_delete(): void
    {
        $office = $this->office;

        foreach ([['GET', '/api/fee-heads'], ['GET', '/api/fee-rates'], ['GET', '/api/fee-waivers'], ['GET', '/api/fee-dues'], ['GET', '/api/fee-payments'], ['GET', "/api/fee-payments/{$this->paymentId}"]] as [$method, $uri]) {
            $this->as($office)->json($method, $uri)->assertOk();
        }
        $this->as($office)->postJson('/api/fee-dues/generate', ['academic_year_id' => $this->year->id, 'month' => '2026-10'])->assertOk();
        $this->as($office)->postJson('/api/fee-payments', ['student_id' => $this->studentId, 'amount' => '10', 'method' => 'cash'])->assertCreated();
        $this->as($office)->postJson('/api/fee-waivers', ['student_id' => $this->studentId, 'academic_year_id' => $this->year->id, 'fee_head_id' => $this->tuition->id, 'percent' => 5])->assertCreated();

        // Admin-only: editing heads and rates, deleting, and cancelling a receipt.
        $head = FeeHead::factory()->create();
        $this->as($office)->putJson("/api/fee-heads/{$this->tuition->id}", ['name_en' => 'Y'])->assertForbidden();
        $this->as($office)->deleteJson("/api/fee-heads/{$head->id}")->assertForbidden();
        $this->as($office)->putJson('/api/fee-rates/'.FeeRate::firstOrFail()->id, ['amount' => '1'])->assertForbidden();
        $this->as($office)->deleteJson('/api/fee-rates/'.FeeRate::firstOrFail()->id)->assertForbidden();
        $this->as($office)->postJson("/api/fee-payments/{$this->paymentId}/cancel", ['reason' => 'x'])->assertForbidden();

        $this->assertDatabaseHas('fee_heads', ['id' => $head->id, 'deleted_at' => null]);
        $this->assertNull(FeePayment::findOrFail($this->paymentId)->cancelled_at);
    }

    public function test_the_admin_can_do_all_of_it(): void
    {
        $this->as($this->admin)->postJson("/api/fee-payments/{$this->paymentId}/cancel", ['reason' => 'x'])->assertOk();
        $this->as($this->admin)->putJson("/api/fee-heads/{$this->tuition->id}", ['name_en' => 'Y'])->assertOk();
        $this->as($this->admin)->deleteJson('/api/fee-heads/'.FeeHead::factory()->create()->id)->assertNoContent();
    }

    public function test_the_old_fee_routes_are_gone(): void
    {
        foreach (['/api/fee-types', '/api/fee-structures', "/api/fee-payments/student/{$this->studentId}", '/api/fee-payments/receipt/1'] as $uri) {
            $this->as($this->admin)->getJson($uri)->assertNotFound();
        }
        $this->as($this->admin)->postJson('/api/fee-types', [])->assertNotFound();
        $this->as($this->admin)->deleteJson("/api/fee-payments/{$this->paymentId}")->assertStatus(405);
        $this->as($this->admin)->putJson("/api/fee-payments/{$this->paymentId}", [])->assertStatus(405);
    }

    public function test_every_fee_route_has_a_permission_check(): void
    {
        $unguarded = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => Str::startsWith($route->uri(), ['api/fee-', 'api/my/fees', 'api/my/children/{student}/fees']))
            ->reject(fn ($route) => collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && Str::startsWith($m, ['permission:', 'role:'])))
            ->map(fn ($route) => $route->uri())
            ->all();

        $this->assertSame([], $unguarded);
    }
}
