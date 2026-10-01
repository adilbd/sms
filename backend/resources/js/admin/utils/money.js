// Bangladeshi Taka as integer paisa, the same rule as App\Support\Money on the server, so
// the screens never add or compare money as floats. Amounts travel as "1500.50" strings.

// "1500.5" -> 150050. A third decimal rounds half up.
export const toPaisa = (value) => {
  const text = String(value ?? '').trim()
  const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(text)
  if (!match) return 0

  const fraction = (match[3] ?? '').padEnd(3, '0')
  let paisa = Number(match[2]) * 100 + Number(fraction.slice(0, 2))
  if (Number(fraction[2]) >= 5) paisa += 1

  return match[1] === '-' ? -paisa : paisa
}

// 150050 -> "1500.50"
export const fromPaisa = (paisa) => {
  const sign = paisa < 0 ? '-' : ''
  const abs = Math.abs(paisa)

  return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`
}

// "1500.5" -> "1,500.50" (Indian digit grouping is not used: the lakh/crore commas are
// easy to misread on a receipt, so thousands are grouped in threes).
export const groupThousands = (value) => {
  const [whole, fraction] = fromPaisa(toPaisa(value)).split('.')
  const sign = whole.startsWith('-') ? '-' : ''

  return `${sign}${whole.replace('-', '').replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${fraction}`
}

// "৳1,500.50"
export const taka = (value) => `৳${groupThousands(value)}`
