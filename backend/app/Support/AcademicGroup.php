<?php

namespace App\Support;

/**
 * From Class 9, each student belongs to a group (see App\Models\Classes::hasGroups()).
 * Not a table: a stateless registry of the three groups and their labels, mirroring
 * App\Models\Staff's position/status constants.
 */
class AcademicGroup
{
    public const SCIENCE = 'science';

    public const BUSINESS_STUDIES = 'business_studies';

    public const HUMANITIES = 'humanities';

    public const VALUES = [self::SCIENCE, self::BUSINESS_STUDIES, self::HUMANITIES];

    public const LABELS_EN = [
        self::SCIENCE => 'Science',
        self::BUSINESS_STUDIES => 'Business Studies',
        self::HUMANITIES => 'Humanities',
    ];

    public const LABELS_BN = [
        self::SCIENCE => 'বিজ্ঞান',
        self::BUSINESS_STUDIES => 'ব্যবসায় শিক্ষা',
        self::HUMANITIES => 'মানবিক',
    ];
}
