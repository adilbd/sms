<?php

namespace Database\Seeders;

use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * Sample NCTB subjects with Bangla names. Matched by code, so re-running this seeder
 * never duplicates rows. See docs/tasks/class-subject-curriculum.md.
 */
class SubjectSeeder extends Seeder
{
    /** code => [name, name_bn, type] */
    public const SUBJECTS = [
        'BAN' => ['Bangla', 'বাংলা', 'theory'],
        'BAN1' => ['Bangla 1st Paper', 'বাংলা ১ম পত্র', 'theory'],
        'BAN2' => ['Bangla 2nd Paper', 'বাংলা ২য় পত্র', 'theory'],
        'ENG' => ['English', 'ইংরেজি', 'theory'],
        'ENG1' => ['English 1st Paper', 'ইংরেজি ১ম পত্র', 'theory'],
        'ENG2' => ['English 2nd Paper', 'ইংরেজি ২য় পত্র', 'theory'],
        'MATH' => ['Mathematics', 'গণিত', 'theory'],
        'SCI' => ['Science', 'বিজ্ঞান', 'both'],
        'BGS' => ['Bangladesh & Global Studies', 'বাংলাদেশ ও বিশ্বপরিচয়', 'theory'],
        'ICT' => ['ICT', 'তথ্য ও যোগাযোগ প্রযুক্তি', 'both'],
        'REL' => ['Religion & Moral Education', 'ধর্ম ও নৈতিক শিক্ষা', 'theory'],
        'PHY' => ['Physics', 'পদার্থবিজ্ঞান', 'both'],
        'CHE' => ['Chemistry', 'রসায়ন', 'both'],
        'BIO' => ['Biology', 'জীববিজ্ঞান', 'both'],
        'HMATH' => ['Higher Mathematics', 'উচ্চতর গণিত', 'theory'],
        'ACC' => ['Accounting', 'হিসাববিজ্ঞান', 'theory'],
        'FBK' => ['Finance & Banking', 'ফিন্যান্স ও ব্যাংকিং', 'theory'],
        'BEN' => ['Business Entrepreneurship', 'ব্যবসায় উদ্যোগ', 'theory'],
        'HIS' => ['History', 'ইতিহাস', 'theory'],
        'GEO' => ['Geography', 'ভূগোল ও পরিবেশ', 'theory'],
        'CIV' => ['Civics', 'পৌরনীতি ও নাগরিকতা', 'theory'],
        'ECO' => ['Economics', 'অর্থনীতি', 'theory'],
        'AGR' => ['Agriculture Studies', 'কৃষিশিক্ষা', 'both'],
    ];

    public function run(): void
    {
        foreach (self::SUBJECTS as $code => [$name, $nameBn, $type]) {
            // Soft-deleted rows are matched too, so a re-run can't collide with them on
            // the unique code.
            $subject = Subject::withTrashed()->where('code', $code)->first() ?? new Subject(['code' => $code]);

            $subject->fill(['name' => $name, 'name_bn' => $nameBn, 'type' => $type]);
            $subject->save();
        }
    }
}
