// Exam types and statuses (App\Models\Exam on the backend).

export const EXAM_TYPES = [
  { value: 'class_test', label: 'Class Test' },
  { value: 'half_yearly', label: 'Half Yearly' },
  { value: 'test', label: 'Test' },
  { value: 'model_test', label: 'Model Test' },
  { value: 'pre_test', label: 'Pre-Test' },
  { value: 'annual', label: 'Annual' },
]

export const EXAM_TYPE_LABELS = Object.fromEntries(EXAM_TYPES.map((type) => [type.value, type.label]))

export const EXAM_STATUS_LABELS = {
  draft: 'Draft',
  marks_entry: 'Mark entry open',
  processed: 'Processed',
  published: 'Published',
}

export const EXAM_STATUS_BADGES = {
  draft: 'badge-warning',
  marks_entry: 'badge-info',
  processed: 'badge-info',
  published: 'badge-success',
}

// The marked parts of a subject, in column order (`{part}_full` / `{part}_pass` on the API).
export const MARK_PARTS = [
  { key: 'written', label: 'Written' },
  { key: 'mcq', label: 'MCQ' },
  { key: 'practical', label: 'Practical' },
]
