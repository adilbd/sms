<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * The registry for the "institute" settings group: every key that
 * InstituteSettingsService reads and writes, grouped for the admin form, plus the
 * validation rules for UpdateInstituteSettingsRequest. Adding a key means adding it
 * here and nowhere else has to change (the service, resource and cache are generic
 * over the key list).
 */
class InstituteSettings
{
    public const GROUP_IDENTITY = 'identity';

    public const GROUP_BRANDING = 'branding';

    public const GROUP_CONTACT = 'contact';

    public const GROUP_ADDRESS = 'address';

    public const GROUP_LOCATION = 'location';

    public const GROUP_SOCIAL = 'social';

    public const GROUP_ATTENDANCE = 'attendance';

    /** The weekday names `weekly_holidays` accepts (lowercase English, like Carbon's dayName). */
    public const WEEKDAYS = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    /** What `weekly_holidays` is when unset: Friday is the weekly holiday in Bangladesh. */
    public const DEFAULT_WEEKLY_HOLIDAYS = ['friday'];

    /** Bangladesh education boards (NCTB-affiliated). */
    public const EDUCATION_BOARDS = [
        'dhaka', 'rajshahi', 'cumilla', 'jashore', 'chattogram',
        'barishal', 'sylhet', 'dinajpur', 'mymensingh', 'madrasah', 'technical',
    ];

    /** Bangladesh administrative divisions. */
    public const DIVISIONS = [
        'barishal', 'chattogram', 'dhaka', 'khulna',
        'mymensingh', 'rajshahi', 'rangpur', 'sylhet',
    ];

    /** Keys whose value is a stored file path, exposed only as a computed "{key}_url". */
    public const FILE_KEYS = ['logo', 'favicon'];

    /**
     * The weekdays in a stored `weekly_holidays` value (JSON), in week order, or the
     * default when it is unset or holds no valid day.
     *
     * @return list<string>
     */
    public static function weeklyHolidays(?string $stored): array
    {
        $decoded = json_decode((string) $stored, true);
        $days = is_array($decoded) ? array_values(array_intersect(self::WEEKDAYS, $decoded)) : [];

        return $days !== [] ? $days : self::DEFAULT_WEEKLY_HOLIDAYS;
    }

    /**
     * @return array<string, string> key => group, in display order
     */
    public static function keys(): array
    {
        return [
            'name_en' => self::GROUP_IDENTITY,
            'name_bn' => self::GROUP_IDENTITY,
            'short_name' => self::GROUP_IDENTITY,
            'motto' => self::GROUP_IDENTITY,
            'established_year' => self::GROUP_IDENTITY,
            'eiin' => self::GROUP_IDENTITY,
            'institute_code' => self::GROUP_IDENTITY,
            'mpo_code' => self::GROUP_IDENTITY,
            'education_board' => self::GROUP_IDENTITY,

            'logo' => self::GROUP_BRANDING,
            'favicon' => self::GROUP_BRANDING,

            'phone' => self::GROUP_CONTACT,
            'telephone' => self::GROUP_CONTACT,
            'email' => self::GROUP_CONTACT,
            'office_hours' => self::GROUP_CONTACT,

            'village' => self::GROUP_ADDRESS,
            'ward' => self::GROUP_ADDRESS,
            'union' => self::GROUP_ADDRESS,
            'post_office' => self::GROUP_ADDRESS,
            'post_code' => self::GROUP_ADDRESS,
            'upazila' => self::GROUP_ADDRESS,
            'district' => self::GROUP_ADDRESS,
            'division' => self::GROUP_ADDRESS,

            'latitude' => self::GROUP_LOCATION,
            'longitude' => self::GROUP_LOCATION,

            'facebook_url' => self::GROUP_SOCIAL,
            'youtube_url' => self::GROUP_SOCIAL,

            // Stored as a JSON list of weekday names.
            'weekly_holidays' => self::GROUP_ATTENDANCE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function keyNames(): array
    {
        return array_keys(self::keys());
    }

    /**
     * Validation rules for every key except the two file uploads (logo, favicon),
     * which UpdateInstituteSettingsRequest validates as files, not strings.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $currentYear = (int) now()->format('Y');

        return [
            'name_en' => ['sometimes', 'required', 'string', 'max:255'],
            'name_bn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'short_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'motto' => ['sometimes', 'nullable', 'string', 'max:255'],
            'established_year' => ['sometimes', 'nullable', 'integer', "between:1800,{$currentYear}"],
            'eiin' => ['sometimes', 'nullable', 'digits:6'],
            'institute_code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mpo_code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'education_board' => ['sometimes', 'nullable', Rule::in(self::EDUCATION_BOARDS)],

            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'telephone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'office_hours' => ['sometimes', 'nullable', 'string', 'max:255'],

            'village' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ward' => ['sometimes', 'nullable', 'string', 'max:255'],
            'union' => ['sometimes', 'nullable', 'string', 'max:255'],
            'post_office' => ['sometimes', 'nullable', 'string', 'max:255'],
            'post_code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'upazila' => ['sometimes', 'nullable', 'string', 'max:255'],
            'district' => ['sometimes', 'nullable', 'string', 'max:255'],
            'division' => ['sometimes', 'nullable', Rule::in(self::DIVISIONS)],

            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],

            'facebook_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'youtube_url' => ['sometimes', 'nullable', 'url', 'max:255'],

            // Days with no school every week. Never empty: a school that meets six days
            // still has one weekly holiday, and an empty list can't be sent as form data.
            'weekly_holidays' => ['sometimes', 'array', 'min:1', 'max:7'],
            'weekly_holidays.*' => ['string', 'distinct', Rule::in(self::WEEKDAYS)],
        ];
    }
}
