{{--
    The applicant's printable copy of an application. Expects $application (with round,
    class and shift loaded), $photoUrl (a temporary signed URL or null) and optionally
    $hideOnScreen (render it for print only). It never shows the admin note or the test
    score, and the birth registration number is masked. The school comes from the shared
    $institute. The page including it must be noindex/no-store (PortalHeaders).
--}}
@php
    use App\Models\AdmissionApplication as Application;
    use App\Support\AcademicGroup;
    use App\Support\BanglaDate;
    use App\Support\BanglaNumber;

    $hideOnScreen = $hideOnScreen ?? false;
    $genderLabels = ['male' => 'ছেলে (Male)', 'female' => 'মেয়ে (Female)', 'other' => 'অন্যান্য (Other)'];
    $religionLabels = ['islam' => 'ইসলাম (Islam)', 'hinduism' => 'হিন্দু (Hinduism)', 'buddhism' => 'বৌদ্ধ (Buddhism)', 'christianity' => 'খ্রিষ্টান (Christianity)', 'other' => 'অন্যান্য (Other)'];
    $relationLabels = ['father' => 'পিতা (Father)', 'mother' => 'মাতা (Mother)', 'other' => 'অন্যান্য (Other)'];
    $names = fn (?string $bn, ?string $en) => collect([$bn, $en])->filter()->unique()->implode(' / ');
    $class = $application->class;
    $shift = $application->shift;
    $address = collect([$institute['village'] ?: $institute['street'], $institute['upazila'], $institute['district']])->filter()->implode(', ');
    $rows = [
        ['শ্রেণি (Class)', $class->name_bn ?: $class->name],
        ['গ্রুপ (Group)', $application->group ? AcademicGroup::LABELS_BN[$application->group].' ('.AcademicGroup::LABELS_EN[$application->group].')' : null],
        ['শিফট (Shift)', $shift ? $names($shift->name_bn, $shift->name_en) : null],
        ['শিক্ষার্থীর নাম (Student)', $names($application->name_bn, $application->name_en)],
        ['জন্ম তারিখ (Date of birth)', BanglaDate::date($application->date_of_birth->toDateString())],
        ['লিঙ্গ (Gender)', $genderLabels[$application->gender] ?? null],
        ['ধর্ম (Religion)', $religionLabels[$application->religion] ?? null],
        ['রক্তের গ্রুপ (Blood group)', $application->blood_group],
        ['জন্ম নিবন্ধন নম্বর (Birth registration no.)', $application->maskedBirthRegistration()],
        ['পিতার নাম (Father)', $names($application->father_name_bn, $application->father_name_en)],
        ['মাতার নাম (Mother)', $names($application->mother_name_bn, $application->mother_name_en)],
        ['অভিভাবক (Guardian)', trim($application->guardian_name.' — '.($relationLabels[$application->guardian_relation] ?? ''), ' —')],
        ['অভিভাবকের মোবাইল (Guardian mobile)', BanglaNumber::format($application->guardian_mobile)],
        ['বর্তমান ঠিকানা (Present address)', $application->present_address],
        ['জেলা (District)', $application->district],
        ['পূর্ববর্তী স্কুল (Previous school)', $application->previous_school],
    ];
@endphp

<style>
    @page { size: A4 portrait; margin: 12mm; }
    @media print {
        body > header, body > footer, body > a.sr-only, .no-print { display: none !important; }
        .admission-copy-wrap { display: block !important; margin: 0 !important; padding: 0 !important; }
        .admission-copy { border: none !important; border-radius: 0 !important; padding: 0 !important; margin: 0 !important; max-width: none !important; break-inside: avoid; }
    }
    @if ($hideOnScreen)
        @media screen { .admission-copy-wrap { display: none; } }
    @endif
</style>

<div class="admission-copy-wrap">
<article class="admission-copy mx-auto max-w-3xl rounded-lg border border-gray-300 bg-white p-6 text-[13px] leading-snug text-gray-900" lang="bn">
    <header class="flex items-center justify-center gap-4 text-center">
        @if ($institute['logo_url'])
            <img src="{{ $institute['logo_url'] }}" alt="" class="h-16 w-16 object-contain">
        @endif
        <div>
            <p class="text-xl font-bold">{{ $institute['name_bn'] ?: $institute['name_en'] }}</p>
            @if ($institute['name_bn'] && $institute['name_en'])
                <p class="text-sm font-semibold">{{ $institute['name_en'] }}</p>
            @endif
            @if ($address)
                <p class="text-xs text-gray-500">{{ $address }}</p>
            @endif
        </div>
    </header>

    <p class="my-3 border-y border-gray-900 py-1 text-center text-[15px] font-bold">
        ভর্তির আবেদনের কপি (Application copy) – {{ $application->round->name_bn ?: $application->round->name_en }}
    </p>

    <div class="flex items-start justify-between gap-4">
        <dl class="space-y-1">
            <div>
                <dt class="text-xs text-gray-500">আবেদন নম্বর (Application number)</dt>
                <dd class="text-lg font-bold tracking-wide">{{ $application->application_no }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">জমার সময় (Submitted)</dt>
                <dd class="font-semibold">{{ BanglaDate::dateTime($application->created_at) }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">বর্তমান অবস্থা (Status)</dt>
                <dd class="font-semibold">{{ Application::STATUS_LABELS_BN[$application->status] }} ({{ Application::STATUS_LABELS_EN[$application->status] }})</dd>
            </div>
            @if ($application->test_at)
                <div>
                    <dt class="text-xs text-gray-500">পরীক্ষা / সাক্ষাৎকারের সময় (Test or interview)</dt>
                    <dd class="font-semibold">{{ BanglaDate::dateTime($application->test_at) }}@if ($application->test_venue), {{ $application->test_venue }}@endif</dd>
                </div>
            @endif
        </dl>
        @if ($photoUrl)
            <img src="{{ $photoUrl }}" alt="শিক্ষার্থীর ছবি (Applicant photo)" class="h-32 w-28 shrink-0 border border-gray-400 object-cover">
        @endif
    </div>

    <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-2">
        @foreach ($rows as [$label, $value])
            @if (filled($value))
                <div>
                    <dt class="text-xs text-gray-500">{{ $label }}</dt>
                    <dd class="font-semibold">{{ $value }}</dd>
                </div>
            @endif
        @endforeach
    </dl>

    <p class="mt-5 rounded border border-gray-400 px-3 py-2 text-xs">
        পরীক্ষা বা সাক্ষাৎকারের দিন এই কপিটি সঙ্গে আনুন।
        (Please bring this copy on the day of the test or interview.)
    </p>

    <div class="mt-12 w-56 border-t border-gray-900 pt-1 text-center text-xs">অভিভাবকের স্বাক্ষর (Guardian's signature)</div>
</article>
</div>
