import { MONTHS_BN, MONTHS_EN } from '@/constants/fees'

// A due's period for people: "2026-10" -> "October 2026" (or in Bangla without digits
// conversion; callers convert digits with banglaNumber), "one_time" -> "One-time",
// "exam:5" -> "Exam".
export const periodLabel = (period, bangla = false) => {
  if (!period) return ''
  if (period === 'one_time') return bangla ? 'এককালীন' : 'One-time'
  if (period.startsWith('exam:')) return bangla ? 'পরীক্ষা' : 'Exam'

  const [year, month] = period.split('-')
  const names = bangla ? MONTHS_BN : MONTHS_EN

  return `${names[Number(month) - 1] ?? month} ${year}`
}

// A fee head's name in the chosen language, falling back to the other.
export const headName = (head, bangla = false) =>
  (bangla ? head?.name_bn || head?.name_en : head?.name_en || head?.name_bn) || ''

// A student's name, Bangla first when asked.
export const studentName = (student, bangla = false) =>
  (bangla ? student?.name_bn || student?.name_en : student?.name_en || student?.name_bn) || ''

// An ISO timestamp as an Asia/Dhaka date and time ("15 Oct 2026, 12:00 pm").
export const dhakaDateTime = (iso) =>
  iso
    ? new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Asia/Dhaka',
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
      }).format(new Date(iso))
    : ''

// The Asia/Dhaka calendar date (YYYY-MM-DD) of an ISO timestamp.
export const dhakaDate = (iso) =>
  new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(iso))

// First error message of a 422 response, for a field.
export const firstError = (errors, field) => errors?.[field]?.[0]
