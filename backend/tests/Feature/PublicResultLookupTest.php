<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\MenuItem;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Student;
use App\Models\StudentEnrolment;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PublicResultLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        \Illuminate\Http\Middleware\TrustProxies::flushState();

        parent::tearDown();
    }

    private const DOB = '2012-03-15';

    private AcademicYear $year;

    private Exam $exam;

    private Section $section;

    private Section $section9;

    private Student $student;

    private Student $student9;

    protected function setUp(): void
    {
        parent::setUp();

        $this->year = AcademicYear::factory()->active()->create(['year' => 2026]);
        $this->exam = Exam::factory()->create([
            'academic_year_id' => $this->year->id, 'name_en' => 'Half Yearly Exam', 'name_bn' => 'অর্ধ-বার্ষিক পরীক্ষা',
            'status' => Exam::STATUS_PUBLISHED, 'published_at' => now(),
        ]);

        $shift = Shift::factory()->create(['name_en' => 'Morning', 'name_bn' => 'প্রভাতী']);
        $class5 = Classes::factory()->create(['number' => 5, 'name' => 'Class 5', 'name_bn' => 'পঞ্চম শ্রেণি']);
        $class9 = Classes::factory()->create(['number' => 9, 'name' => 'Class 9', 'name_bn' => null]);
        $this->section = Section::factory()->create(['class_id' => $class5->id, 'shift_id' => $shift->id, 'name' => 'Section A', 'code' => 'A']);
        $this->section9 = Section::factory()->create(['class_id' => $class9->id, 'shift_id' => $shift->id, 'name' => 'Section A', 'code' => 'A']);

        foreach ([$class5, $class9] as $class) {
            \App\Models\ExamSubject::factory()->create(['exam_id' => $this->exam->id, 'class_id' => $class->id]);
        }

        $this->student = $this->resultFor($this->section, '20260047', 7, null, ['name_en' => 'Rahim Uddin', 'name_bn' => 'রহিম উদ্দিন']);
        $this->student9 = $this->resultFor($this->section9, '20260099', 12, 'science');
    }

    private function resultFor(Section $section, string $code, int $roll, ?string $group, array $attributes = [], ?Exam $exam = null, ?AcademicYear $year = null): Student
    {
        $student = Student::factory()->create(['student_id' => $code, 'date_of_birth' => self::DOB, ...$attributes]);
        $enrolment = StudentEnrolment::factory()->create([
            'student_id' => $student->id, 'academic_year_id' => ($year ?? $this->year)->id,
            'section_id' => $section->id, 'class_id' => $section->class_id, 'roll_number' => $roll, 'group' => $group,
        ]);

        ExamResult::factory()->create([
            'exam_id' => ($exam ?? $this->exam)->id, 'student_id' => $student->id, 'enrolment_id' => $enrolment->id,
            'class_id' => $section->class_id, 'section_id' => $section->id,
            'gpa' => '3.64', 'grade' => 'A', 'passed_count' => 2, 'failed_count' => 1, 'class_position' => 4, 'section_position' => 3,
            'total_obtained' => '148.50', 'total_full' => '200.00',
            'subjects' => [
                $this->unit('Mathematics', 'গণিত', 'A+', '5.00', '85.00'),
                $this->unit('Bangla 1st + Bangla 2nd', 'বাংলা ১ম + বাংলা ২য়', 'A', '4.00', '63.50', combined: true),
                $this->unit('Agriculture', 'কৃষি', 'F', '0.00', '20.00', optional: true),
            ],
        ]);

        return $student;
    }

    private function unit(string $en, string $bn, string $grade, string $point, string $obtained, bool $optional = false, bool $combined = false): array
    {
        return [
            'exam_subject_ids' => [1], 'subject_ids' => [1], 'name_en' => $en, 'name_bn' => $bn,
            'is_optional' => $optional, 'is_combined' => $combined,
            'papers' => [['subject_id' => 1, 'name_en' => $en, 'name_bn' => $bn, 'is_absent' => false, 'is_missing' => false,
                'parts' => ['written' => ['obtained' => $obtained, 'full' => 100, 'pass' => 33]]]],
            'parts' => ['written' => ['obtained' => $obtained, 'full' => 100, 'pass' => 33]],
            'obtained' => $obtained, 'full' => 100, 'percentage' => $obtained, 'grade' => $grade, 'point' => $point, 'is_absent' => false,
        ];
    }

    private function byId(array $overrides = []): array
    {
        return ['exam_id' => $this->exam->id, 'student_id' => '20260047', 'date_of_birth' => self::DOB, ...$overrides];
    }

    private function byRoll(array $overrides = []): array
    {
        return ['exam_id' => $this->exam->id, 'section_id' => $this->section->id, 'roll' => 7, 'date_of_birth' => self::DOB, ...$overrides];
    }

    private function notFoundBodies(): array
    {
        $unpublished = Exam::factory()->create(['academic_year_id' => $this->year->id, 'status' => Exam::STATUS_PROCESSED]);
        $otherYear = AcademicYear::factory()->create(['year' => 2025]);
        $otherExam = Exam::factory()->create(['academic_year_id' => $otherYear->id, 'status' => Exam::STATUS_PUBLISHED, 'published_at' => now()]);
        $this->resultFor($this->section, '20250001', 8, null, [], $unpublished);
        // Enrolled in 2025 only: no result in the 2026 exam.
        $former = Student::factory()->create(['student_id' => '20250002', 'date_of_birth' => self::DOB]);
        StudentEnrolment::factory()->create(['student_id' => $former->id, 'academic_year_id' => $otherYear->id, 'section_id' => $this->section->id, 'class_id' => $this->section->class_id, 'roll_number' => 30]);
        ExamResult::factory()->create(['exam_id' => $otherExam->id, 'student_id' => $former->id, 'enrolment_id' => StudentEnrolment::where('student_id', $former->id)->value('id')]);

        $cases = [
            'wrong dob' => $this->byId(['date_of_birth' => '2011-01-01']),
            'unknown id' => $this->byId(['student_id' => '99999999']),
            'unknown roll' => $this->byRoll(['roll' => 99]),
            'unknown section' => $this->byRoll(['section_id' => 99999]),
            'not enrolled that year' => $this->byId(['student_id' => '20250002']),
            'unpublished exam' => $this->byId(['exam_id' => $unpublished->id, 'student_id' => '20250001']),
            'unknown exam' => $this->byId(['exam_id' => 987654]),
        ];

        return $cases;
    }

    // --- API ---------------------------------------------------------------------------

    public function test_api_finds_the_result_by_student_id_and_by_section_group_and_roll(): void
    {
        $byId = $this->postJson('/api/public/results', $this->byId())
            ->assertOk()
            ->assertJsonPath('data.student.student_code', '20260047')
            ->assertJsonPath('data.gpa', '3.64')
            ->assertJsonPath('data.class_position', 4)
            ->assertJsonPath('data.roll_number', 7)
            ->assertJsonCount(3, 'data.subjects')
            ->assertJsonStructure(['data' => ['exam' => ['academic_year'], 'class', 'section' => ['shift'], 'subjects']]);

        $byRoll = $this->postJson('/api/public/results', $this->byRoll())->assertOk();
        $this->assertSame($byId->json('data'), $byRoll->json('data'));

        $this->postJson('/api/public/results', [
            'exam_id' => $this->exam->id, 'section_id' => $this->section9->id, 'group' => 'science', 'roll' => 12, 'date_of_birth' => self::DOB,
        ])->assertOk()->assertJsonPath('data.student.student_code', '20260099');

        // The student's private details never leave the server.
        $this->assertStringNotContainsString(self::DOB, $byId->getContent());
    }

    public function test_every_kind_of_miss_gives_the_same_404_body(): void
    {
        $bodies = [];
        foreach ($this->notFoundBodies() as $name => $payload) {
            $response = $this->postJson('/api/public/results', $payload);
            $response->assertNotFound();
            $bodies[$name] = $response->getContent();
        }

        $this->assertCount(1, array_unique($bodies), 'Misses differ: '.json_encode(array_keys($bodies)));
        $this->assertStringContainsString('কোনো ফলাফল পাওয়া যায়নি', json_decode(reset($bodies), true)['message']);
    }

    public function test_api_validation(): void
    {
        $this->postJson('/api/public/results', ['exam_id' => $this->exam->id, 'date_of_birth' => self::DOB])
            ->assertUnprocessable()->assertJsonValidationErrors(['student_id', 'section_id', 'roll']);
        $this->postJson('/api/public/results', $this->byId(['date_of_birth' => '15/03/2012']))
            ->assertUnprocessable()->assertJsonValidationErrors(['date_of_birth']);
        $this->postJson('/api/public/results', $this->byId(['date_of_birth' => '2012-02-31']))
            ->assertUnprocessable()->assertJsonValidationErrors(['date_of_birth']);
        $this->postJson('/api/public/results', $this->byId(['exam_id' => 'abc']))
            ->assertUnprocessable()->assertJsonValidationErrors(['exam_id']);
        $this->postJson('/api/public/results', $this->byId(['exam_id' => ['1']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['exam_id']);
        $this->postJson('/api/public/results', $this->byId(['student_id' => ['x']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['student_id']);
        $this->postJson('/api/public/results', $this->byRoll(['roll' => 'x']))
            ->assertUnprocessable()->assertJsonValidationErrors(['roll']);
        $this->postJson('/api/public/results', $this->byRoll(['group' => 'arts']))
            ->assertUnprocessable()->assertJsonValidationErrors(['group']);
    }

    public function test_a_group_is_required_from_class_nine_and_forbidden_below(): void
    {
        $this->postJson('/api/public/results', ['exam_id' => $this->exam->id, 'section_id' => $this->section9->id, 'roll' => 12, 'date_of_birth' => self::DOB])
            ->assertUnprocessable()->assertJsonValidationErrors(['group']);
        $this->postJson('/api/public/results', $this->byRoll(['group' => 'science']))
            ->assertUnprocessable()->assertJsonValidationErrors(['group']);
    }

    public function test_api_exams_lists_published_exams_only_with_their_sections(): void
    {
        Exam::factory()->create(['academic_year_id' => $this->year->id, 'name_en' => 'Secret Draft', 'status' => Exam::STATUS_DRAFT]);
        Exam::factory()->create(['academic_year_id' => $this->year->id, 'name_en' => 'Secret Processed', 'status' => Exam::STATUS_PROCESSED]);
        Section::factory()->create(['class_id' => $this->section->class_id, 'is_active' => false, 'name' => 'Inactive Section']);

        $response = $this->getJson('/api/public/exams')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->exam->id)
            ->assertJsonPath('data.0.academic_year.year', 2026)
            ->assertJsonCount(2, 'data.0.classes');

        $this->assertStringNotContainsString('Secret', $response->getContent());
        $this->assertStringNotContainsString('Inactive Section', $response->getContent());
        $this->assertSame('Section A', $response->json('data.0.classes.0.sections.0.name'));
        $this->assertSame('Morning', $response->json('data.0.classes.0.sections.0.shift.name_en'));
    }

    public function test_the_lookup_is_limited_to_ten_a_minute_per_ip_with_retry_after(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/public/results', $this->byId())->assertOk();
        }

        $this->postJson('/api/public/results', $this->byId())->assertStatus(429)->assertHeader('Retry-After');
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->postJson('/api/public/results', $this->byId())->assertOk();
    }

    public function test_sixty_failed_lookups_an_hour_lock_the_ip_but_not_another(): void
    {
        $key = \App\Services\ResultService::publicFailureKey('127.0.0.1');
        for ($i = 0; $i < 59; $i++) {
            RateLimiter::hit($key, 3600);
        }

        // The 60th failure is still answered as "not found"; the next lookup is locked out,
        // even with the right details.
        $this->postJson('/api/public/results', $this->byId(['student_id' => '99999999']))->assertNotFound();
        $this->postJson('/api/public/results', $this->byId())->assertStatus(429)->assertHeader('Retry-After');
        $this->post('/results', $this->byId())->assertStatus(429)->assertHeader('Retry-After');

        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->postJson('/api/public/results', $this->byId())->assertOk();
    }

    public function test_the_year_is_shown_once_in_the_marksheet_and_the_lookup_page(): void
    {
        $this->exam->update(['name_bn' => 'অর্ধবার্ষিক পরীক্ষা ২০২৬', 'name_en' => 'Half Yearly 2026']);

        $sheet = $this->post('/results', $this->byId())->assertOk()->getContent();
        $this->assertSame(1, substr_count($sheet, '২০২৬ –'));
        $this->assertStringNotContainsString('২০২৬ ২০২৬', $sheet);

        $page = $this->get('/results')->getContent();
        $this->assertStringContainsString('>অর্ধবার্ষিক পরীক্ষা ২০২৬</option>', $page);

        $this->exam->update(['name_bn' => 'অর্ধবার্ষিক পরীক্ষা']);
        $this->assertStringContainsString('অর্ধবার্ষিক পরীক্ষা ২০২৬ –', $this->post('/results', $this->byId())->getContent());
        $this->assertStringContainsString('>অর্ধবার্ষিক পরীক্ষা 2026</option>', $this->get('/results')->getContent());
    }

    public function test_the_date_of_birth_is_not_flashed_to_the_session_or_the_redirected_form(): void
    {
        $this->post('/results', $this->byId(['date_of_birth' => '2011-01-01']))->assertRedirect('/results');
        $this->assertNull(session()->getOldInput('date_of_birth'));
        $this->assertSame('20260047', session()->getOldInput('student_id'));

        $this->followingRedirects()->post('/results', $this->byId(['date_of_birth' => '2011-01-01']))
            ->assertDontSee('2011-01-01');
    }

    public function test_forwarded_for_is_ignored_when_no_proxy_is_trusted(): void
    {
        $key = \App\Services\ResultService::publicFailureKey('127.0.0.1');

        $this->withHeader('X-Forwarded-For', '203.0.113.9')->postJson('/api/public/results', $this->byId(['student_id' => '99999999']))->assertNotFound();

        $this->assertSame(1, RateLimiter::attempts($key));
        $this->assertSame(0, RateLimiter::attempts(\App\Services\ResultService::publicFailureKey('203.0.113.9')));
    }

    public function test_forwarded_for_is_used_when_the_client_is_a_trusted_proxy(): void
    {
        \App\Support\TrustedProxies::apply('127.0.0.1');

        $this->withHeader('X-Forwarded-For', '203.0.113.9')->postJson('/api/public/results', $this->byId(['student_id' => '99999999']))->assertNotFound();

        $this->assertSame(1, RateLimiter::attempts(\App\Services\ResultService::publicFailureKey('203.0.113.9')));
        $this->assertSame(0, RateLimiter::attempts(\App\Services\ResultService::publicFailureKey('127.0.0.1')));

        $this->withHeader('X-Forwarded-For', '203.0.113.9')->postJson('/api/login', ['login' => 'nobody@example.com', 'password' => 'wrong-password']);
        $this->assertSame(1, RateLimiter::attempts('login-ip-fail:'.sha1('203.0.113.9')));
        $this->assertSame(0, RateLimiter::attempts('login-ip-fail:'.sha1('127.0.0.1')));
    }

    public function test_forwarded_host_is_not_honoured_even_when_proxies_are_trusted(): void
    {
        \App\Support\TrustedProxies::apply('*');

        $request = \Illuminate\Http\Request::create('http://localhost/up', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'HTTP_X_FORWARDED_HOST' => 'evil.example']);
        $response = null;
        (new \Illuminate\Http\Middleware\TrustProxies)->handle($request, function ($r) use (&$response) {
            $response = $r;

            return response('ok');
        });

        $this->assertSame('203.0.113.9', $response->ip());
        $this->assertSame('localhost', $response->getHost());
    }

    public function test_successful_lookups_do_not_count_as_failures(): void
    {
        $this->postJson('/api/public/results', $this->byId())->assertOk();
        $this->postJson('/api/public/results', $this->byId())->assertOk();

        $this->assertSame(0, RateLimiter::attempts(\App\Services\ResultService::publicFailureKey('127.0.0.1')));
    }

    // --- Website -----------------------------------------------------------------------

    public function test_the_marksheet_shows_in_bangla_with_bangla_digits_and_names(): void
    {
        $response = $this->post('/results', $this->byId(['language' => 'bn', 'page' => 'a4', 'orientation' => 'portrait']))->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('রহিম উদ্দিন', $html);
        $this->assertStringContainsString('পঞ্চম শ্রেণি', $html);
        $this->assertStringContainsString('গণিত', $html);
        $this->assertStringContainsString('৩.৬৪', $html);
        $this->assertStringContainsString('২০২৬০০৪৭', $html);
        $this->assertStringContainsString('size: A4 portrait', $html);
        $this->assertStringContainsString('৪র্থ বিষয়', $html);
        $this->assertStringContainsString('ফেল হিসেবে গণ্য নয়', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertDoesNotMatchRegularExpression('/20260047/', strip_tags($html));
    }

    public function test_the_marksheet_shows_in_english_on_legal_landscape(): void
    {
        $html = $this->post('/results', $this->byId(['language' => 'en', 'page' => 'legal', 'orientation' => 'landscape']))->assertOk()->getContent();

        $this->assertStringContainsString('Rahim Uddin', $html);
        $this->assertStringContainsString('Mathematics', $html);
        $this->assertStringContainsString('20260047', $html);
        $this->assertStringContainsString('3.64', $html);
        $this->assertStringContainsString('Subjects passed', $html);
        $this->assertStringContainsString('2 of 3', $html);
        $this->assertStringContainsString('size: legal landscape', $html);
        $this->assertStringContainsString('Combined', $html);
        $this->assertStringContainsString('4th subject', $html);
        $this->assertStringNotContainsString('৩.৬৪', $html);
    }

    public function test_the_marksheet_can_be_found_by_section_group_and_roll(): void
    {
        $this->post('/results', $this->byRoll(['language' => 'en']))->assertOk()->assertSee('Rahim Uddin');
        $this->post('/results', [
            'exam_id' => $this->exam->id, 'section_id' => $this->section9->id, 'group' => 'science', 'roll' => 12,
            'date_of_birth' => self::DOB, 'language' => 'en',
        ])->assertOk()->assertSee('20260099');
    }

    public function test_the_marksheet_is_noindex_no_store_and_never_puts_the_dob_in_a_url(): void
    {
        $response = $this->post('/results', $this->byId())->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('noindex, nofollow', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('window.print()', $html);
        $this->assertStringNotContainsString(self::DOB, $html);

        // Only POST renders a marksheet: a GET with the details in the query string is just the form.
        $get = $this->get('/results?'.http_build_query($this->byId()))->assertOk();
        $this->assertStringNotContainsString('Rahim Uddin', $get->getContent());
    }

    public function test_a_web_miss_redirects_back_to_the_form_with_the_same_message(): void
    {
        $messages = [];
        foreach ($this->notFoundBodies() as $name => $payload) {
            $response = $this->post('/results', $payload)->assertRedirect('/results');
            $messages[$name] = session('errors')->first('lookup');
        }

        $this->assertCount(1, array_unique($messages));
        $this->assertStringContainsString('কোনো ফলাফল পাওয়া যায়নি', reset($messages));

        $this->followingRedirects()->post('/results', $this->byId(['date_of_birth' => '2011-01-01']))
            ->assertOk()->assertSee('কোনো ফলাফল পাওয়া যায়নি')->assertSee('No result found')
            ->assertDontSee('2011-01-01');
    }

    public function test_web_validation_redirects_back_with_errors_and_old_input(): void
    {
        $this->from('/results')->post('/results', ['exam_id' => 'abc', 'date_of_birth' => 'x'])
            ->assertRedirect('/results')
            ->assertSessionHasErrors(['exam_id', 'date_of_birth', 'student_id']);

        $this->from('/results')->post('/results', ['exam_id' => $this->exam->id, 'section_id' => $this->section9->id, 'roll' => 12, 'date_of_birth' => self::DOB])
            ->assertSessionHasErrors(['group']);

        $this->post('/results', $this->byId(['language' => 'fr']))->assertSessionHasErrors(['language']);
        $this->post('/results', $this->byId(['page' => 'a3']))->assertSessionHasErrors(['page']);
        $this->post('/results', $this->byId(['orientation' => 'tilted']))->assertSessionHasErrors(['orientation']);
    }

    public function test_the_lookup_page_lists_published_exams_and_sections_only(): void
    {
        Exam::factory()->create(['academic_year_id' => $this->year->id, 'name_en' => 'Secret Draft', 'name_bn' => 'গোপন', 'status' => Exam::STATUS_PROCESSED]);

        $html = $this->get('/results')->assertOk()->getContent();

        $this->assertStringContainsString('অর্ধ-বার্ষিক পরীক্ষা', $html);
        $this->assertStringNotContainsString('গোপন', $html);
        $this->assertStringNotContainsString('Secret Draft', $html);
        $this->assertStringContainsString('Class 5 – Section A (Morning)', $html);
        $this->assertStringContainsString('Class 9 – Section A (Morning)', $html);
        $this->assertStringContainsString('name="date_of_birth"', $html);
        $this->assertStringContainsString('name="_token"', $html);
    }

    public function test_the_lookup_page_preselects_an_exam(): void
    {
        $html = $this->get('/results?exam_id='.$this->exam->id)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="'.$this->exam->id.'"[^>]*selected/', $html);

        // Garbage or an unpublished exam id is ignored, not an error.
        $this->get('/results?exam_id=abc')->assertOk();
        $this->get('/results?exam_id[]=1')->assertOk();
        $this->get('/results?exam_id=987654')->assertOk();
    }

    public function test_the_archive_groups_published_exams_by_year_and_links_to_the_form(): void
    {
        $old = AcademicYear::factory()->create(['year' => 2025]);
        Exam::factory()->create(['academic_year_id' => $old->id, 'name_en' => 'Annual Exam', 'name_bn' => null, 'status' => Exam::STATUS_PUBLISHED, 'published_at' => now()]);
        Exam::factory()->create(['academic_year_id' => $old->id, 'name_en' => 'Hidden Draft', 'status' => Exam::STATUS_MARKS_ENTRY]);

        $html = $this->get('/results/archive')->assertOk()->getContent();

        $this->assertStringNotContainsString('Hidden Draft', $html);
        $this->assertStringContainsString('Annual Exam', $html);
        $this->assertStringContainsString('/results?exam_id='.$this->exam->id, $html);
        $this->assertLessThan(strpos($html, '>2025<'), strpos($html, '>2026<'), 'Newest year first');
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_the_lookup_and_archive_pages_are_in_the_sitemap(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<loc>'.route('results.index').'</loc>', $xml);
        $this->assertStringContainsString('<loc>'.route('results.archive').'</loc>', $xml);
        $this->assertStringNotContainsString('exam_id', $xml);
    }

    public function test_the_header_menu_gets_the_results_item_once(): void
    {
        $this->assertContains('results.index', MenuItem::ROUTES);

        $this->seed(MenuSeeder::class);
        $this->seed(MenuSeeder::class);

        $this->assertSame(1, MenuItem::where('route_name', 'results.index')->count());
        $this->assertSame('ফলাফল', MenuItem::where('route_name', 'results.index')->value('label'));
    }

    public function test_an_existing_menu_without_results_gets_it_appended_once(): void
    {
        MenuItem::create(['location' => 'header', 'label' => 'হোম', 'type' => 'route', 'route_name' => 'home', 'sort_order' => 0]);

        $this->seed(MenuSeeder::class);
        $this->seed(MenuSeeder::class);

        $this->assertSame(2, MenuItem::where('location', 'header')->count());
        $item = MenuItem::where('route_name', 'results.index')->sole();
        $this->assertSame(1, $item->sort_order);
        $this->get('/')->assertSee(route('results.index'), false);
    }
}
