const BANGLA_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯']

// Writes the digits of a number in Bangla ("3.64" -> "৩.৬৪"). Everything else is kept.
// The same rule as App\Support\BanglaNumber on the server.
export const banglaNumber = (value) =>
  value === null || value === undefined ? '' : String(value).replace(/[0-9]/g, (digit) => BANGLA_DIGITS[digit])
