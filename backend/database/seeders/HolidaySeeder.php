<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Holiday;
use Illuminate\Database\Seeder;

/**
 * Bangladesh public holidays of 2026 that fall on a fixed date. The moveable ones (Eid,
 * Ashura, Buddha Purnima and so on, which follow the lunar calendar) are added by an admin
 * under Academic > Holidays. Weekly holidays (Friday) are an institute setting, not rows.
 * Matched by date, so re-running never duplicates; needs AcademicYearSeeder first.
 */
class HolidaySeeder extends Seeder
{
    private const HOLIDAYS = [
        '2026-02-21' => ['Shaheed Dibosh and International Mother Language Day', 'শহীদ দিবস ও আন্তর্জাতিক মাতৃভাষা দিবস'],
        '2026-03-26' => ['Independence Day', 'স্বাধীনতা দিবস'],
        '2026-04-14' => ['Pohela Boishakh', 'পহেলা বৈশাখ'],
        '2026-05-01' => ['May Day', 'মে দিবস'],
        '2026-12-16' => ['Victory Day', 'বিজয় দিবস'],
        '2026-12-25' => ['Christmas Day', 'বড়দিন'],
    ];

    public function run(): void
    {
        $year = AcademicYear::where('year', 2026)->first();

        if (! $year) {
            return;
        }

        foreach (self::HOLIDAYS as $date => [$english, $bangla]) {
            Holiday::updateOrCreate(
                ['date' => $date],
                ['name_en' => $english, 'name_bn' => $bangla, 'academic_year_id' => $year->id]
            );
        }
    }
}
