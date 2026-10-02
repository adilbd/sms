// Admission application statuses (App\Models\AdmissionApplication) and which moves the
// API allows from each; the server enforces the same table.
export const STATUS_LABELS = {
  submitted: 'Submitted',
  under_review: 'Under review',
  test_scheduled: 'Test scheduled',
  approved: 'Approved',
  waitlisted: 'Waitlisted',
  rejected: 'Rejected',
  admitted: 'Admitted',
}

export const STATUS_BADGES = {
  submitted: 'badge-info',
  under_review: 'badge-info',
  test_scheduled: 'badge-warning',
  approved: 'badge-success',
  waitlisted: 'badge-warning',
  rejected: 'badge-danger',
  admitted: 'badge-success',
}

export const TRANSITIONS = {
  submitted: ['under_review', 'rejected'],
  under_review: ['test_scheduled', 'approved', 'waitlisted', 'rejected'],
  test_scheduled: ['test_scheduled', 'approved', 'waitlisted', 'rejected'],
  waitlisted: ['approved', 'rejected'],
  approved: [],
  rejected: [],
  admitted: [],
}

export const ACTION_LABELS = {
  under_review: 'Start review',
  test_scheduled: 'Schedule test / interview',
  approved: 'Approve',
  waitlisted: 'Waitlist',
  rejected: 'Reject',
}
