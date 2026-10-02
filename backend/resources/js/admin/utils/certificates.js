import { banglaDate } from '@/utils/banglaDate'
import { banglaNumber } from '@/utils/banglaNumber'

// Certificate types, as the API names them (App\Models\Certificate::TYPES).
export const CERTIFICATE_TYPES = [
  { value: 'testimonial', label: 'Testimonial', bn: 'প্রশংসাপত্র', prefix: 'TES' },
  { value: 'transfer', label: 'Transfer certificate', bn: 'ছাড়পত্র (টিসি)', prefix: 'TC' },
  { value: 'study', label: 'Study certificate', bn: 'অধ্যয়ন সনদ', prefix: 'STU' },
  { value: 'character', label: 'Character certificate', bn: 'চারিত্রিক সনদ', prefix: 'CHR' },
]

export const TYPE_LABELS = Object.fromEntries(CERTIFICATE_TYPES.map((type) => [type.value, type.label]))

// The default conduct text the server stores when none is given (CertificateService::DEFAULT_CONDUCT).
export const DEFAULT_CONDUCT_BN = 'ভালো'

const ENGLISH_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']

// "2026-10-15" -> "15 October 2026" (a plain calendar date, no time zone shift).
export const englishDate = (ymd) => {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd || '')
  return match ? `${Number(match[3])} ${ENGLISH_MONTHS[Number(match[2]) - 1]} ${match[1]}` : ''
}

// A calendar date in the printed language: "১৫ অক্টোবর ২০২৬" or "15 October 2026".
export const longDate = (ymd, bangla) => {
  if (!ymd) return ''
  return bangla ? banglaDate(`${ymd.slice(0, 10)}T06:00:00+06:00`) : englishDate(ymd)
}

// Digits in the printed language.
export const digits = (value, bangla) => (value === null || value === undefined ? '' : bangla ? banglaNumber(value) : String(value))
