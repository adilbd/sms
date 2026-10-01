// Fee heads, payment methods and statuses (see App\Models\FeeHead, FeePayment and FeeDue
// on the backend).

export const FEE_KINDS = [
  { value: 'monthly', label: 'Monthly' },
  { value: 'one_time', label: 'One-time' },
  { value: 'per_exam', label: 'Per exam' },
]

export const KIND_LABELS = Object.fromEntries(FEE_KINDS.map((kind) => [kind.value, kind.label]))

export const PAYMENT_METHODS = [
  { value: 'cash', label: 'Cash', bn: 'নগদ টাকা' },
  { value: 'bkash', label: 'bKash', bn: 'বিকাশ' },
  { value: 'nagad', label: 'Nagad', bn: 'নগদ (মোবাইল)' },
  { value: 'rocket', label: 'Rocket', bn: 'রকেট' },
]

export const METHOD_LABELS = Object.fromEntries(PAYMENT_METHODS.map((method) => [method.value, method.label]))

// bKash, Nagad and Rocket need a hand-entered transaction ID.
export const MOBILE_METHODS = ['bkash', 'nagad', 'rocket']

export const DUE_STATUS_BADGES = {
  unpaid: 'badge-danger',
  partial: 'badge-warning',
  paid: 'badge-success',
  waived: 'badge-info',
}

export const MONTHS_EN = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
export const MONTHS_BN = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর']
