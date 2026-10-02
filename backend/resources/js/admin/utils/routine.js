import { banglaNumber } from '@/utils/banglaNumber'

// Class routine labels. Days are the lowercase English weekday names the API uses, in
// school-week order (Saturday first).
export const DAY_LABELS = {
  saturday: { bn: 'শনিবার', en: 'Saturday' },
  sunday: { bn: 'রবিবার', en: 'Sunday' },
  monday: { bn: 'সোমবার', en: 'Monday' },
  tuesday: { bn: 'মঙ্গলবার', en: 'Tuesday' },
  wednesday: { bn: 'বুধবার', en: 'Wednesday' },
  thursday: { bn: 'বৃহস্পতিবার', en: 'Thursday' },
  friday: { bn: 'শুক্রবার', en: 'Friday' },
}

export const dayLabel = (day, bn = false) => DAY_LABELS[day]?.[bn ? 'bn' : 'en'] ?? day

// Period times are Asia/Dhaka wall-clock "HH:MM" strings: shown as they are, with Bangla
// digits for Bangla. The same rule as the portal routine page.
export const clock = (time, bn = false) => (bn ? banglaNumber(time) : time || '')

// A teacher's name or a subject's name in the chosen language, falling back to the other.
export const pick = (banglaName, englishName, bn = false) => (bn ? banglaName || englishName : englishName || banglaName) || ''

export const sectionLabel = (section) => [section?.class?.name, section?.name].filter(Boolean).join(' – ')
