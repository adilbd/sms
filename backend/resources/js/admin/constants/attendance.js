// Attendance statuses (see App\Models\Attendance::STATUSES). `next` is the order a tap or
// click cycles through on the mark sheet.
export const ATTENDANCE_STATUSES = [
  { value: 'present', label: 'Present', short: 'P', classes: 'bg-green-100 text-green-800 border-green-300' },
  { value: 'absent', label: 'Absent', short: 'A', classes: 'bg-red-100 text-red-800 border-red-300' },
  { value: 'late', label: 'Late', short: 'L', classes: 'bg-yellow-100 text-yellow-800 border-yellow-300' },
  { value: 'leave', label: 'Leave', short: 'Lv', classes: 'bg-blue-100 text-blue-800 border-blue-300' },
]

export const ATTENDANCE_STATUS = Object.fromEntries(ATTENDANCE_STATUSES.map((status) => [status.value, status]))

export const nextAttendanceStatus = (value) => {
  const index = ATTENDANCE_STATUSES.findIndex((status) => status.value === value)
  return ATTENDANCE_STATUSES[(index + 1) % ATTENDANCE_STATUSES.length].value
}
