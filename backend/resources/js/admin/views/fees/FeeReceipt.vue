<template>
  <div class="fee-receipt-wrap">
    <div class="no-print flex flex-wrap items-center justify-between gap-3 mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Receipt<span v-if="payment"> {{ payment.receipt_no }}</span></h1>
        <p class="text-sm text-gray-500">Choose "Save as PDF" in the print dialog to make a PDF.</p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="text-xs text-gray-600">Language
          <select v-model="language" class="input mt-0.5 block">
            <option value="bn">বাংলা</option>
            <option value="en">English</option>
          </select>
        </label>
        <label class="text-xs text-gray-600">Paper
          <select v-model="paper" class="input mt-0.5 block">
            <option value="a4">A4</option>
            <option value="a5">A5 (half A4)</option>
          </select>
        </label>
        <router-link v-if="authStore.hasPermission('collect-fees')" to="/fees/collect" class="btn btn-secondary">New payment</router-link>
        <button v-if="payment && !payment.is_cancelled && authStore.hasPermission('delete-fees')" type="button" class="btn btn-danger" @click="cancel">Cancel receipt</button>
        <button type="button" class="btn btn-primary" :disabled="!payment" @click="print">🖨️ Print</button>
      </div>
    </div>

    <div v-if="notice" class="no-print rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 mb-4">{{ notice }}</div>
    <p v-if="loading" class="no-print text-center text-gray-500 py-8">Loading receipt...</p>

    <article v-if="payment" class="fee-receipt" :class="{ 'fee-receipt-a5': paper === 'a5' }" :lang="language">
      <div v-if="payment.is_cancelled" class="fr-watermark" aria-hidden="true">{{ t('বাতিল', 'CANCELLED') }}</div>

      <header class="fr-header">
        <img v-if="school?.logo_url" :src="school.logo_url" alt="" class="fr-logo" />
        <div>
          <h2 class="fr-school">{{ bn ? school?.name_bn || school?.name : school?.name || school?.name_bn }}</h2>
          <p v-if="addressLine" class="fr-muted">{{ addressLine }}</p>
        </div>
      </header>

      <h3 class="fr-title">{{ t('ফি আদায়ের রসিদ', 'Fee Receipt') }}</h3>

      <dl class="fr-grid">
        <div><dt>{{ t('রসিদ নং', 'Receipt no.') }}</dt><dd>{{ d(payment.receipt_no) }}</dd></div>
        <div><dt>{{ t('তারিখ', 'Date') }}</dt><dd>{{ dateText }}</dd></div>
        <div class="fr-wide"><dt>{{ t('শিক্ষার্থীর নাম', 'Student') }}</dt><dd>{{ studentName(payment.student, bn) }}</dd></div>
        <div><dt>{{ t('আইডি', 'Student ID') }}</dt><dd>{{ d(payment.student?.student_id) }}</dd></div>
        <div><dt>{{ t('শ্রেণি', 'Class') }}</dt><dd>{{ pick(enrolment?.class?.name_bn, enrolment?.class?.name) || '-' }}</dd></div>
        <div><dt>{{ t('সেকশন', 'Section') }}</dt><dd>{{ enrolment?.section?.name || '-' }}</dd></div>
        <div><dt>{{ t('রোল', 'Roll') }}</dt><dd>{{ enrolment?.roll_number != null ? d(enrolment.roll_number) : '-' }}</dd></div>
      </dl>

      <table class="fr-table">
        <thead>
          <tr>
            <th class="fr-left">{{ t('ক্রম', '#') }}</th>
            <th class="fr-left">{{ t('খাত', 'Fee') }}</th>
            <th class="fr-left">{{ t('মাস / সময়', 'Month / period') }}</th>
            <th class="fr-right">{{ t('টাকা', 'Amount') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(allocation, i) in payment.allocations" :key="allocation.fee_due_id">
            <td class="fr-left">{{ d(i + 1) }}</td>
            <td class="fr-left">{{ headName(allocation.due?.head, bn) }}</td>
            <td class="fr-left">{{ periodText(allocation.due?.period) }}</td>
            <td class="fr-right">{{ money(allocation.amount) }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="3" class="fr-right fr-strong">{{ t('মোট', 'Total') }}</td>
            <td class="fr-right fr-strong">{{ money(payment.amount) }}</td>
          </tr>
        </tfoot>
      </table>

      <dl class="fr-grid fr-pay">
        <div><dt>{{ t('পরিশোধের মাধ্যম', 'Method') }}</dt><dd>{{ methodText }}</dd></div>
        <div v-if="payment.transaction_id"><dt>{{ t('ট্রানজেকশন আইডি', 'Transaction ID') }}</dt><dd>{{ payment.transaction_id }}</dd></div>
        <div><dt>{{ t('আদায়কারী', 'Collected by') }}</dt><dd>{{ payment.collected_by_name }}</dd></div>
        <div v-if="payment.note" class="fr-wide"><dt>{{ t('মন্তব্য', 'Note') }}</dt><dd>{{ payment.note }}</dd></div>
      </dl>

      <p v-if="payment.is_cancelled" class="fr-cancelled">
        {{ t('এই রসিদ বাতিল করা হয়েছে', 'This receipt has been cancelled') }}<template v-if="payment.cancel_reason">: {{ payment.cancel_reason }}</template>
      </p>

      <footer class="fr-signatures">
        <div><span></span>{{ t('অভিভাবক', 'Guardian') }}</div>
        <div><span></span>{{ t('আদায়কারীর স্বাক্ষর', 'Received by') }}</div>
      </footer>
    </article>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { METHOD_LABELS, PAYMENT_METHODS } from '@/constants/fees'
import { banglaNumber } from '@/utils/banglaNumber'
import { groupThousands } from '@/utils/money'
import { banglaDateTime } from '@/utils/banglaDate'
import { dhakaDateTime, headName, periodLabel, studentName } from '@/utils/fees'

const route = useRoute()
const authStore = useAuthStore()

const payment = ref(null)
const school = ref(null)
const loading = ref(true)
const notice = ref('')

// The receipt's language and paper size, chosen above it.
const language = ref('bn')
const paper = ref('a4')
const bn = computed(() => language.value === 'bn')

const t = (bangla, english) => (bn.value ? bangla : english)
// Bangla digits in Bangla, as they are in English.
const d = (value) => (value === null || value === undefined ? '' : bn.value ? banglaNumber(value) : String(value))
const pick = (banglaName, englishName) => (bn.value ? banglaName || englishName : englishName || banglaName) || ''
const money = (value) => `৳${d(groupThousands(value))}`

const periodText = (period) => d(periodLabel(period, bn.value))
const dateText = computed(() => (bn.value ? banglaDateTime(payment.value?.paid_at) : dhakaDateTime(payment.value?.paid_at)))
const methodText = computed(() => {
  const method = PAYMENT_METHODS.find((m) => m.value === payment.value?.method)

  return bn.value ? method?.bn || payment.value?.method : METHOD_LABELS[payment.value?.method] || payment.value?.method
})

// The class, section and roll of the enrolment the first paid due belongs to.
const enrolment = computed(() => payment.value?.allocations?.[0]?.due?.enrolment ?? null)

const addressLine = computed(() => {
  const a = school.value?.address
  return a ? [a.village || a.street, a.upazila, a.district].filter(Boolean).join(', ') : ''
})

// @page can't be bound in an SFC style block, so the page rule is written into a style
// element that follows the paper size. A5 is landscape: half of an A4 sheet.
const PAGE_STYLE_ID = 'fee-receipt-page'
const applyPageStyle = () => {
  let style = document.getElementById(PAGE_STYLE_ID)
  if (!style) {
    style = document.createElement('style')
    style.id = PAGE_STYLE_ID
    document.head.appendChild(style)
  }
  style.textContent = paper.value === 'a5'
    ? '@page { size: A5 landscape; margin: 8mm; }'
    : '@page { size: A4 portrait; margin: 12mm; }'
}
watch(paper, applyPageStyle)

const print = () => window.print()

// Noto Sans Bengali, for the Bangla text. Added once, only on this page.
const FONT_ID = 'noto-sans-bengali'
const loadFont = () => {
  if (document.getElementById(FONT_ID)) return

  const link = document.createElement('link')
  link.id = FONT_ID
  link.rel = 'stylesheet'
  link.href = 'https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700&display=swap'
  document.head.appendChild(link)
}

const load = async () => {
  const { data } = await api.get(`/fee-payments/${route.params.id}`)
  payment.value = data.data
}

const cancel = async () => {
  const reason = window.prompt(`Cancel receipt ${payment.value.receipt_no}? This puts the dues it paid back to unpaid. Reason:`)
  if (!reason || !reason.trim()) return

  try {
    await api.post(`/fee-payments/${payment.value.id}/cancel`, { reason: reason.trim() })
    await load()
  } catch (error) {
    notice.value = error.response?.data?.errors?.reason?.[0] || error.response?.data?.message || 'Failed to cancel the receipt'
  }
}

onMounted(async () => {
  // Marks the page so the print CSS can hide the admin chrome (sidebar and header).
  document.body.classList.add('fee-receipt-page')
  loadFont()
  applyPageStyle()

  try {
    const [, schoolRes] = await Promise.all([load(), api.get('/public/school')])
    school.value = schoolRes.data.data
  } catch (error) {
    notice.value = error.response?.data?.message || 'Failed to load the receipt'
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => {
  document.body.classList.remove('fee-receipt-page')
  document.getElementById(PAGE_STYLE_ID)?.remove()
})
</script>

<style>
.fee-receipt {
  position: relative;
  font-family: 'Noto Sans Bengali', 'Noto Sans', system-ui, sans-serif;
  background: #fff;
  color: #111827;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  padding: 24px 28px;
  margin: 0 auto 24px;
  max-width: 800px;
  font-size: 13px;
  line-height: 1.45;
  overflow: hidden;
}
.fee-receipt-a5 { max-width: 780px; padding: 14px 20px; font-size: 12px; }
.fr-header { display: flex; align-items: center; justify-content: center; gap: 16px; text-align: center; }
.fr-logo { height: 56px; width: 56px; object-fit: contain; }
.fr-school { font-size: 19px; font-weight: 700; margin: 0; }
.fr-muted { color: #6b7280; font-size: 11px; margin: 0; }
.fr-title { text-align: center; font-size: 15px; font-weight: 700; margin: 10px 0; padding: 4px 0; border-top: 1px solid #111827; border-bottom: 1px solid #111827; }
.fr-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px 16px; margin: 0 0 10px; }
.fr-grid dt { font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.03em; }
.fr-grid dd { margin: 0; font-weight: 600; }
.fr-wide { grid-column: span 2; }
.fr-table { width: 100%; border-collapse: collapse; margin: 10px 0; }
.fr-table th, .fr-table td { border: 1px solid #9ca3af; padding: 4px 8px; }
.fr-table th { background: #f3f4f6; font-weight: 600; }
.fr-left { text-align: left; }
.fr-right { text-align: right; }
.fr-strong { font-weight: 700; }
.fr-pay { padding-top: 6px; border-top: 1px dashed #9ca3af; }
.fr-cancelled { color: #b91c1c; font-weight: 600; margin: 8px 0 0; }
.fr-signatures { display: grid; grid-template-columns: repeat(2, 1fr); gap: 48px; margin-top: 40px; text-align: center; font-size: 12px; }
.fr-signatures span { display: block; border-top: 1px solid #111827; margin-bottom: 4px; }
.fr-watermark {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 96px;
  font-weight: 800;
  letter-spacing: 0.1em;
  color: rgba(185, 28, 28, 0.18);
  transform: rotate(-24deg);
  pointer-events: none;
  user-select: none;
}

@media print {
  /* The admin chrome: sidebar, top bar and the page padding around the router view. */
  body.fee-receipt-page aside,
  body.fee-receipt-page header:not(.fr-header),
  body.fee-receipt-page .no-print { display: none !important; }
  body.fee-receipt-page .ml-64 { margin-left: 0 !important; }
  body.fee-receipt-page main { padding: 0 !important; }
  body.fee-receipt-page .min-h-screen { background: #fff !important; }

  .fee-receipt {
    border: none;
    border-radius: 0;
    padding: 0;
    margin: 0;
    max-width: none;
    break-inside: avoid;
  }
  .fr-watermark { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
