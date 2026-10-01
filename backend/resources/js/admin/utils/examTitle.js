import { banglaNumber } from './banglaNumber'

const ASCII = { '০': '0', '১': '1', '২': '2', '৩': '3', '৪': '4', '৫': '5', '৬': '6', '৭': '7', '৮': '8', '৯': '9' }

// An exam's name with its year, without repeating a year the name already carries (ASCII or
// Bangla digits). The same rule as App\Support\ExamTitle on the server.
export const examTitle = (name, year, bangla = false) => {
  const text = String(name ?? '').trim()
  const y = year === null || year === undefined ? '' : String(year)
  if (y === '') return text
  const shown = bangla ? banglaNumber(y) : y
  if (text === '') return shown
  const ascii = text.replace(/[০-৯]/g, (digit) => ASCII[digit])
  const present = new RegExp(`(?<![0-9])${y.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?![0-9])`).test(ascii)
  return present ? text : `${text} ${shown}`
}
