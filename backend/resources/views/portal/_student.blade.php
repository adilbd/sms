{{-- The student's summary card: photo, name, class, section, shift, roll and group. Expects $student. --}}
@php
    use App\Support\AcademicGroup;
    use App\Support\BanglaNumber;

    $enrolment = $student->currentEnrolment;
    $class = $enrolment?->class;
    $section = $enrolment?->section;
    $shift = $section?->shift;
    $group = $enrolment?->group;
    $rows = [
        'আইডি' => BanglaNumber::format($student->student_id),
        'শ্রেণি' => $class ? ($class->name_bn ?: $class->name) : '-',
        'সেকশন' => $section?->name ?? '-',
        'শিফট' => $shift ? ($shift->name_bn ?: $shift->name_en) : '-',
        'রোল' => $enrolment?->roll_number !== null ? BanglaNumber::format($enrolment->roll_number) : '-',
        'গ্রুপ' => $group ? AcademicGroup::LABELS_BN[$group] : '-',
    ];
@endphp
<section class="card-public flex flex-col gap-4 p-5 sm:flex-row sm:items-center" aria-label="শিক্ষার্থীর তথ্য">
    @if ($student->photoUrl())
        <img src="{{ $student->photoUrl() }}" alt="" class="h-24 w-24 rounded-xl object-cover">
    @else
        <div class="flex h-24 w-24 items-center justify-center rounded-xl bg-primary-50 text-3xl font-bold text-primary-700" aria-hidden="true">{{ mb_substr($student->name_bn ?: $student->name_en, 0, 1) }}</div>
    @endif
    <div class="min-w-0 flex-1">
        <p class="text-xl font-bold text-gray-900">{{ $student->name_bn ?: $student->name_en }}</p>
        @if ($student->name_bn && $student->name_en)
            <p class="text-sm text-gray-500">{{ $student->name_en }}</p>
        @endif
        <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
            @foreach ($rows as $label => $value)
                <div><dt class="text-xs text-gray-500">{{ $label }}</dt><dd class="font-semibold text-gray-900">{{ $value }}</dd></div>
            @endforeach
        </dl>
    </div>
</section>
