// The school's local calendar date. Dates are Asia/Dhaka calendar dates (YYYY-MM-DD), never
// the browser's own time zone, so "today" matches what the server uses.
const dhakaParts = () =>
  new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())

export const todayInDhaka = () => dhakaParts()

export const currentMonthInDhaka = () => dhakaParts().slice(0, 7)

// "2026-10-03" -> 3
export const dayOfMonth = (date) => Number(date.slice(8, 10))

// "2026-10-03" -> "Sat"
export const weekdayShort = (date) =>
  new Intl.DateTimeFormat('en-US', { timeZone: 'UTC', weekday: 'short' }).format(new Date(`${date}T00:00:00Z`))
