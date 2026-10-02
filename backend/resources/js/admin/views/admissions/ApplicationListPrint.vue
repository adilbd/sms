<template>
  <div class="admission-list-print">
    <div class="no-print flex flex-wrap items-center justify-between gap-3 mb-6">
      <h1 class="text-2xl font-bold text-gray-900">Print applicant list</h1>
      <div class="flex flex-wrap items-end gap-2">
        <label class="text-xs text-gray-600">Language
          <select v-model="language" class="input mt-0.5 block">
            <option value="bn">বাংলা</option>
            <option value="en">English</option>
          </select>
        </label>
        <router-link to="/admissions" class="btn btn-secondary">Back</router-link>
        <button type="button" class="btn btn-primary" :disabled="loading || rows.length === 0" @click="print">Print</button>
      </div>
    </div>

    <div v-if="notice" class="no-print rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 mb-4">{{ notice }}</div>
    <p v-if="capped" class="no-print rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 mb-4">
      Only the first {{ MAX_ROWS }} of {{ total }} applications are shown. Narrow the filters to print the rest.
    </p>
    <p v-if="loading" class="no-print text-center text-gray-500 py-8">Loading applications...</p>
    <p v-else-if="!notice && rows.length === 0" class="no-print text-center text-gray-500 py-8">No applications match the filters.</p>

    <section v-if="!loading && rows.length" class="al-sheet" :lang="language">
      <header class="al-header">
        <img v-if="school?.logo_url" :src="school.logo_url" alt="" class="al-logo" />
        <div>
          <h2 class="al-school">{{ bn ? school?.name_bn || school?.name : school?.name || school?.name_bn }}</h2>
          <h3 class="al-title">{{ t('ভর্তি আবেদনকারীদের তালিকা', 'Admission applicants') }}</h3>
        </div>
      </header>
      <p class="al-meta">
        <span>{{ t('ভর্তি চক্র', 'Round') }}: {{ roundLabel }}</span>
        <span v-for="f in filterLabels" :key="f">{{ f }}</span>
        <span>{{ t('মোট', 'Total') }}: {{ d(rows.length) }}</span>
        <span>{{ t('প্রিন্টের সময়', 'Printed') }}: {{ printedAt }}</span>
      </p>

      <table class="al-table">
        <thead>
          <tr>
            <th>#</th>
            <th>{{ t('আবেদন নম্বর', 'Application no.') }}</th>
            <th>{{ t('শিক্ষার্থীর নাম', 'Student') }}</th>
            <th>{{ t('শ্রেণি / গ্রুপ', 'Class / group') }}</th>
            <template v-if="canSeeContact"><th>{{ t('অভিভাবক', 'Guardian') }}</th><th>{{ t('মোবাইল', 'Mobile') }}</th></template>
            <th>{{ t('অবস্থা', 'Status') }}</th>
            <th>{{ t('পরীক্ষার তারিখ', 'Test date') }}</th>
            <th>{{ t('নম্বর', 'Score') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, index) in rows" :key="row.id">
            <td>{{ d(index + 1) }}</td>
            <td class="al-nowrap">{{ row.application_no }}</td>
            <td>{{ join(row.name_bn, row.name_en) }}</td>
            <td>{{ pick(row.class?.name_bn, row.class?.name) }}<template v-if="row.group"> · {{ bn ? BANGLA_GROUPS[row.group] : GROUP_LABELS[row.group] }}</template></td>
            <template v-if="canSeeContact"><td>{{ row.guardian?.name }}</td><td class="al-nowrap">{{ row.guardian?.mobile ? d(row.guardian.mobile) : '' }}</td></template>
            <td>{{ bn ? BANGLA_STATUS[row.status] : STATUS_LABELS[row.status] }}</td>
            <td class="al-nowrap">{{ row.test_at ? date(row.test_at) : '' }}</td>
            <td>{{ row.test_score !== null && row.test_score !== undefined ? d(row.test_score) : '' }}</td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { GROUP_LABELS } from '@/constants/academic'
import { STATUS_LABELS } from '@/constants/admissions'
import { banglaNumber } from '@/utils/banglaNumber'
import { banglaDate, banglaDateTime } from '@/utils/banglaDate'

const MAX_ROWS = 1000
const PER_PAGE = 100
const BANGLA_STATUS = {
  submitted: 'জমা দেওয়া হয়েছে',
  under_review: 'যাচাই চলছে',
  test_scheduled: 'পরীক্ষা/সাক্ষাৎকারের সময় নির্ধারিত',
  approved: 'অনুমোদিত',
  waitlisted: 'অপেক্ষমাণ তালিকায়',
  rejected: 'বাতিল',
  admitted: 'ভর্তি সম্পন্ন',
}
const BANGLA_GROUPS = { science: 'বিজ্ঞান', business_studies: 'ব্যবসায় শিক্ষা', humanities: 'মানবিক' }

const route = useRoute()
const authStore = useAuthStore()
const rows = ref([])
const total = ref(0)
const school = ref(null)
const rounds = ref([])
const classes = ref([])
const loading = ref(true)
const notice = ref('')
const language = ref('bn')
const printedAtIso = new Date().toISOString()

const bn = computed(() => language.value === 'bn')
const t = (bangla, english) => (bn.value ? bangla : english)
const pick = (banglaValue, englishValue) => (bn.value ? banglaValue || englishValue : englishValue || banglaValue)
const d = (value) => (bn.value ? banglaNumber(value) : String(value))
const join = (...parts) => parts.filter(Boolean).join(' / ')
const date = (iso) => (bn.value ? banglaDate(iso) : new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Dhaka', dateStyle: 'medium' }).format(new Date(iso)))
const printedAt = computed(() => (bn.value ? banglaDateTime(printedAtIso) : new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Dhaka', dateStyle: 'medium', timeStyle: 'short' }).format(new Date(printedAtIso))))

// Guardian contact is shown only to users who may see it (the API omits it otherwise).
const canSeeContact = computed(() => authStore.hasPermission('edit-students'))
const capped = computed(() => total.value > MAX_ROWS)

// The filters arrive in the query string (set by the applications list).
const filters = computed(() => Object.fromEntries(
  ['round_id', 'class_id', 'status', 'search'].filter((key) => route.query[key]).map((key) => [key, route.query[key]]),
))

const roundLabel = computed(() => {
  const round = rounds.value.find((r) => String(r.id) === String(filters.value.round_id))
  return round ? pick(round.name_bn, round.name_en) : t('সব', 'All')
})
const filterLabels = computed(() => {
  const out = []
  const cls = classes.value.find((c) => String(c.id) === String(filters.value.class_id))
  if (cls) out.push(`${t('শ্রেণি', 'Class')}: ${pick(cls.name_bn, cls.name)}`)
  if (filters.value.status) out.push(`${t('অবস্থা', 'Status')}: ${bn.value ? BANGLA_STATUS[filters.value.status] : STATUS_LABELS[filters.value.status]}`)
  if (filters.value.search) out.push(`${t('অনুসন্ধান', 'Search')}: ${filters.value.search}`)
  return out
})

const PAGE_STYLE_ID = 'admission-list-page'
const FONT_ID = 'noto-sans-bengali'

const fetchRows = async () => {
  const all = []
  let page = 1
  let last = 1

  do {
    const { data } = await api.get('/admission-applications', { params: { ...filters.value, page, per_page: PER_PAGE } })
    all.push(...data.data)
    total.value = data.meta.total
    last = data.meta.last_page
    page += 1
  } while (page <= last && all.length < MAX_ROWS)

  rows.value = all.slice(0, MAX_ROWS)
}

const print = () => window.print()

onMounted(async () => {
  document.body.classList.add('admission-print-page')
  if (!document.getElementById(FONT_ID)) {
    const link = document.createElement('link')
    link.id = FONT_ID
    link.rel = 'stylesheet'
    link.href = 'https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700&display=swap'
    document.head.appendChild(link)
  }
  const style = document.createElement('style')
  style.id = PAGE_STYLE_ID
  style.textContent = '@page { size: A4 landscape; margin: 10mm; }'
  document.head.appendChild(style)

  try {
    const [schoolRes, roundsRes, classesRes] = await Promise.all([
      api.get('/public/school'),
      api.get('/admission-rounds', { params: { per_page: 100 } }),
      api.get('/classes', { params: { per_page: 100 } }),
    ])
    school.value = schoolRes.data.data
    rounds.value = roundsRes.data.data
    classes.value = classesRes.data.data
    await fetchRows()
  } catch (error) {
    notice.value = error.response?.data?.message || 'Failed to load the applications'
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => {
  document.body.classList.remove('admission-print-page')
  document.getElementById(PAGE_STYLE_ID)?.remove()
})
</script>

<style>
.al-sheet { font-family: 'Noto Sans Bengali', 'Noto Sans', system-ui, sans-serif; background: #fff; color: #111827; font-size: 11px; line-height: 1.35; }
.al-header { display: flex; align-items: center; justify-content: center; gap: 12px; text-align: center; }
.al-logo { height: 48px; width: 48px; object-fit: contain; }
.al-school { font-size: 18px; font-weight: 700; margin: 0; }
.al-title { font-size: 14px; font-weight: 600; margin: 0; }
.al-meta { display: flex; flex-wrap: wrap; gap: 4px 20px; margin: 8px 0; font-size: 11px; color: #374151; }
.al-table { width: 100%; border-collapse: collapse; }
.al-table th, .al-table td { border: 1px solid #9ca3af; padding: 3px 5px; text-align: left; vertical-align: top; }
.al-table th { background: #f3f4f6; font-weight: 600; }
.al-table thead { display: table-header-group; }
.al-table tr { break-inside: avoid; page-break-inside: avoid; }
.al-nowrap { white-space: nowrap; }

@media print {
  body.admission-print-page aside,
  body.admission-print-page header:not(.al-header),
  body.admission-print-page .no-print { display: none !important; }
  body.admission-print-page .ml-64 { margin-left: 0 !important; }
  body.admission-print-page main { padding: 0 !important; }
  body.admission-print-page .min-h-screen { background: #fff !important; }
}
</style>
