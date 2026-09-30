// Class levels and academic groups (see CLAUDE.md's Domain section and
// App\Models\Classes / App\Support\AcademicGroup on the backend).

export const LEVELS = [
  { value: 'primary', label: 'Primary (Class 1–5)' },
  { value: 'junior_secondary', label: 'Junior Secondary (Class 6–8)' },
  { value: 'secondary', label: 'Secondary (Class 9–10)' },
  { value: 'higher_secondary', label: 'Higher Secondary (Class 11–12)' },
]

export const LEVEL_LABELS = Object.fromEntries(LEVELS.map((level) => [level.value, level.label]))

// From Class 9, each section may carry a group.
export const GROUPS_FROM_NUMBER = 9

export const GROUPS = [
  { value: 'science', label: 'Science' },
  { value: 'business_studies', label: 'Business Studies' },
  { value: 'humanities', label: 'Humanities' },
]

export const GROUP_LABELS = Object.fromEntries(GROUPS.map((group) => [group.value, group.label]))
