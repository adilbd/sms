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

    public function run(): void
    {
        $subjectIds = Subject::pluck('id', 'code');

        foreach (Classes::orderBy('number')->get() as $class) {
            if ($class->number === null || $class->curriculum()->exists()) {
                continue;
            }

            $position = 0;

            foreach ($this->rowsFor((int) $class->number) as [$code, $group, $type]) {
                if (! $subjectIds->has($code)) {
                    continue;
                }

                $class->curriculum()->create([
                    'subject_id' => $subjectIds[$code],
                    'group' => $group,
                    'type' => $type,
                    'sort_order' => $position++,
                ]);
            }
        }
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
            $number <= 10 => [
                ...$common(['BAN1', 'BAN2', 'ENG1', 'ENG2', 'MATH', 'ICT', 'REL']),
                ...$group(AcademicGroup::SCIENCE, ['PHY', 'CHE'], ['HMATH', 'BIO', 'AGR']),
                ...$group(AcademicGroup::BUSINESS_STUDIES, ['ACC', 'FBK', 'BEN'], ['AGR']),
                ...$group(AcademicGroup::HUMANITIES, ['HIS', 'GEO', 'CIV'], ['AGR', 'ECO']),
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
