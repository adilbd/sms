<?php

namespace Tests\Concerns;

use App\Models\AdmissionApplication;
use App\Models\AdmissionRound;
use App\Models\AdmissionRoundClass;
use App\Models\Classes;
use App\Models\Section;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The exam test school (active 2026 year, Class 9 and 10, one shift) plus Class 6, an
 * open admission round with Class 6 (2 seats) and Class 9 (no limit), an office clerk and
 * a teacher. "Now" is frozen at 2026-10-15 (Asia/Dhaka) before the round is built, so
 * "open" is deterministic, and both disks are faked.
 */
trait BuildsAdmissions
{
    use BuildsExams;

    protected Classes $class6;

    protected Section $section6;

    protected AdmissionRound $round;

    protected User $office;

    protected User $teacher;

    protected int $birthRegistration = 20140000000000000;

    protected function setUpAdmissions(): void
    {
        $this->setUpExams();
        $this->travelTo('2026-10-15 06:00:00');
        Storage::fake('local');
        Storage::fake('public');

        $this->class6 = Classes::factory()->create(['number' => 6, 'name' => 'Class 6', 'name_bn' => 'ষষ্ঠ শ্রেণি']);
        $this->section6 = Section::factory()->create(['class_id' => $this->class6->id, 'shift_id' => $this->shift->id, 'code' => 'A']);

        $this->round = AdmissionRound::factory()->create(['academic_year_id' => $this->year->id, 'name_en' => 'Admission 2026', 'name_bn' => 'ভর্তি ২০২৬']);
        AdmissionRoundClass::create(['round_id' => $this->round->id, 'class_id' => $this->class6->id, 'seats' => 2]);
        AdmissionRoundClass::create(['round_id' => $this->round->id, 'class_id' => $this->class9->id, 'seats' => null]);

        $this->office = $this->userWithRole('office');
        $this->teacher = $this->userWithRole('teacher');
    }

    /**
     * A valid public form payload (photo only among the files) for Class 6, with a fresh
     * birth registration number each call.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function applicationPayload(array $overrides = []): array
    {
        return array_merge([
            'class_id' => $this->class6->id,
            'name_en' => 'Karim Uddin',
            'name_bn' => 'করিম উদ্দিন',
            'date_of_birth' => '2014-05-20',
            'gender' => 'male',
            'religion' => 'islam',
            'birth_registration_number' => (string) ++$this->birthRegistration,
            'guardian_relation' => 'father',
            'guardian_name' => 'Abdul Uddin',
            'guardian_mobile' => '01712345678',
            'present_address' => 'Mirpur, Dhaka',
            'photo' => UploadedFile::fake()->image('photo.jpg', 200, 200)->size(100),
        ], $overrides);
    }

    protected function submitApplication(array $overrides = [], ?AdmissionRound $round = null)
    {
        return $this->post(route('admissions.apply.store', $round ?? $this->round), $this->applicationPayload($overrides));
    }

    /**
     * An application row for Class 6 in the test round, with its photo on the private disk.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function application(array $attributes = []): AdmissionApplication
    {
        $path = 'admissions/'.fake()->uuid().'.jpg';
        Storage::disk('local')->put($path, UploadedFile::fake()->image('photo.jpg', 100, 100)->getContent());

        return AdmissionApplication::factory()->create(array_merge([
            'round_id' => $this->round->id,
            'class_id' => $this->class6->id,
            'photo_path' => $path,
            'application_no' => sprintf('ADM-2026-%06d', random_int(100, 899999)),
        ], $attributes));
    }
}
