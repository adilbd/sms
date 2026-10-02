{{--
    One student's marksheet. Expects $result (an ExamResult with student, enrolment, class,
    section.shift and exam.academicYear loaded), $language (bn|en) and $orientation; the
    school comes from the shared $institute. Bangla shows Bangla names and digits.
--}}
@php
    use App\Support\AcademicGroup;
    use App\Support\BanglaNumber;
    use App\Support\ExamTitle;

    $bn = $language === 'bn';
    $t = fn (string $bangla, string $english) => $bn ? $bangla : $english;
    $d = fn ($value) => $bn ? BanglaNumber::format($value) : (string) $value;
    // "148.50" -> "148.5", "90.00" -> "90".
    $num = fn ($value) => $value === null ? '-' : $d((string) (float) $value);
    $pick = fn (?string $banglaName, ?string $englishName) => $bn ? ($banglaName ?: $englishName) : ($englishName ?: $banglaName);

    $exam = $result->exam;
    $student = $result->student;
    $section = $result->section;
    $shift = $section?->shift;
    $units = $result->subjects ?? [];
    $partLabels = ['written' => $t('লিখিত', 'Written'), 'mcq' => $t('বহুনির্বাচনী', 'MCQ'), 'practical' => $t('ব্যবহারিক', 'Practical')];
    $parts = array_keys(array_filter($partLabels, fn ($label, $key) => collect($units)->contains(fn ($unit) => ! empty($unit['parts'][$key])), ARRAY_FILTER_USE_BOTH));
    $address = collect([$institute['village'] ?: $institute['street'], $institute['upazila'], $institute['district']])->filter()->implode(', ');
    $group = $result->enrolment?->group;
    $total = $result->passed_count + $result->failed_count;
    $classTeacher = $result->mainClassTeacher();
    $classTeacherName = $classTeacher ? $pick($classTeacher->name_bn, $classTeacher->name_en) : null;
@endphp

<article class="marksheet mx-auto rounded-lg border border-gray-300 bg-white p-6 text-[13px] leading-snug text-gray-900 {{ $orientation === 'landscape' ? 'max-w-5xl' : 'max-w-3xl' }}"
         style="font-family: 'Noto Sans Bengali', 'Noto Sans', system-ui, sans-serif" lang="{{ $language }}">
    <header class="flex items-center justify-center gap-4 text-center">
        @if ($institute['logo_url'])
            <img src="{{ $institute['logo_url'] }}" alt="" class="h-16 w-16 object-contain">
        @endif
        <div>
            <h2 class="text-xl font-bold">{{ $pick($institute['name_bn'], $institute['name_en']) }}</h2>
            @if ($bn ? $institute['name_en'] : $institute['name_bn'])
                <p class="text-sm font-semibold">{{ $bn ? $institute['name_en'] : $institute['name_bn'] }}</p>
            @endif
            @if ($address)
                <p class="text-xs text-gray-500">{{ $address }}</p>
            @endif
        </div>
    </header>

    <h3 class="my-3 border-y border-gray-900 py-1 text-center text-[15px] font-bold">
        {{ ExamTitle::withYear($pick($exam->name_bn, $exam->name_en), $exam->academicYear->year, $bn) }} – {{ $t('একাডেমিক ট্রান্সক্রিপ্ট', 'Academic transcript') }}
    </h3>

    <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 sm:grid-cols-4">
        <div class="col-span-2 sm:col-span-4">
            <dt class="text-[10px] uppercase tracking-wide text-gray-500">{{ $t('শিক্ষার্থীর নাম', 'Student name') }}</dt>
            <dd class="text-[15px] font-semibold">{{ $pick($student->name_bn, $student->name_en) }}</dd>
        </div>
        <div><dt class="text-[10px] uppercase tracking-wide text-gray-500">{{ $t('আইডি', 'Student ID') }}</dt><dd class="font-semibold">{{ $d($student->student_id) }}</dd></div>
        <div><dt class="text-[10px] uppercase tracking-wide text-gray-500">{{ $t('রোল', 'Roll') }}</dt><dd class="font-semibold">{{ $result->enrolment?->roll_number !== null ? $d($result->enrolment->roll_number) : '-' }}</dd></div>
        <div><dt class="text-[10px] uppercase tracking-wide text-gray-500">{{ $t('শ্রেণি', 'Class') }}</dt><dd class="font-semibold">{{ $pick($result->class?->name_bn, $result->class?->name) }}</dd></div>
        <div><dt class="text-[10px] uppercase tracking-wide text-gray-500">{{ $t('সেকশন', 'Section') }}</dt><dd class="font-semibold">{{ $section?->name ?? '-' }}</dd></div>
        <div><dt class="text-[10px] uppercase tracking-wide text-gray-500">{{ $t('শিফট', 'Shift') }}</dt><dd class="font-semibold">{{ $shift ? $pick($shift->name_bn, $shift->name_en) : '-' }}</dd></div>
        <div><dt class="text-[10px] uppercase tracking-wide text-gray-500">{{ $t('গ্রুপ', 'Group') }}</dt><dd class="font-semibold">{{ $group ? ($bn ? AcademicGroup::LABELS_BN[$group] : AcademicGroup::LABELS_EN[$group]) : '-' }}</dd></div>
    </dl>

    <table class="my-3 w-full border-collapse text-center">
        <thead>
            <tr class="bg-gray-100">
                <th class="border border-gray-400 px-1.5 py-1 text-left">{{ $t('বিষয়', 'Subject') }}</th>
                @foreach ($parts as $part)
                    <th class="border border-gray-400 px-1.5 py-1">{{ $partLabels[$part] }}</th>
                @endforeach
                <th class="border border-gray-400 px-1.5 py-1">{{ $t('মোট', 'Total') }}</th>
                <th class="border border-gray-400 px-1.5 py-1">{{ $t('গ্রেড', 'Grade') }}</th>
                <th class="border border-gray-400 px-1.5 py-1">{{ $t('পয়েন্ট', 'Point') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($units as $unit)
                @php
                    $failed = $unit['grade'] === 'F';
                    $notCounted = $failed && $unit['is_optional'];
                @endphp
                <tr class="{{ $notCounted ? 'text-gray-500' : ($failed ? 'text-red-700' : '') }}">
                    <td class="border border-gray-400 px-1.5 py-1 text-left">
                        {{ $pick($unit['name_bn'], $unit['name_en']) }}
                        @if ($unit['is_combined'])<span class="ml-1 rounded border border-gray-500 px-1 text-[10px] text-gray-700">{{ $t('যুক্ত', 'Combined') }}</span>@endif
                        @if ($unit['is_optional'])<span class="ml-1 rounded border border-gray-500 px-1 text-[10px] text-gray-700">{{ $t('৪র্থ বিষয়', '4th subject') }}</span>@endif
                        @if ($notCounted)<div class="text-[11px]">{{ $t('৪র্থ বিষয় – ফেল হিসেবে গণ্য নয়', '4th subject – not counted as a fail') }}</div>@endif
                    </td>
                    @foreach ($parts as $part)
                        <td class="border border-gray-400 px-1.5 py-1">
                            @if (! empty($unit['parts'][$part]))
                                @if ($unit['is_absent']) - @else {{ $num($unit['parts'][$part]['obtained']) }}<span class="text-gray-500"> /{{ $d($unit['parts'][$part]['full']) }}</span> @endif
                            @endif
                        </td>
                    @endforeach
                    <td class="border border-gray-400 px-1.5 py-1">
                        @if ($unit['is_absent'])
                            {{ collect($unit['papers'])->contains(fn ($paper) => $paper['is_missing']) ? $t('অনুপস্থিত', 'Missing') : $t('অনুপস্থিত', 'Absent') }}
                        @else
                            {{ $num($unit['obtained']) }}<span class="text-gray-500"> /{{ $num($unit['full']) }}</span>
                        @endif
                    </td>
                    <td class="border border-gray-400 px-1.5 py-1 font-bold">{{ $unit['grade'] }}</td>
                    <td class="border border-gray-400 px-1.5 py-1">{{ $d(number_format((float) $unit['point'], 2, '.', '')) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        @foreach ([
            [$t('জিপিএ', 'GPA'), $d(number_format((float) $result->gpa, 2, '.', ''))],
            [$t('গ্রেড', 'Grade'), $result->grade],
            [$t('মোট নম্বর', 'Total marks'), $num($result->total_obtained).' / '.$num($result->total_full)],
            [$t('ফলাফল', 'Result'), $result->is_pass ? $t('উত্তীর্ণ', 'Passed') : $t('অনুত্তীর্ণ', 'Failed')],
            [$t('পাস করা বিষয়', 'Subjects passed'), $bn ? $d($total).' টির মধ্যে '.$d($result->passed_count) : $result->passed_count.' of '.$total],
            [$t('অকৃতকার্য বিষয়', 'Failed subjects'), $d($result->failed_count)],
            [$t('শ্রেণিতে অবস্থান', 'Position in class'), $result->class_position !== null ? $d($result->class_position) : '-'],
            [$t('সেকশনে অবস্থান', 'Position in section'), $result->section_position !== null ? $d($result->section_position) : '-'],
        ] as [$label, $value])
            <div class="rounded border border-gray-400 px-2 py-1.5">
                <span class="block text-[10px] uppercase text-gray-500">{{ $label }}</span>
                <strong class="text-[15px]">{{ $value }}</strong>
            </div>
        @endforeach
    </div>

    <p class="mt-3 text-[11px] text-gray-500">
        {{ $t(
            'জিপিএ হলো আবশ্যিক বিষয়গুলোর পয়েন্টের গড়; ৪র্থ বিষয়ের ২.০০-এর বেশি পয়েন্ট যোগ হয়। গ্রেডিং: A+ ৮০–১০০ (৫.০০), A ৭০–৭৯ (৪.০০), A- ৬০–৬৯ (৩.৫০), B ৫০–৫৯ (৩.০০), C ৪০–৪৯ (২.০০), D ৩৩–৩৯ (১.০০), F ০–৩২ (০.০০)।',
            'GPA is the average of the compulsory subject points; points of the 4th subject above 2.00 are added. Grading: A+ 80–100 (5.00), A 70–79 (4.00), A- 60–69 (3.50), B 50–59 (3.00), C 40–49 (2.00), D 33–39 (1.00), F 0–32 (0.00).'
        ) }}
    </p>

    <footer class="mt-12 grid grid-cols-3 gap-8 text-center text-xs">
        <div><span class="mb-1 block border-t border-gray-900"></span>@if ($classTeacherName)<strong class="block">{{ $classTeacherName }}</strong>@endif{{ $t('শ্রেণি শিক্ষক', 'Class teacher') }}</div>
        <div><span class="mb-1 block border-t border-gray-900"></span>{{ $t('প্রধান শিক্ষক', 'Head teacher') }}</div>
        <div><span class="mb-1 block border-t border-gray-900"></span>{{ $t('অভিভাবক', 'Guardian') }}</div>
    </footer>
</article>
