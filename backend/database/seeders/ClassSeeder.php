<?php

namespace Database\Seeders;

use App\Models\Classes;
use Illuminate\Database\Seeder;

/**
 * Class 1-12 (NCTB curriculum). Matched by number, so re-running this seeder never
 * duplicates rows. See docs/tasks/academic-structure.md.
 */
class ClassSeeder extends Seeder
{
    private const NAMES_BN = [
        1 => 'প্রথম শ্রেণি',
        2 => 'দ্বিতীয় শ্রেণি',
        3 => 'তৃতীয় শ্রেণি',
        4 => 'চতুর্থ শ্রেণি',
        5 => 'পঞ্চম শ্রেণি',
        6 => 'ষষ্ঠ শ্রেণি',
        7 => 'সপ্তম শ্রেণি',
        8 => 'অষ্টম শ্রেণি',
        9 => 'নবম শ্রেণি',
        10 => 'দশম শ্রেণি',
        11 => 'একাদশ শ্রেণি',
        12 => 'দ্বাদশ শ্রেণি',
    ];

    public function run(): void
    {
        foreach (range(Classes::MIN_NUMBER, Classes::MAX_NUMBER) as $number) {
            Classes::updateOrCreate(['number' => $number], [
                'name' => "Class {$number}",
                'name_bn' => self::NAMES_BN[$number],
                'code' => 'C'.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
                'display_order' => $number,
                'is_active' => true,
            ]);
        }
    }
}
