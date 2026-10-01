<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFees;
use Tests\TestCase;

class MyFeesApiTest extends TestCase
{
    use BuildsFees, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFees();
    }

    private function enrolledFor(User $login, ?User $guardian = null): StudentEnrolment
    {
        $enrolment = $this->enrolStudents(1)[0];
        Student::whereKey($enrolment->student_id)->update(['user_id' => $login->id, 'guardian_user_id' => $guardian?->id]);

        return $enrolment;
    }

    public function test_a_student_sees_only_their_own_dues_payments_and_outstanding_total(): void
    {
        $login = $this->userWithRole('student');
        $mine = $this->enrolledFor($login);
        $stranger = $this->enrolStudents(1)[0];
        $this->generateDues(['month' => '2026-09'])->assertOk();
        $this->generateDues(['month' => '2026-10'])->assertOk();
        $this->pay($mine, '1000.00')->assertCreated();
        $this->pay($stranger, '500.00')->assertCreated();

        $this->as($login)->getJson('/api/my/fees')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'student' => ['id', 'student_id', 'name_en', 'name_bn'],
                'outstanding_total',
                'dues' => [['id', 'period', 'amount', 'net_amount', 'paid_amount', 'outstanding_amount', 'status', 'due_date', 'head']],
                'payments' => [['id', 'receipt_no', 'paid_at', 'method', 'amount', 'is_cancelled']],
            ]])
            ->assertJsonPath('data.student.id', $mine->student_id)
            ->assertJsonPath('data.outstanding_total', '600.00')
            ->assertJsonCount(2, 'data.dues')
            ->assertJsonCount(1, 'data.payments')
            ->assertJsonPath('data.payments.0.receipt_no', '2026-000001')
            ->assertJsonPath('data.dues.0.status', 'paid')
            ->assertJsonPath('data.dues.1.outstanding_amount', '600.00');
    }

    public function test_a_cancelled_payment_is_flagged_and_no_longer_reduces_the_outstanding_total(): void
    {
        $login = $this->userWithRole('student');
        $mine = $this->enrolledFor($login);
        $this->generateDues()->assertOk();
        $id = $this->pay($mine, '300.00')->assertCreated()->json('data.id');
        $this->as($this->admin)->postJson("/api/fee-payments/{$id}/cancel", ['reason' => 'x'])->assertOk();

        $this->as($login)->getJson('/api/my/fees')
            ->assertOk()
            ->assertJsonPath('data.outstanding_total', '800.00')
            ->assertJsonPath('data.payments.0.is_cancelled', true);
    }

    public function test_a_student_with_nothing_owed_gets_empty_lists(): void
    {
        $login = $this->userWithRole('student');
        $this->enrolledFor($login);

        $this->as($login)->getJson('/api/my/fees')
            ->assertOk()
            ->assertJsonPath('data.outstanding_total', '0.00')
            ->assertJsonPath('data.dues', [])
            ->assertJsonPath('data.payments', []);
    }

    public function test_a_login_without_a_student_record_gets_404(): void
    {
        $this->as($this->userWithRole('student'))->getJson('/api/my/fees')->assertNotFound();
    }

    public function test_a_guardian_sees_each_of_their_children_and_nobody_elses(): void
    {
        $guardian = $this->userWithRole('parent');
        $first = $this->enrolledFor($this->userWithRole('student'), $guardian);
        $second = $this->enrolledFor($this->userWithRole('student'), $guardian);
        $other = $this->enrolledFor($this->userWithRole('student'), $this->userWithRole('parent'));
        $this->generateDues()->assertOk();
        $this->pay($first, '200.00')->assertCreated();

        $this->as($guardian)->getJson("/api/my/children/{$first->student_id}/fees")
            ->assertOk()
            ->assertJsonPath('data.student.id', $first->student_id)
            ->assertJsonPath('data.outstanding_total', '600.00')
            ->assertJsonCount(1, 'data.payments');
        $this->as($guardian)->getJson("/api/my/children/{$second->student_id}/fees")
            ->assertOk()->assertJsonPath('data.outstanding_total', '800.00')->assertJsonCount(0, 'data.payments');

        // Another family's child, and a student who does not exist, are both 403.
        $this->as($guardian)->getJson("/api/my/children/{$other->student_id}/fees")->assertForbidden();
        $this->as($guardian)->getJson('/api/my/children/999999/fees')->assertForbidden();
        $this->as($guardian)->getJson('/api/my/children/1abc/fees')->assertNotFound();
    }

    public function test_the_own_fee_routes_are_role_scoped(): void
    {
        $student = $this->userWithRole('student');
        $guardian = $this->userWithRole('parent');
        $child = $this->enrolledFor($this->userWithRole('student'), $guardian);

        $this->getJson('/api/my/fees')->assertUnauthorized();
        $this->getJson("/api/my/children/{$child->student_id}/fees")->assertUnauthorized();
        $this->as($guardian)->getJson('/api/my/fees')->assertForbidden();
        $this->as($student)->getJson("/api/my/children/{$child->student_id}/fees")->assertForbidden();
        $this->as($this->teacher)->getJson('/api/my/fees')->assertForbidden();
        $this->as($this->office)->getJson('/api/my/fees')->assertForbidden();
        $this->as($this->teacher)->getJson("/api/my/children/{$child->student_id}/fees")->assertForbidden();
    }
}
