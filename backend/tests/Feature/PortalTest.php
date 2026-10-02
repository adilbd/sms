<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\ExamSubject;
use App\Models\FeePayment;
use App\Models\Holiday;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\User;
use App\Support\LoginTrust;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\BuildsFees;
use Tests\Concerns\BuildsRoutines;
use Tests\TestCase;

/**
 * The student and guardian portal at /portal: session login, access, the dashboard and
 * pages, and that the pages agree with /api/my/*. "Now" is 2026-10-15 (Asia/Dhaka), a
 * Thursday; Fridays are the weekly holiday.
 */
class PortalTest extends TestCase
{
    use BuildsFees, BuildsRoutines, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFees();
        $this->setUpRoutines();
    }

    private function login(string $username, string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['username' => $username, 'password' => Hash::make('secret-pass'), ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * An enrolled student, optionally linked to a student login and a guardian login.
     *
     * @return array{0: StudentEnrolment, 1: ?User}
     */
    private function enrolled(?User $login = null, ?User $guardian = null, array $student = []): StudentEnrolment
    {
        $enrolment = $this->enrol($this->section10, null, null, (int) StudentEnrolment::max('roll_number') + 1, $student);
        Student::whereKey($enrolment->student_id)->update(['user_id' => $login?->id, 'guardian_user_id' => $guardian?->id]);

        return $enrolment;
    }

    private function web(User $user)
    {
        return $this->actingAs($user, 'web');
    }

    private function publish(StudentEnrolment $enrolment, string $gpa = '4.50', string $status = Exam::STATUS_PUBLISHED): ExamResult
    {
        $exam = Exam::factory()->create([
            'academic_year_id' => $this->year->id, 'name_en' => 'Half Yearly Exam', 'name_bn' => 'অর্ধবার্ষিক পরীক্ষা',
            'status' => $status, 'published_at' => $status === Exam::STATUS_PUBLISHED ? now() : null,
        ]);

        return ExamResult::factory()->create([
            'exam_id' => $exam->id, 'student_id' => $enrolment->student_id, 'enrolment_id' => $enrolment->id,
            'class_id' => $enrolment->class_id, 'section_id' => $enrolment->section_id, 'gpa' => $gpa, 'grade' => 'A',
        ]);
    }

    private function mark(StudentEnrolment $enrolment, string $date, string $status): void
    {
        Attendance::factory()->create(['enrolment_id' => $enrolment->id, 'date' => $date, 'status' => $status]);
    }

    // Login

    public function test_a_student_signs_in_with_their_student_id(): void
    {
        $login = $this->login('20260001', 'student');
        $this->enrolled($login);

        $this->get('/portal/login')->assertOk();
        $this->post('/portal/login', ['login' => '20260001', 'password' => 'secret-pass'])->assertRedirect('/portal');

        $this->assertAuthenticatedAs($login, 'web');
        $this->get('/portal')->assertOk();
        $this->assertSame(0, $login->tokens()->count(), 'A session login must not issue an API token');
    }

    public function test_a_guardian_signs_in_with_their_mobile_given_with_the_country_code(): void
    {
        $guardian = $this->login('01712345678', 'parent');
        $this->enrolled(null, $guardian);

        $this->post('/portal/login', ['login' => '+8801712345678', 'password' => 'secret-pass'])->assertRedirect('/portal');

        $this->assertAuthenticatedAs($guardian, 'web');
    }

    public function test_a_wrong_password_shows_the_generic_error_and_signs_nobody_in(): void
    {
        $this->login('20260001', 'student');

        $this->from('/portal/login')->post('/portal/login', ['login' => '20260001', 'password' => 'wrong'])
            ->assertRedirect('/portal/login')->assertSessionHasErrors('login');
        $this->get('/portal/login')->assertSee('লগইন ব্যর্থ হয়েছে')->assertDontSee('credentials');
        $this->assertGuest('web');
    }

    public function test_an_inactive_account_is_refused(): void
    {
        $this->login('20260001', 'student', ['is_active' => false]);

        $this->post('/portal/login', ['login' => '20260001', 'password' => 'secret-pass'])->assertSessionHasErrors('login');
        $this->assertGuest('web');
    }

    public function test_a_staff_user_is_redirected_to_the_admin_login_without_a_session(): void
    {
        $this->post('/portal/login', ['login' => $this->teacher->email, 'password' => 'password'])
            ->assertRedirect('/admin/login?from=portal');

        $this->assertGuest('web');
        $this->get('/portal')->assertRedirect('/portal/login');
    }

    public function test_the_login_is_throttled_with_retry_after(): void
    {
        $this->login('20260001', 'student');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/portal/login', ['login' => '20260001', 'password' => 'wrong'])->assertSessionHasErrors('login');
        }

        $this->post('/portal/login', ['login' => '20260001', 'password' => 'secret-pass'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
        $this->assertGuest('web');
    }

    public function test_the_portal_login_shares_the_account_lock_with_the_api(): void
    {
        $user = $this->login('20260001', 'student');
        // Ten failed passwords against the account from other IPs lock it for this IP too.
        RateLimiter::hit('login-user:'.$user->id, 60);
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit('login-user:'.$user->id, 60);
        }

        $this->post('/portal/login', ['login' => '20260001', 'password' => 'secret-pass'])
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->postJson('/api/login', ['login' => '20260001', 'password' => 'secret-pass'])->assertStatus(429);
    }

    public function test_a_login_without_a_csrf_token_gets_419(): void
    {
        $this->login('20260001', 'student');
        // The framework skips CSRF verification while the environment is "testing".
        $this->app['env'] = 'local';

        $this->post('/portal/login', ['login' => '20260001', 'password' => 'secret-pass'])->assertStatus(419);
        $this->assertGuest('web');
    }

    public function test_logout_ends_the_session(): void
    {
        $login = $this->login('20260001', 'student');
        $this->enrolled($login);

        $this->post('/portal/login', ['login' => '20260001', 'password' => 'secret-pass']);
        $this->assertAuthenticated('web');

        $this->post('/portal/logout')->assertRedirect('/portal/login');

        $this->assertGuest('web');
        $this->get('/portal')->assertRedirect('/portal/login');
    }

    // Access

    public static function portalUris(): array
    {
        return [
            'dashboard' => ['/portal'], 'profile' => ['/portal/profile'], 'results' => ['/portal/results'],
            'result' => ['/portal/results/1'], 'attendance' => ['/portal/attendance'], 'fees' => ['/portal/fees'],
            'receipt' => ['/portal/fees/receipts/1'], 'exams' => ['/portal/exams'], 'routine' => ['/portal/routine'], 'homework' => ['/portal/homework'],
        ];
    }

    /** @dataProvider portalUris */
    public function test_a_guest_is_redirected_to_the_login_from_every_page(string $uri): void
    {
        $this->get($uri)->assertRedirect('/portal/login')
            ->assertHeader('X-Robots-Tag', 'noindex');
    }

    /** @dataProvider portalUris */
    public function test_a_staff_session_cannot_open_portal_pages(string $uri): void
    {
        $this->web($this->teacher)->get($uri)->assertForbidden();
        $this->flushSession();
        $this->web($this->admin)->get($uri)->assertForbidden();
    }

    public function test_a_deactivated_user_with_a_session_is_signed_out(): void
    {
        $login = $this->login('20260001', 'student', ['is_active' => false]);
        $this->enrolled($login);

        $this->web($login)->get('/portal')->assertRedirect('/portal/login');
    }

    public function test_every_response_is_no_store_and_noindex(): void
    {
        $login = $this->login('20260001', 'student');
        $enrolment = $this->enrolled($login);
        $result = $this->publish($enrolment);

        $uris = ['/portal/login', '/portal', '/portal/profile', '/portal/results', "/portal/results/{$result->exam_id}",
            '/portal/attendance', '/portal/fees', '/portal/exams', '/portal/routine', '/portal/homework', '/portal/results/99999'];

        foreach ($uris as $uri) {
            $response = $this->web($login)->get($uri);

            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'), $uri);
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'), $uri);
            $response->assertHeader('X-Robots-Tag', 'noindex');
        }

        $this->post('/portal/login', ['login' => 'nobody', 'password' => 'x'])
            ->assertHeader('X-Robots-Tag', 'noindex');
    }

    // Student

    public function test_the_dashboard_numbers_match_the_api(): void
    {
        $login = $this->login('20260001', 'student');
        $enrolment = $this->enrolled($login, null, ['name_bn' => 'রহিম উদ্দিন', 'name_en' => 'Rahim Uddin']);
        $this->enrolled(); // a stranger with dues
        $this->generateDues(['month' => '2026-10'])->assertOk();
        $this->pay($enrolment, '300.00')->assertCreated();
        $this->publish($enrolment, '4.50');
        foreach ([['2026-10-01', 'present'], ['2026-10-03', 'absent'], ['2026-10-04', 'late'], ['2026-10-05', 'leave'], ['2026-10-07', 'present'], ['2026-10-08', 'present']] as [$date, $status]) {
            $this->mark($enrolment, $date, $status);
        }

        $student = $this->as($login)->getJson('/api/my/student')->assertOk()->json('data');
        $results = $this->as($login)->getJson('/api/my/results')->assertOk()->json('data');
        $attendance = $this->as($login)->getJson('/api/my/attendance')->assertOk()->json('data');
        $fees = $this->as($login)->getJson('/api/my/fees')->assertOk()->json('data');

        $this->assertSame('4.50', $results[0]['gpa']);
        $this->assertSame('500.00', $fees['outstanding_total']);

        $html = $this->web($login)->get('/portal')->assertOk()->getContent();

        $this->assertStringContainsString('রহিম উদ্দিন', $html);
        $this->assertStringContainsString(\App\Support\BanglaNumber::format($student['student_id']), $html);
        $this->assertStringContainsString('জিপিএ '.\App\Support\BanglaNumber::format($results[0]['gpa']), $html);
        $this->assertStringContainsString(\App\Support\Money::display($fees['outstanding_total']), $html);
        $this->assertStringContainsString(\App\Support\BanglaNumber::format($attendance['percentage']).'%', $html);
        $this->assertSame('30.77', $attendance['percentage']);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_a_student_ignores_a_student_query_for_another_student(): void
    {
        $login = $this->login('20260001', 'student');
        $this->enrolled($login, null, ['name_bn' => 'আমার নাম']);
        $other = $this->enrolled(null, null, ['name_bn' => 'অন্যের নাম']);

        $this->web($login)->get('/portal?student='.$other->student_id)
            ->assertOk()->assertSee('আমার নাম')->assertDontSee('অন্যের নাম');
        $this->web($login)->get('/portal/fees?student='.$other->student_id)->assertOk();
    }

    public function test_a_login_without_a_student_record_gets_404(): void
    {
        $this->web($this->login('20260001', 'student'))->get('/portal')->assertNotFound();
    }

    // Guardian

    public function test_a_guardian_with_two_children_gets_a_switcher_and_each_childs_data(): void
    {
        $guardian = $this->login('01712345678', 'parent');
        $first = $this->enrolled(null, $guardian, ['name_bn' => 'প্রথম সন্তান']);
        $second = $this->enrolled(null, $guardian, ['name_bn' => 'দ্বিতীয় সন্তান']);
        $this->generateDues(['month' => '2026-10'])->assertOk();
        $this->pay($second, '200.00')->assertCreated();

        $page = $this->web($guardian)->get('/portal/fees?student='.$first->student_id)->assertOk();
        $page->assertSee('সন্তান বেছে নিন', false)->assertSee('প্রথম সন্তান');
        $this->assertStringContainsString(\App\Support\Money::display('800.00'), $page->getContent());

        $page = $this->web($guardian)->get('/portal/fees?student='.$second->student_id)->assertOk();
        $this->assertStringContainsString(\App\Support\Money::display('600.00'), $page->getContent());
        $page->assertSee(\App\Support\BanglaNumber::format(FeePayment::first()->receipt_no));
    }

    public function test_the_chosen_child_is_remembered_in_the_session(): void
    {
        $guardian = $this->login('01712345678', 'parent');
        $this->enrolled(null, $guardian, ['name_bn' => 'প্রথম সন্তান', 'student_id' => '20260001']);
        $second = $this->enrolled(null, $guardian, ['name_bn' => 'দ্বিতীয় সন্তান', 'student_id' => '20260002']);

        $this->web($guardian)->get('/portal')->assertSee('প্রথম সন্তান');
        $this->get('/portal?student='.$second->student_id)->assertSee('দ্বিতীয় সন্তান');
        $this->get('/portal/profile')->assertSee('দ্বিতীয় সন্তান')->assertSessionHas('portal.student_id', $second->student_id);
    }

    public function test_another_familys_child_is_403_and_an_unknown_student_too(): void
    {
        $guardian = $this->login('01712345678', 'parent');
        $this->enrolled(null, $guardian);
        $stranger = $this->enrolled(null, $this->login('01812345678', 'parent'));

        foreach (['/portal', '/portal/fees', '/portal/results', '/portal/attendance', '/portal/exams', '/portal/profile'] as $uri) {
            $this->web($guardian)->get($uri.'?student='.$stranger->student_id)->assertForbidden();
        }
        $this->web($guardian)->get('/portal?student=999999')->assertForbidden();
        $this->web($guardian)->get('/portal?student=abc')->assertSessionHasErrors('student');
    }

    public function test_a_guardian_with_one_child_sees_no_switcher(): void
    {
        $guardian = $this->login('01712345678', 'parent');
        $this->enrolled(null, $guardian);

        $this->web($guardian)->get('/portal')->assertOk()->assertDontSee('সন্তান বেছে নিন', false);
    }

    public function test_a_guardian_without_children_gets_404(): void
    {
        $this->web($this->login('01712345678', 'parent'))->get('/portal')->assertNotFound();
    }

    // Results

    public function test_only_published_exams_are_listed_and_an_unpublished_marksheet_is_404(): void
    {
        $login = $this->login('20260001', 'student');
        $enrolment = $this->enrolled($login);
        $published = $this->publish($enrolment, '4.50');
        $draft = $this->publish($enrolment, '3.00', Exam::STATUS_PROCESSED);
        $stranger = $this->publish($this->enrolled());

        $this->web($login)->get('/portal/results')->assertOk()
            ->assertSee(route('portal.result', $published->exam_id), false)
            ->assertDontSee(route('portal.result', $draft->exam_id), false)
            ->assertDontSee(route('portal.result', $stranger->exam_id), false);

        $this->web($login)->get("/portal/results/{$draft->exam_id}")->assertNotFound();
        $this->web($login)->get("/portal/results/{$stranger->exam_id}")->assertNotFound();
    }

    public function test_the_marksheet_renders_in_bangla_and_english_with_the_page_options(): void
    {
        $login = $this->login('20260001', 'student');
        $result = $this->publish($this->enrolled($login), '4.50');

        $bn = $this->web($login)->get("/portal/results/{$result->exam_id}")->assertOk();
        $bn->assertSee('একাডেমিক ট্রান্সক্রিপ্ট')->assertSee('@page { size: A4 portrait;', false);
        $this->assertSame(1, substr_count($bn->getContent(), '<h1'));

        $en = $this->web($login)->get("/portal/results/{$result->exam_id}?language=en&page=legal&orientation=landscape")->assertOk();
        $en->assertSee('Academic transcript')->assertSee('@page { size: legal landscape;', false);

        $this->web($login)->get("/portal/results/{$result->exam_id}?language=fr")->assertSessionHasErrors('language');
    }

    // Attendance

    public function test_the_attendance_grid_shows_holidays_and_weekly_holidays_and_matches_the_api(): void
    {
        $login = $this->login('20260001', 'student');
        $enrolment = $this->enrolled($login);
        Holiday::factory()->create(['date' => '2026-10-06', 'name_bn' => 'দুর্গাপূজা', 'name_en' => 'Durga Puja', 'academic_year_id' => $this->year->id]);
        foreach ([['2026-10-01', 'present'], ['2026-10-03', 'absent'], ['2026-10-04', 'late'], ['2026-10-05', 'leave'], ['2026-10-07', 'present'], ['2026-10-08', 'present']] as [$date, $status]) {
            $this->mark($enrolment, $date, $status);
        }

        $api = $this->as($login)->getJson('/api/my/attendance?month=2026-10')->assertOk()->json('data');
        $this->assertArrayNotHasKey('non_school_days', $api);

        $html = $this->web($login)->get('/portal/attendance?month=2026-10')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-date="2026-10-06"\s+data-status="holiday"[^>]*>.*?দুর্গাপূজা/s', $html);
        $this->assertMatchesRegularExpression('/data-date="2026-10-02"\s+data-status="weekly"[^>]*>.*?সাপ্তাহিক ছুটি/s', $html);
        $this->assertMatchesRegularExpression('/data-date="2026-10-03"\s+data-status="absent"/', $html);
        $this->assertMatchesRegularExpression('/data-date="2026-10-04"\s+data-status="late"/', $html);
        $this->assertMatchesRegularExpression('/data-date="2026-10-05"\s+data-status="leave"/', $html);
        $this->assertMatchesRegularExpression('/data-date="2026-10-01"\s+data-status="present"/', $html);
        $this->assertStringContainsString('অক্টোবর ২০২৬', $html);
        $this->assertStringContainsString('month=2026-09', $html);
        $this->assertStringContainsString('month=2026-11', $html);
        foreach (['present', 'absent', 'late', 'leave'] as $status) {
            $this->assertMatchesRegularExpression('/data-testid="total-'.$status.'">'.\App\Support\BanglaNumber::format($api['totals'][$status]).'</', $html);
        }
        $this->assertStringContainsString(\App\Support\BanglaNumber::format($api['percentage']).'%', $html);
        $this->assertStringContainsString(\App\Support\BanglaNumber::format($api['year_to_date']['percentage']).'%', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_a_bad_month_is_rejected(): void
    {
        $login = $this->login('20260001', 'student');
        $this->enrolled($login);

        $this->web($login)->get('/portal/attendance?month=2026-13')->assertSessionHasErrors('month');
        $this->web($login)->get('/portal/attendance?month[]=x')->assertSessionHasErrors('month');
    }

    // Fees

    public function test_the_fees_page_matches_the_api_and_the_receipt_is_own_only(): void
    {
        $login = $this->login('20260001', 'student');
        $mine = $this->enrolled($login);
        $other = $this->enrolled();
        $this->generateDues(['month' => '2026-09'])->assertOk();
        $this->generateDues(['month' => '2026-10'])->assertOk();
        $myPayment = $this->pay($mine, '1000.00')->assertCreated()->json('data');
        $theirPayment = $this->pay($other, '500.00')->assertCreated()->json('data');

        $api = $this->as($login)->getJson('/api/my/fees')->assertOk()->json('data');
        $this->assertSame('600.00', $api['outstanding_total']);

        $page = $this->web($login)->get('/portal/fees')->assertOk();
        $html = $page->getContent();
        $this->assertStringContainsString(\App\Support\Money::display($api['outstanding_total']), $html);
        foreach ($api['dues'] as $due) {
            $this->assertMatchesRegularExpression('/data-due="'.$due['id'].'".*?'.preg_quote(\App\Support\Money::display($due['outstanding_amount']), '/').'/s', $html);
        }
        $page->assertSee(\App\Support\BanglaNumber::format($myPayment['receipt_no']))
            ->assertDontSee(\App\Support\BanglaNumber::format($theirPayment['receipt_no']))
            ->assertSee(route('portal.receipt', $myPayment['id']), false);

        $this->web($login)->get(route('portal.receipt', $myPayment['id']))->assertOk()
            ->assertSee(\App\Support\BanglaNumber::format($myPayment['receipt_no']))
            ->assertSee('ফি আদায়ের রসিদ');
        $this->web($login)->get(route('portal.receipt', $theirPayment['id']))->assertNotFound();
        $this->web($login)->get(route('portal.receipt', 999999))->assertNotFound();
    }

    public function test_a_guardians_receipt_is_limited_to_the_chosen_child(): void
    {
        $guardian = $this->login('01712345678', 'parent');
        $first = $this->enrolled(null, $guardian);
        $second = $this->enrolled(null, $guardian);
        $this->generateDues()->assertOk();
        $paid = $this->pay($second, '100.00')->assertCreated()->json('data.id');

        $this->web($guardian)->get(route('portal.receipt', ['payment' => $paid, 'student' => $first->student_id]))->assertNotFound();
        $this->web($guardian)->get(route('portal.receipt', ['payment' => $paid, 'student' => $second->student_id]))->assertOk();
    }

    public function test_the_receipt_has_a_fully_bangla_date_and_labels_cash_as_taka(): void
    {
        $login = $this->login('20260001', 'student');
        $enrolment = $this->enrolled($login);
        $this->generateDues()->assertOk();
        $id = $this->as($this->admin)->postJson('/api/fee-payments', [
            'student_id' => $enrolment->student_id, 'amount' => '300.00', 'method' => 'cash', 'paid_at' => '2026-10-01T09:07:00Z',
        ])->assertCreated()->json('data.id');
        $nagad = $this->pay($enrolment, '100.00', 'nagad', ['transaction_id' => 'TX123456'])->assertCreated()->json('data.id');

        $cash = $this->web($login)->get(route('portal.receipt', $id))->assertOk();
        $cash->assertSee('১ অক্টোবর ২০২৬, অপরাহ্ন ৩:০৭')->assertSee('নগদ টাকা');
        $this->assertSame(1, substr_count($cash->getContent(), '<h1'));
        $this->assertDoesNotMatchRegularExpression('/\b(Oct|pm|am)\b/i', strip_tags(explode('data-testid="receipt-date"', $cash->getContent())[1] ?? ''));

        $this->web($login)->get(route('portal.receipt', $nagad))->assertSee('নগদ (মোবাইল)');
        $this->web($login)->get(route('portal.receipt', ['payment' => $id, 'language' => 'en', 'page' => 'a5']))
            ->assertSee('Fee Receipt')->assertSee('@page { size: A5 landscape;', false);
    }

    // Exams

    public function test_the_exam_schedule_lists_upcoming_exams_for_the_students_subjects_only(): void
    {
        $login = $this->login('20260001', 'student');
        $this->enrolled($login);
        $upcoming = Exam::factory()->create(['academic_year_id' => $this->year->id, 'name_bn' => 'বার্ষিক পরীক্ষা', 'start_date' => '2026-11-01', 'end_date' => '2026-11-15', 'status' => Exam::STATUS_MARKS_ENTRY]);
        $past = Exam::factory()->create(['academic_year_id' => $this->year->id, 'name_bn' => 'শেষ হওয়া পরীক্ষা', 'start_date' => '2026-06-01', 'end_date' => '2026-06-15', 'status' => Exam::STATUS_MARKS_ENTRY]);
        foreach ([$upcoming, $past] as $exam) {
            ExamSubject::factory()->create(['exam_id' => $exam->id, 'class_id' => $this->class10->id, 'subject_id' => $this->bangla->id, 'exam_date' => $exam->start_date, 'start_time' => '10:00:00', 'end_time' => '13:00:00']);
        }

        $this->web($login)->get('/portal/exams')->assertOk()
            ->assertSee('বার্ষিক পরীক্ষা')->assertDontSee('শেষ হওয়া পরীক্ষা')
            ->assertSee('১ নভেম্বর ২০২৬')->assertSee('১০:০০ পূর্বাহ্ন');
        $this->assertCount(2, $this->as($login)->getJson('/api/my/exams')->json('data.0.exams'));
    }

    // Routine

    private function publishRoutine(): void
    {
        $this->subjectNames();
        $teacher = $this->teacherFor($this->section10, $this->bangla, ['name_bn' => 'রহিম স্যার', 'name_en' => 'Rahim Sir']);
        $this->saveRoutine($this->section10, [
            $this->cell($this->morning[1], 'saturday', $this->bangla, $teacher, 'Room 101'),
            $this->cell($this->morning[2], 'sunday', $this->bangla),
        ])->assertOk();
    }

    private function subjectNames(): void
    {
        $this->bangla->update(['name_bn' => 'বাংলা']);
    }

    public function test_the_routine_page_shows_the_students_section_routine_and_matches_the_api(): void
    {
        $login = $this->login('20260001', 'student');
        $this->enrolled($login);
        $this->publishRoutine();
        // Another section's routine must not leak in.
        $this->saveRoutine($this->section9, [$this->cell($this->morning[4], 'monday', $this->bangla, null, 'Room 909')])->assertOk();

        $html = $this->web($login)->get('/portal/routine')->assertOk()
            ->assertSee('বাংলা')->assertSee('রহিম স্যার')->assertSee('Room 101')
            ->assertSee('শনিবার')->assertSee('টিফিন')->assertSee('০৮:০০')->assertDontSee('Room 909')
            ->assertDontSee('শুক্রবার')
            ->getContent();
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('A4 landscape', $html);
        $this->assertStringContainsString('window.print()', $html);

        $api = $this->as($login)->getJson('/api/my/routine')->assertOk()->json('data');
        $this->assertCount(2, $api['slots']);
        $this->assertSame(['Room 101'], array_values(array_filter(array_column($api['slots'], 'room'))));
    }

    public function test_the_routine_page_can_be_printed_in_english(): void
    {
        $login = $this->login('20260001', 'student');
        $this->enrolled($login);
        $this->publishRoutine();

        $this->web($login)->get('/portal/routine?language=en')->assertOk()
            ->assertSee('Saturday')->assertSee('Rahim Sir')->assertSee('Tiffin')->assertSee('08:00')->assertSee('Room: Room 101', false);
        $this->web($login)->get('/portal/routine?language=xx')->assertSessionHasErrors('language');
    }

    public function test_the_routine_page_says_so_when_there_is_no_routine_yet(): void
    {
        $login = $this->login('20260001', 'student');
        $this->enrolled($login);

        $this->web($login)->get('/portal/routine')->assertOk()->assertSee('এখনো তৈরি হয়নি');
    }

    public function test_a_guardian_switches_between_children_and_cannot_open_another_familys(): void
    {
        $guardian = $this->login('01712345678', 'parent');
        $first = $this->enrolled(null, $guardian);
        $second = $this->enrol($this->section9, null, null, 1);
        Student::whereKey($second->student_id)->update(['guardian_user_id' => $guardian->id]);
        $stranger = $this->enrol($this->section9, null, null, 2);
        Student::whereKey($stranger->student_id)->update(['guardian_user_id' => $this->login('01800000000', 'parent')->id]);

        $this->publishRoutine();
        $this->saveRoutine($this->section9, [$this->cell($this->morning[4], 'monday', $this->bangla, null, 'Room 909')])->assertOk();

        $this->web($guardian)->get("/portal/routine?student={$first->student_id}")->assertOk()->assertSee('Room 101')->assertDontSee('Room 909');
        $this->web($guardian)->get("/portal/routine?student={$second->student_id}")->assertOk()->assertSee('Room 909')->assertDontSee('Room 101');
        $this->web($guardian)->get("/portal/routine?student={$stranger->student_id}")->assertForbidden();
    }

    // Profile

    public function test_changing_the_password_works_and_bumps_the_trust_version(): void
    {
        $login = $this->login('20260001', 'student');
        $this->enrolled($login);
        LoginTrust::trust($login, '10.0.0.9');
        $this->assertTrue(LoginTrust::isTrusted($login, '10.0.0.9'));
        $login->createToken('phone');

        $this->web($login)->put('/portal/profile/password', [
            'current_password' => 'secret-pass', 'new_password' => 'brand-new-pass', 'new_password_confirmation' => 'brand-new-pass',
        ])->assertRedirect('/portal/profile')->assertSessionHas('status', 'password-changed');

        $this->assertTrue(Hash::check('brand-new-pass', $login->fresh()->password));
        $this->assertFalse(LoginTrust::isTrusted($login, '10.0.0.9'));
        $this->assertSame(0, $login->tokens()->count());
        $this->web($login)->get('/portal/profile')->assertSee('পাসওয়ার্ড পরিবর্তন হয়েছে');
    }

    public function test_changing_the_password_ends_the_other_sessions_but_keeps_this_one(): void
    {
        $login = $this->login('20260001', 'student');
        $this->enrolled($login);

        // Session B: signed in earlier from another device.
        $this->post('/portal/login', ['login' => '20260001', 'password' => 'secret-pass'])->assertRedirect('/portal');
        $this->get('/portal/profile')->assertOk();
        $sessionB = session()->all();
        $this->flushSession();
        Auth::guard('web')->forgetUser();

        // Session A changes the password.
        $this->post('/portal/login', ['login' => '20260001', 'password' => 'secret-pass'])->assertRedirect('/portal');
        $this->get('/portal/profile')->assertOk();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->put('/portal/profile/password', [
            'current_password' => 'secret-pass', 'new_password' => 'brand-new-pass', 'new_password_confirmation' => 'brand-new-pass',
        ])->assertRedirect('/portal/profile');
        $passwordWrites = array_filter(array_column(\Illuminate\Support\Facades\DB::getQueryLog(), 'query'), fn ($q) => preg_match('/^update "users" set .*"password"/', $q));
        \Illuminate\Support\Facades\DB::disableQueryLog();
        $this->assertCount(1, $passwordWrites, 'the password hash is written once');
        $this->get('/portal/profile')->assertOk();
        $this->assertAuthenticated('web');
        $sessionA = session()->all();

        // Session A is still signed in on its next request ...
        $this->flushSession();
        Auth::guard('web')->forgetUser();
        $this->withSession($sessionA)->get('/portal/profile')->assertOk();

        // ... and session B is sent to the portal login.
        $this->flushSession();
        Auth::guard('web')->forgetUser();
        $this->withSession($sessionB)->get('/portal/profile')->assertRedirect('/portal/login');
    }

    public function test_a_page_parameter_on_a_plain_portal_page_is_not_rejected(): void
    {
        $login = $this->login('20260001', 'student');
        $this->enrolled($login);

        foreach (['/portal', '/portal/fees', '/portal/results', '/portal/attendance', '/portal/exams', '/portal/homework', '/portal/profile'] as $url) {
            $this->web($login)->get("{$url}?page=2")->assertOk();
        }

        // The routine validates only its language; the marksheet and receipt validate the paper.
        $this->web($login)->get('/portal/routine?page=2&orientation=x')->assertOk();
        $this->web($login)->getJson('/portal/routine?language=xx')->assertUnprocessable()->assertJsonValidationErrors('language');
        $this->web($login)->getJson('/portal/results/1?page=2')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_print_options_are_validated_per_page(): void
    {
        $login = $this->login('20260001', 'student');
        $this->enrolled($login);

        $this->web($login)->getJson('/portal/results/1?page=a5')->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->web($login)->getJson('/portal/fees/receipts/1?page=legal')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_a_staff_web_session_can_sign_out(): void
    {
        $this->web($this->teacher)->post('/portal/logout')->assertRedirect('/portal/login');
        $this->assertGuest('web');
    }

    public function test_a_wrong_current_password_or_a_short_one_is_422_as_json_and_an_error_in_the_form(): void
    {
        $login = $this->login('20260001', 'student');
        $this->enrolled($login);
        $payload = ['current_password' => 'nope', 'new_password' => 'brand-new-pass', 'new_password_confirmation' => 'brand-new-pass'];

        $this->web($login)->putJson('/portal/profile/password', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->web($login)->from('/portal/profile')->put('/portal/profile/password', $payload)
            ->assertRedirect('/portal/profile')->assertSessionHasErrors('current_password');
        $this->web($login)->putJson('/portal/profile/password', ['current_password' => 'secret-pass', 'new_password' => 'short', 'new_password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('new_password');
        $this->assertTrue(Hash::check('secret-pass', $login->fresh()->password));
    }

    // SEO

    /** @dataProvider portalUris */
    public function test_every_signed_in_page_is_noindex_with_one_h1(string $uri): void
    {
        $login = $this->login('20260001', 'student');
        $enrolment = $this->enrolled($login);
        $result = $this->publish($enrolment);
        $this->generateDues()->assertOk();
        $payment = $this->pay($enrolment, '100.00')->assertCreated()->json('data.id');

        $uri = str_replace(['/results/1', '/receipts/1'], ["/results/{$result->exam_id}", "/receipts/{$payment}"], $uri);
        $html = $this->web($login)->get($uri)->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertSame(1, substr_count($html, '<h1'), $uri);
    }

    public function test_the_portal_is_not_in_the_sitemap_and_is_disallowed_in_robots_txt(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/portal', false);

        $this->app['env'] = 'production';
        $this->get('/robots.txt')->assertSee('Disallow: /portal', false);
    }

    // Header

    public function test_the_header_shows_the_portal_and_logout_when_signed_in(): void
    {
        $this->seed(\Database\Seeders\MenuSeeder::class);
        $login = $this->login('20260001', 'student');
        $this->enrolled($login);

        $this->get('/')->assertSee('লগইন / পোর্টাল')->assertDontSee('আমার পোর্টাল');

        $this->web($login)->get('/')->assertSee('আমার পোর্টাল')->assertSee(route('portal.logout'), false)->assertDontSee('লগইন / পোর্টাল');
        Auth::guard('web')->logout();

        // A signed-in staff web session doesn't get the portal link.
        $this->web($this->teacher)->get('/')->assertDontSee('আমার পোর্টাল');
    }

    public function test_the_menu_seeder_adds_the_portal_link_once_to_a_new_and_an_existing_menu(): void
    {
        $this->assertContains('portal.login', \App\Models\MenuItem::ROUTES);

        $this->seed(\Database\Seeders\MenuSeeder::class);
        $this->seed(\Database\Seeders\MenuSeeder::class);
        $this->assertSame(1, \App\Models\MenuItem::where('route_name', 'portal.login')->count());
        $this->assertSame('লগইন / পোর্টাল', \App\Models\MenuItem::where('route_name', 'portal.login')->value('label'));

        \App\Models\MenuItem::where('route_name', 'portal.login')->delete();
        $this->seed(\Database\Seeders\MenuSeeder::class);
        $this->seed(\Database\Seeders\MenuSeeder::class);
        $this->assertSame(1, \App\Models\MenuItem::where('route_name', 'portal.login')->count());
        $this->get('/')->assertSee(route('portal.login'), false);
    }
}
