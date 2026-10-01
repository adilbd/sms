<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            InstituteSettingsSeeder::class,
            PublicContentSeeder::class,
            PageSeeder::class,
            GallerySeeder::class,
            ShiftSeeder::class,
            StaffSeeder::class,
            StaffLoginSeeder::class,
            ClassSeeder::class,
            SubjectSeeder::class,
            CurriculumSeeder::class,
            AcademicYearSeeder::class,
            HolidaySeeder::class,
            SectionSeeder::class,
            StudentSeeder::class,
            AttendanceSeeder::class,
            ExamSeeder::class,
            MenuSeeder::class,
        ]);
    }
}
