<?php

namespace Tests\Feature;

use App\Models\Homework;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\BuildsFees;
use Tests\Concerns\BuildsRoutines;
use Tests\TestCase;

/**
 * The portal's homework page, its "due this week" tile and the signed attachment link.
 * "Now" is 2026-10-15 12:00 Asia/Dhaka (BuildsFees), a Thursday.
 */
class HomeworkPortalTest extends TestCase
{
    use BuildsFees, BuildsRoutines, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFees();
        $this->setUpRoutines();
        Storage::fake('local');
    }

    private function homework(Section $section, Subject $subject, array $attributes = []): Homework
    {
        return Homework::factory()->create([
            'academic_year_id' => $this->year->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'assigned_on' => '2026-10-01',
            'due_on' => '2026-10-20',
            ...$attributes,
        ]);
    }

    private function studentLogin(?StudentEnrolment $enrolment = null): array
    {
        $enrolment ??= $this->enrol($this->section10, null, null, 1);
        $user = $this->userWithRole('student');
        $enrolment->student->update(['user_id' => $user->id]);

        return [$user, $enrolment];
    }

    private function web(User $user)
    {
        return $this->actingAs($user, 'web');
    }

    public function test_the_page_groups_homework_by_due_date_and_marks_overdue_ones(): void
    {
        [$user] = $this->studentLogin();
        $this->homework($this->section10, $this->bangla, ['title' => 'Overdue sheet', 'due_on' => '2026-10-12']);
        $this->homework($this->section10, $this->bangla, ['title' => 'Due soon A', 'due_on' => '2026-10-17']);
        $this->homework($this->section10, $this->bangla, ['title' => 'Due soon B', 'due_on' => '2026-10-17']);
        $this->homework($this->section10, $this->bangla, ['title' => 'Very old', 'due_on' => '2026-08-01', 'assigned_on' => '2026-07-01']);
        $this->homework($this->section9, $this->bangla, ['title' => 'Other section']);

        $html = $this->web($user)->get('/portal/homework')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-testid="overdue"'), 'Only the overdue date group is marked');
        $this->assertSame(3, substr_count($html, 'data-testid="homework-item"'));
        $this->assertSame(2, substr_count($html, 'জমার তারিখ:'), 'Two date groups');
        $this->assertLessThan(strpos($html, 'Due soon A'), strpos($html, 'Overdue sheet'), 'Nearest date first');
        $this->assertStringContainsString('Due soon B', $html);
        $this->assertStringNotContainsString('Very old', $html);
        $this->assertStringNotContainsString('Other section', $html);
    }

    public function test_the_page_has_one_h1_and_is_private_and_noindex(): void
    {
        [$user] = $this->studentLogin();
        $this->homework($this->section10, $this->bangla);

        $response = $this->web($user)->get('/portal/homework')->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->get('/portal/homework')->assertOk();
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->get('/portal/homework')->assertRedirect('/portal/login');
    }

    public function test_an_empty_page_says_so(): void
    {
        [$user] = $this->studentLogin();

        $this->web($user)->get('/portal/homework')->assertOk()->assertSee('এখন কোনো হোমওয়ার্ক নেই');
    }

    public function test_only_the_subjects_the_student_takes_are_shown(): void
    {
        $this->homework($this->section9, $this->bangla, ['title' => 'Bangla work']);
        $this->homework($this->section9, $this->physics, ['title' => 'Physics work']);
        $this->homework($this->section9, $this->accounting, ['title' => 'Accounting work']);
        $this->homework($this->section9, $this->higherMath, ['title' => 'Higher Math work']);

        [$business] = $this->studentLogin($this->enrol($this->section9, 'business_studies', null, 1));
        $html = $this->web($business)->get('/portal/homework')->assertOk()->getContent();
        $this->assertStringContainsString('Bangla work', $html);
        $this->assertStringContainsString('Accounting work', $html);
        $this->assertStringNotContainsString('Physics work', $html);
        $this->assertStringNotContainsString('Higher Math work', $html);

        $this->flushSession();
        [$science] = $this->studentLogin($this->enrol($this->section9, 'science', $this->higherMath, 2));
        $html = $this->web($science)->get('/portal/homework')->getContent();
        $this->assertStringContainsString('Higher Math work', $html);
        $this->assertStringContainsString('Physics work', $html);
        $this->assertStringNotContainsString('Accounting work', $html);
    }

    public function test_the_dashboard_tile_counts_what_is_due_in_the_next_seven_days_like_the_api(): void
    {
        [$user] = $this->studentLogin();
        $this->homework($this->section10, $this->bangla, ['due_on' => '2026-10-14', 'assigned_on' => '2026-10-01']); // overdue
        $this->homework($this->section10, $this->bangla, ['due_on' => '2026-10-15']); // today
        $this->homework($this->section10, $this->bangla, ['due_on' => '2026-10-21']); // today + 6
        $this->homework($this->section10, $this->bangla, ['due_on' => '2026-10-22']); // today + 7: next week
        $this->homework($this->section9, $this->bangla, ['due_on' => '2026-10-16']); // other section

        $api = $this->actingAs($user, 'sanctum')->getJson('/api/my/homework?from=2026-10-15&to=2026-10-21')->assertOk()->json('data');
        $this->assertCount(2, $api);

        $this->web($user)->get('/portal')->assertOk()
            ->assertSee('data-testid="homework-due-count"', false)
            ->assertSeeInOrder(['data-testid="homework-due-count"', \App\Support\BanglaNumber::format(2).'টি'], false);
    }

    public function test_the_tile_counts_zero_with_no_homework(): void
    {
        [$user] = $this->studentLogin();

        $this->web($user)->get('/portal')->assertOk()->assertSeeInOrder(['data-testid="homework-due-count"', \App\Support\BanglaNumber::format(0).'টি'], false);
    }

    public function test_the_page_and_the_api_list_the_same_homework(): void
    {
        [$user] = $this->studentLogin();
        $this->homework($this->section10, $this->bangla, ['title' => 'Alpha', 'due_on' => '2026-10-16']);
        $this->homework($this->section10, $this->bangla, ['title' => 'Beta', 'due_on' => '2026-10-18']);

        $html = $this->web($user)->get('/portal/homework')->getContent();
        $titles = collect($this->actingAs($user, 'sanctum')->getJson('/api/my/homework?from=2026-09-15')->json('data'))->pluck('title');

        $this->assertSame(['Alpha', 'Beta'], $titles->all());
        foreach ($titles as $title) {
            $this->assertStringContainsString($title, $html);
        }
    }

    public function test_a_guardian_switches_between_children_and_cannot_open_another_familys(): void
    {
        $first = $this->enrol($this->section10, null, null, 1, ['name_bn' => 'প্রথম সন্তান']);
        $second = $this->enrol($this->section9, 'business_studies', null, 2, ['name_bn' => 'দ্বিতীয় সন্তান']);
        $stranger = $this->enrol($this->section10, null, null, 3);
        $guardian = $this->userWithRole('parent');
        Student::whereIn('id', [$first->student_id, $second->student_id])->update(['guardian_user_id' => $guardian->id]);
        Student::whereKey($stranger->student_id)->update(['guardian_user_id' => $this->userWithRole('parent')->id]);

        $this->homework($this->section10, $this->bangla, ['title' => 'Tenth work']);
        $this->homework($this->section9, $this->accounting, ['title' => 'Ninth work']);

        $this->web($guardian)->get('/portal/homework?student='.$first->student_id)->assertOk()->assertSee('Tenth work')->assertDontSee('Ninth work');
        $this->get('/portal/homework?student='.$second->student_id)->assertOk()->assertSee('Ninth work')->assertDontSee('Tenth work');
        $this->get('/portal/homework?student='.$stranger->student_id)->assertForbidden();
    }

    // Attachments

    private function withAttachment(string $name = 'Exercises.pdf'): Homework
    {
        $id = $this->actingAs($this->admin, 'sanctum')->post('/api/homework', [
            'section_id' => $this->section10->id, 'subject_id' => $this->bangla->id, 'title' => 'With file',
            'assigned_on' => '2026-10-10', 'due_on' => '2026-10-20',
            'attachment' => UploadedFile::fake()->create($name, 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        return Homework::findOrFail($id);
    }

    public function test_the_page_links_a_signed_attachment_and_the_link_downloads_it(): void
    {
        [$user] = $this->studentLogin();
        $homework = $this->withAttachment();

        $html = $this->web($user)->get('/portal/homework')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/href="([^"]*homework\/'.$homework->id.'\/attachment[^"]*)"/', $html, $match));
        $url = html_entity_decode($match[1]);
        $this->assertStringContainsString('signature=', $url);

        // The signature is the credential: no session needed, and PDFs download.
        $this->flushSession();
        auth('web')->logout();
        $response = $this->get($url)->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Robots-Tag', 'noindex')
            ->assertHeader('Content-Disposition', 'attachment; filename=Exercises.pdf');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_an_expired_or_altered_or_unsigned_link_is_403(): void
    {
        $homework = $this->withAttachment();
        $other = $this->withAttachment('Other.pdf');
        $url = URL::temporarySignedRoute('portal.homework.attachment', now()->addMinutes(10), ['homework' => $homework->id]);

        $this->get($url)->assertOk();

        // Expired.
        $this->travel(11)->minutes();
        $this->get($url)->assertForbidden();
        $this->travelBack();
        $this->travelTo('2026-10-15 06:00:00');

        // Another homework id with this signature, or an altered expiry.
        $this->get(str_replace("homework/{$homework->id}/", "homework/{$other->id}/", $url))->assertForbidden();
        $this->get(preg_replace('/expires=\d+/', 'expires=9999999999', $url))->assertForbidden();
        $this->get(substr($url, 0, -3).'abc')->assertForbidden();
        // Unsigned.
        $this->get("/portal/homework/{$homework->id}/attachment")->assertForbidden();
    }

    public function test_a_signed_link_for_a_missing_or_deleted_homework_or_file_is_404(): void
    {
        $homework = $this->withAttachment();
        $plain = $this->homework($this->section10, $this->bangla);

        $this->get(URL::temporarySignedRoute('portal.homework.attachment', now()->addMinutes(5), ['homework' => $plain->id]))->assertNotFound();

        Storage::disk('local')->delete($homework->attachment_path);
        $this->get(URL::temporarySignedRoute('portal.homework.attachment', now()->addMinutes(5), ['homework' => $homework->id]))->assertNotFound();

        $deleted = $this->withAttachment();
        $deleted->delete();
        $this->get(URL::temporarySignedRoute('portal.homework.attachment', now()->addMinutes(5), ['homework' => $deleted->id]))->assertNotFound();
    }

    public function test_the_link_is_not_minted_for_homework_the_student_cannot_see(): void
    {
        [$user] = $this->studentLogin($this->enrol($this->section9, 'business_studies', null, 1));
        $this->actingAs($this->admin, 'sanctum')->post('/api/homework', [
            'section_id' => $this->section9->id, 'subject_id' => $this->physics->id, 'title' => 'Physics only',
            'due_on' => '2026-10-20', 'attachment' => UploadedFile::fake()->create('physics.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $html = $this->web($user)->get('/portal/homework')->getContent();
        $this->assertStringNotContainsString('attachment?', $html);
        $this->assertStringNotContainsString('physics.pdf', $html);
    }
}
