<?php

namespace Database\Seeders;

use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Subject;
use App\Support\AcademicGroup;
use Illuminate\Database\Seeder;

/**
 * A sample NCTB curriculum for Class 1-12, a starting point for admins to edit. Only a
 * class whose curriculum is empty is seeded, so re-running never overwrites their edits.
 * Needs ClassSeeder and SubjectSeeder first. Writes through the models directly.
 */
class CurriculumSeeder extends Seeder
{
    private const C = ClassSubject::TYPE_COMPULSORY;

    private const O = ClassSubject::TYPE_OPTIONAL;

    /** Subject code => paper group: the two papers are graded as one subject. */
    private const PAPER_GROUPS = [
        'BAN1' => 'bangla',
        'BAN2' => 'bangla',
        'ENG1' => 'english',
        'ENG2' => 'english',
    ];

    /** Subjects with a practical part at SSC/HSC: written 50, MCQ 25, practical 25. */
    private const PRACTICAL_CODES = ['PHY', 'CHE', 'BIO', 'ICT', 'AGR'];

    public function run(): void
    {
        $subjects = Subject::get()->keyBy('code');

        foreach (Classes::orderBy('number')->get() as $class) {
            if ($class->number === null || $class->curriculum()->exists()) {
                continue;
            }

            $position = 0;

            foreach ($this->rowsFor((int) $class->number) as [$code, $group, $type]) {
                if (! $subjects->has($code)) {
                    continue;
                }

                $class->curriculum()->create([
                    'subject_id' => $subjects[$code]->id,
                    'group' => $group,
                    'type' => $type,
                    'sort_order' => $position++,
                    ...$this->marksFor((int) $class->number, $subjects[$code]),
                    'paper_group' => self::PAPER_GROUPS[$code] ?? null,
                ]);
            }
        }
    }

    /**
     * The marks parts of a row. Class 9-12 use the SSC/HSC split: a practical subject has
     * written 50/17, MCQ 25/8 and practical 25/8, any other 70/23 written and 30/10 MCQ.
     * Below Class 9 the subject's own total and pass marks are the written part.
     *
     * @return array<string, int|null>
     */
    private function marksFor(int $number, Subject $subject): array
    {
        if ($number < 9) {
            return ['written_full' => $subject->total_marks, 'written_pass' => $subject->pass_marks];
        }

        if (in_array($subject->code, self::PRACTICAL_CODES, true)) {
            return [
                'written_full' => 50, 'written_pass' => 17,
                'mcq_full' => 25, 'mcq_pass' => 8,
                'practical_full' => 25, 'practical_pass' => 8,
            ];
        }

        return ['written_full' => 70, 'written_pass' => 23, 'mcq_full' => 30, 'mcq_pass' => 10];
    }

    /**
     * @return list<array{string, ?string, string}> [subject code, group, type]
     */
    private function rowsFor(int $number): array
    {
        $common = fn (array $codes) => array_map(fn ($code) => [$code, null, self::C], $codes);
        $group = fn (string $group, array $compulsory, array $optional) => [
            ...array_map(fn ($code) => [$code, $group, self::C], $compulsory),
            ...array_map(fn ($code) => [$code, $group, self::O], $optional),
        ];

        return match (true) {
            $number <= 2 => $common(['BAN', 'ENG', 'MATH', 'REL']),
            $number <= 5 => $common(['BAN', 'ENG', 'MATH', 'SCI', 'BGS', 'REL']),
            $number <= 8 => $common(['BAN', 'ENG', 'MATH', 'SCI', 'BGS', 'ICT', 'REL', 'AGR']),
            // SSC: Science studies Physics, Chemistry and Biology, with Higher Mathematics or
            // Agriculture as the 4th subject. Per the NCTB SSC scheme, Bangladesh & Global Studies
            // is group-compulsory for Science only, and General Science for Business Studies and
            // Humanities.
            $number <= 10 => [
                ...$common(['BAN1', 'BAN2', 'ENG1', 'ENG2', 'MATH', 'ICT', 'REL']),
                ...$group(AcademicGroup::SCIENCE, ['PHY', 'CHE', 'BIO', 'BGS'], ['HMATH', 'AGR']),
                ...$group(AcademicGroup::BUSINESS_STUDIES, ['ACC', 'FBK', 'BEN', 'SCI'], ['AGR']),
                ...$group(AcademicGroup::HUMANITIES, ['HIS', 'GEO', 'CIV', 'SCI'], ['AGR', 'ECO']),
            ],
            default => [
                ...$common(['BAN1', 'BAN2', 'ENG1', 'ENG2', 'ICT']),
                ...$group(AcademicGroup::SCIENCE, ['PHY', 'CHE'], ['HMATH', 'BIO', 'AGR']),
                ...$group(AcademicGroup::BUSINESS_STUDIES, ['ACC', 'FBK', 'BEN'], ['AGR']),
                ...$group(AcademicGroup::HUMANITIES, ['ECO', 'HIS', 'CIV', 'GEO'], ['AGR']),
            ],
        };
    }
}
