<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\User;
use App\Support\AcademicGroup;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Five demo students per class in the 2026 academic year, all in Section A of the
 * Morning shift, each with a student login (username = student ID) and a guardian login
 * (username = guardian mobile). From Class 9 every student has a group and, when the
 * curriculum offers one, a valid 4th subject. The fifth student of each class from
 * Class 2 shares a guardian with the first student of the class below, so sibling cases
 * exist. Every password is "password". Matched by student_id, so re-running never
 * duplicates. Needs Role/Class/Subject/Curriculum/AcademicYear/Section/Shift seeders
 * first; writes through the models directly (the accounts are built here, not through
 * StudentService).
 */
class StudentSeeder extends Seeder
{
    private const PER_CLASS = 5;

    /** bcrypt is slow on purpose; hash "password" once and share it across the ~110 logins. */
    private ?string $passwordHash = null;

    private const MALE_NAMES = [
        ['Rahim Uddin', 'রহিম উদ্দিন'], ['Karim Hossain', 'করিম হোসেন'], ['Tanvir Ahmed', 'তানভীর আহমেদ'],
        ['Sajid Islam', 'সাজিদ ইসলাম'], ['Nayeem Khan', 'নাঈম খান'], ['Arif Chowdhury', 'আরিফ চৌধুরী'],
    ];

    private const FEMALE_NAMES = [
        ['Ayesha Akter', 'আয়েশা আক্তার'], ['Fatema Begum', 'ফাতেমা বেগম'], ['Nusrat Jahan', 'নুসরাত জাহান'],
        ['Sumaiya Khatun', 'সুমাইয়া খাতুন'], ['Tania Rahman', 'তানিয়া রহমান'], ['Mim Sultana', 'মিম সুলতানা'],
    ];

    public function run(): void
    {
        $year = AcademicYear::where('year', 2026)->first();

        if (! $year) {
            return;
        }

        $studentRole = Role::findOrCreate('student', 'web');
        $parentRole = Role::findOrCreate('parent', 'web');

        foreach (Classes::whereNotNull('number')->orderBy('number')->get() as $class) {
            $section = Section::where('class_id', $class->id)
                ->where('code', 'A')
                ->whereHas('shift', fn ($q) => $q->where('slug', 'morning'))
                ->first();

            if (! $section) {
                continue;
            }

            for ($i = 1; $i <= self::PER_CLASS; $i++) {
                DB::transaction(fn () => $this->seedStudent($class, $section, $year, $i, $studentRole, $parentRole));
            }
        }
    }

    private function seedStudent(Classes $class, Section $section, AcademicYear $year, int $i, Role $studentRole, Role $parentRole): void
    {
        $number = (int) $class->number;
        $studentId = '2026'.str_pad((string) (($number - 1) * self::PER_CLASS + $i), 4, '0', STR_PAD_LEFT);

        if (Student::withTrashed()->where('student_id', $studentId)->exists()) {
            return;
        }

        $female = $i % 2 === 0;
        [$nameEn, $nameBn] = ($female ? self::FEMALE_NAMES : self::MALE_NAMES)[($number + $i) % 6];
        [$fatherEn, $fatherBn] = self::MALE_NAMES[($number * 2 + $i) % 6];

        // The fifth student of Class N (N >= 2) is a sibling of the first of Class N-1.
        $guardianKey = ($i === self::PER_CLASS && $number >= 2)
            ? ($number - 2) * self::PER_CLASS + 1
            : ($number - 1) * self::PER_CLASS + $i;
        $guardianMobile = '01700'.str_pad((string) $guardianKey, 6, '0', STR_PAD_LEFT);

        $guardian = User::firstOrCreate(['username' => $guardianMobile], [
            'name' => $fatherEn,
            'phone' => $guardianMobile,
            'password' => $this->passwordHash(),
            'is_active' => true,
        ]);
        $guardian->assignRole($parentRole);

        $login = User::create([
            'name' => $nameEn,
            'username' => $studentId,
            'password' => $this->passwordHash(),
            'is_active' => true,
        ]);
        $login->assignRole($studentRole);

        $student = Student::create([
            'student_id' => $studentId,
            'name_en' => $nameEn,
            'name_bn' => $nameBn,
            'date_of_birth' => Carbon::create(2026 - 5 - $number, $i * 2, 10)->toDateString(),
            'gender' => $female ? 'female' : 'male',
            'religion' => 'islam',
            'nationality' => 'Bangladeshi',
            'present_address' => 'Dhaka',
            'permanent_address' => 'Dhaka',
            'district' => 'Dhaka',
            'admission_date' => '2026-01-05',
            'status' => Student::STATUS_ACTIVE,
            'father_name_en' => $fatherEn,
            'father_name_bn' => $fatherBn,
            'father_mobile' => $guardianMobile,
            'guardian_relation' => 'father',
            'guardian_name' => $fatherEn,
            'guardian_mobile' => $guardianMobile,
            'guardian_user_id' => $guardian->id,
            'user_id' => $login->id,
        ]);

        $group = $class->hasGroups() ? AcademicGroup::VALUES[($i - 1) % count(AcademicGroup::VALUES)] : null;

        StudentEnrolment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'class_id' => $class->id,
            'section_id' => $section->id,
            'group' => $group,
            'optional_subject_id' => $group ? $this->fourthSubjectId($class, $group) : null,
            'roll_number' => $i,
            'status' => StudentEnrolment::STATUS_ACTIVE,
        ]);
    }

    private function passwordHash(): string
    {
        return $this->passwordHash ??= Hash::make('password');
    }

    private function fourthSubjectId(Classes $class, string $group): ?int
    {
        return ClassSubject::where('class_id', $class->id)
            ->where('type', ClassSubject::TYPE_OPTIONAL)
            ->where(fn ($q) => $q->whereNull('group')->orWhere('group', $group))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('subject_id');
    }
}
