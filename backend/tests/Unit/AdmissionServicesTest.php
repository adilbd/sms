<?php

namespace Tests\Unit;

use App\Models\AdmissionApplication;
use App\Models\AdmissionRound;
use App\Models\Section;
use App\Models\User;
use App\Repositories\Contracts\AdmissionApplicationRepositoryInterface;
use App\Repositories\Contracts\AdmissionRoundRepositoryInterface;
use App\Services\AdmissionApplicationService;
use App\Services\AdmissionRoundService;
use App\Services\AdmissionService;
use App\Services\StudentService;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The admission services against mocked repository interfaces, no database rows.
 */
class AdmissionServicesTest extends TestCase
{
    private function application(string $status): AdmissionApplication
    {
        $application = new AdmissionApplication(['status' => $status, 'class_id' => 6]);
        $application->id = 11;

        return $application;
    }

    /** @return array<string, list<string>> */
    private function errors(callable $call): array
    {
        try {
            $call();
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            return $e->errors();
        }
    }

    public function test_an_illegal_transition_is_a_validation_error_and_writes_nothing(): void
    {
        $application = $this->application('submitted');
        $this->mock(AdmissionApplicationRepositoryInterface::class, function (MockInterface $m) use ($application) {
            $m->shouldReceive('lockForUpdate')->andReturn($application);
            $m->shouldNotReceive('update');
        });

        $errors = $this->errors(fn () => app(AdmissionApplicationService::class)->changeStatus($application, ['status' => 'approved'], new User));

        $this->assertArrayHasKey('status', $errors);
    }

    public function test_approving_a_full_class_is_a_409(): void
    {
        $application = $this->application('under_review');
        $round = new AdmissionRound;
        $round->id = 3;
        $this->mock(AdmissionApplicationRepositoryInterface::class, function (MockInterface $m) use ($application, $round) {
            $m->shouldReceive('lockForUpdate')->andReturn($application);
            $m->shouldReceive('loadDetail')->andReturn($application->setRelation('round', $round));
            $m->shouldReceive('countSeatsTaken')->with(3, 6, 11)->andReturn(30);
            $m->shouldNotReceive('update');
        });
        $this->mock(AdmissionRoundRepositoryInterface::class, function (MockInterface $m) use ($round) {
            $m->shouldReceive('lockForUpdate')->once()->andReturn($round);
            $m->shouldReceive('seatsFor')->with($round, 6)->andReturn(30);
        });

        try {
            app(AdmissionApplicationService::class)->changeStatus($application, ['status' => 'approved'], new User);
            $this->fail('Expected a 409.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_convert_refuses_an_application_that_is_not_approved(): void
    {
        $application = $this->application('under_review');
        $this->mock(AdmissionApplicationRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('lockForUpdate')->andReturn($application));
        $this->mock(StudentService::class, fn (MockInterface $m) => $m->shouldNotReceive('create'));

        try {
            app(AdmissionApplicationService::class)->convert($application, ['section_id' => 1, 'password' => 'x']);
            $this->fail('Expected a 409.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_convert_refuses_a_section_of_another_class(): void
    {
        $application = $this->application('approved');
        $section = new Section(['class_id' => 9]);
        $this->mock(AdmissionApplicationRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('lockForUpdate')->andReturn($application));
        $this->mock(\App\Repositories\Contracts\SectionRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('findOrFail')->with(5)->andReturn($section));
        $this->mock(StudentService::class, fn (MockInterface $m) => $m->shouldNotReceive('create'));

        $errors = $this->errors(fn () => app(AdmissionApplicationService::class)->convert($application, ['section_id' => 5, 'password' => 'x']));

        $this->assertArrayHasKey('section_id', $errors);
    }

    public function test_a_round_cannot_close_before_it_opens(): void
    {
        $this->mock(AdmissionRoundRepositoryInterface::class, fn (MockInterface $m) => $m->shouldNotReceive('create'));

        $errors = $this->errors(fn () => app(AdmissionRoundService::class)->create([
            'academic_year_id' => 1, 'name_en' => 'R', 'opens_at' => '2026-11-10', 'closes_at' => '2026-11-01', 'classes' => [['class_id' => 1]],
        ]));

        $this->assertArrayHasKey('closes_at', $errors);
    }

    public function test_deleting_a_round_with_applications_is_a_409(): void
    {
        $round = new AdmissionRound;
        $this->mock(AdmissionRoundRepositoryInterface::class, function (MockInterface $m) use ($round) {
            $m->shouldReceive('hasApplications')->with($round)->andReturn(true);
            $m->shouldNotReceive('delete');
        });

        try {
            app(AdmissionRoundService::class)->delete($round);
            $this->fail('Expected a 409.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_submit_rejects_a_duplicate_birth_registration_before_storing_files(): void
    {
        $round = new AdmissionRound;
        $round->id = 3;
        $round->setRelation('classes', collect([(new \App\Models\AdmissionRoundClass(['class_id' => 6]))->setRelation('class', new \App\Models\Classes(['number' => 6]))]));
        $round->setRelation('academicYear', new \App\Models\AcademicYear(['year' => 2026]));
        $this->mock(AdmissionRoundRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('findOpen')->with(3)->andReturn($round));
        $this->mock(AdmissionApplicationRepositoryInterface::class, function (MockInterface $m) {
            $m->shouldReceive('existsForBirthRegistration')->with(3, '20140123456789012')->andReturn(true);
            $m->shouldNotReceive('create');
        });

        $errors = $this->errors(fn () => app(AdmissionService::class)->submit(3, ['class_id' => 6, 'birth_registration_number' => '20140123456789012'], [], '203.0.113.50'));

        $this->assertSame([AdmissionService::DUPLICATE_MESSAGE], $errors['birth_registration_number']);
    }
}
