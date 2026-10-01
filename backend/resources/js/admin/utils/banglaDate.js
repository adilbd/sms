import { banglaNumber } from '@/utils/banglaNumber'

// Dates and times in Bangla: Bangla digits, Bangla month names and পূর্বাহ্ন / অপরাহ্ন.
// The same rule as App\Support\BanglaDate on the server. Timestamps are shown in
// Asia/Dhaka, the school's local time.
export const BANGLA_MONTHS = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর']
export const BANGLA_AM = 'পূর্বাহ্ন'
export const BANGLA_PM = 'অপরাহ্ন'

const dhakaParts = (iso) => {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-GB', {
      timeZone: 'Asia/Dhaka',
      year: 'numeric',
      month: 'numeric',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      hourCycle: 'h23',
    })
      .formatToParts(new Date(iso))
      .map((part) => [part.type, part.value]),
  )

  return {
    year: parts.year,
    month: Number(parts.month),
    day: Number(parts.day),
    hour: Number(parts.hour) % 24,
    minute: parts.minute,
  }
}

// "2026-10-01T09:07:00+00:00" -> "১ অক্টোবর ২০২৬"
export const banglaDate = (iso) => {
  if (!iso) return ''
  const p = dhakaParts(iso)

  return `${banglaNumber(p.day)} ${BANGLA_MONTHS[p.month - 1]} ${banglaNumber(p.year)}`
}

// "2026-10-01T09:07:00+00:00" -> "১ অক্টোবর ২০২৬, অপরাহ্ন ৩:০৭"
export const banglaDateTime = (iso) => {
  if (!iso) return ''
  const p = dhakaParts(iso)

  return `${banglaDate(iso)}, ${p.hour < 12 ? BANGLA_AM : BANGLA_PM} ${banglaNumber(p.hour % 12 || 12)}:${banglaNumber(p.minute)}`
}
