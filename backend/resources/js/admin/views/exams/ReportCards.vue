<template>
  <div class="report-cards">
    <div class="no-print flex flex-wrap items-center justify-between gap-3 mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Report cards<span v-if="exam"> – {{ exam.name_en || exam.name_bn }}</span></h1>
        <p class="text-sm text-gray-500">
          {{ cards.length }} card{{ cards.length === 1 ? '' : 's' }}, one per page when printed. Choose "Save as PDF" in the print dialog to make a PDF.
        </p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="text-xs text-gray-600">Language
          <select v-model="language" class="input mt-0.5 block">
            <option value="bn">বাংলা</option>
            <option value="en">English</option>
          </select>
        </label>
        <label class="text-xs text-gray-600">Page
          <select v-model="paper" class="input mt-0.5 block">
            <option value="a4">A4</option>
            <option value="legal">Legal</option>
          </select>
        </label>
        <label class="text-xs text-gray-600">Orientation
          <select v-model="orientation" class="input mt-0.5 block">
            <option value="portrait">Portrait</option>
            <option value="landscape">Landscape</option>
          </select>
        </label>
        <router-link :to="`/exams/${route.params.id}/results`" class="btn btn-secondary">Back to results</router-link>
        <button type="button" class="btn btn-primary" :disabled="loading || cards.length === 0" @click="print">🖨️ Print</button>
      </div>
    </div>

    <div v-if="notice" class="no-print rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 mb-4">{{ notice }}</div>
    <p v-if="loading" class="no-print text-center text-gray-500 py-8">Loading report cards...</p>
    <p v-else-if="!notice && cards.length === 0" class="no-print text-center text-gray-500 py-8">
      There are no results to print. Process the exam first.
    </p>

    <article v-for="card in cards" :key="card.id" class="report-card" :class="{ 'report-card-wide': orientation === 'landscape' }" :lang="language">
      <header class="rc-header">
        <img v-if="school?.logo_url" :src="school.logo_url" alt="" class="rc-logo" />
        <div class="rc-school">
          <h2 class="rc-school-bn">{{ bn ? school?.name_bn || school?.name : school?.name || school?.name_bn }}</h2>
          <h2 v-if="bn ? school?.name : school?.name_bn" class="rc-school-en">{{ bn ? school?.name : school?.name_bn }}</h2>
          <p v-if="addressLine" class="rc-muted">{{ addressLine }}</p>
        </div>
      </header>

      <h3 class="rc-title">
        {{ examTitle(pick(exam?.name_bn, exam?.name_en), exam?.academic_year?.year, bn) }}
        – {{ t('একাডেমিক ট্রান্সক্রিপ্ট', 'Progress Report') }}
      </h3>

      <dl class="rc-grid">
        <div><dt>{{ t('শিক্ষাবর্ষ', 'Academic year') }}</dt><dd>{{ d(exam?.academic_year?.name) }}</dd></div>
        <div><dt>{{ t('শ্রেণি', 'Class') }}</dt><dd>{{ pick(card.class?.name_bn, card.class?.name) }}</dd></div>
        <div><dt>{{ t('সেকশন', 'Section') }}</dt><dd>{{ card.section?.name }}</dd></div>
        <div><dt>{{ t('শিফট', 'Shift') }}</dt><dd>{{ pick(card.section?.shift?.name_bn, card.section?.shift?.name_en) || '-' }}</dd></div>
      </dl>

      <dl class="rc-grid rc-student">
        <div class="rc-wide">
          <dt>{{ t('শিক্ষার্থীর নাম', 'Student') }}</dt>
          <dd><span class="rc-name-bn">{{ pick(card.student?.name_bn, card.student?.name_en) }}</span></dd>
        </div>
        <div><dt>{{ t('আইডি', 'Student ID') }}</dt><dd>{{ d(card.student?.student_code) }}</dd></div>
        <div><dt>{{ t('রোল', 'Roll') }}</dt><dd>{{ card.roll_number != null ? d(card.roll_number) : '-' }}</dd></div>
        <div><dt>{{ t('গ্রুপ', 'Group') }}</dt><dd>{{ groupLabel(card.group) }}</dd></div>
      </dl>

      <table class="rc-table">
        <thead>
          <tr>
            <th class="rc-left">{{ t('বিষয়', 'Subject') }}</th>
            <th v-for="part in MARK_PARTS" :key="part.key">{{ partLabel(part) }}</th>
            <th>{{ t('মোট', 'Total') }}</th>
            <th>{{ t('গ্রেড', 'Grade') }}</th>
            <th>{{ t('পয়েন্ট', 'Point') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(unit, i) in card.subjects" :key="i" :class="unit.grade === 'F' ? (unit.is_optional ? 'rc-muted-row' : 'rc-fail') : ''">
            <td class="rc-left">
              {{ pick(unit.name_bn, unit.name_en) }}
              <span v-if="unit.is_combined" class="rc-tag">{{ t('যুক্ত', 'Combined') }}</span>
              <span v-if="unit.is_optional" class="rc-tag">{{ t('৪র্থ বিষয়', '4th subject') }}</span>
              <div v-if="unit.is_optional && unit.grade === 'F'" class="rc-muted">{{ t('৪র্থ বিষয় – ফেল হিসেবে গণ্য নয়', '4th subject — not counted as a fail') }}</div>
            </td>
            <td v-for="part in MARK_PARTS" :key="part.key">
              <template v-if="unit.parts[part.key]">
                <template v-if="unit.is_absent">-</template>
                <template v-else>{{ num(unit.parts[part.key].obtained) }}<span class="rc-muted"> /{{ d(unit.parts[part.key].full) }}</span></template>
              </template>
            </td>
            <td>
              <template v-if="unit.is_absent">{{ unit.papers.some((p) => p.is_missing) ? t('অনুপস্থিত', 'Missing') : t('অনুপস্থিত', 'Absent') }}</template>
              <template v-else>{{ num(unit.obtained) }}<span class="rc-muted"> /{{ num(unit.full) }}</span></template>
            </td>
            <td class="rc-strong">{{ unit.grade }}</td>
            <td>{{ d(unit.point) }}</td>
          </tr>
        </tbody>
      </table>

      <div class="rc-summary">
        <div><span>{{ t('জিপিএ', 'GPA') }}</span><strong>{{ d(card.gpa) }}</strong></div>
        <div><span>{{ t('গ্রেড', 'Grade') }}</span><strong>{{ card.grade }}</strong></div>
        <div><span>{{ t('মোট নম্বর', 'Total marks') }}</span><strong>{{ num(card.total_obtained) }} / {{ num(card.total_full) }}</strong></div>
        <div><span>{{ t('ফলাফল', 'Result') }}</span><strong>{{ card.is_pass ? t('উত্তীর্ণ', 'Passed') : t('অনুত্তীর্ণ', 'Failed') }}</strong></div>
        <div>
          <span>{{ t('পাস করা বিষয়', 'Subjects passed') }}</span>
          <strong>{{ bn ? `${d(card.passed_count + card.failed_count)} টির মধ্যে ${d(card.passed_count)}` : `${card.passed_count} of ${card.passed_count + card.failed_count}` }}</strong>
        </div>
        <div><span>{{ t('অকৃতকার্য বিষয়', 'Failed subjects') }}</span><strong>{{ d(card.failed_count) }}</strong></div>
        <div><span>{{ t('শ্রেণিতে অবস্থান', 'Position in class') }}</span><strong>{{ d(card.class_position) }}</strong></div>
        <div><span>{{ t('সেকশনে অবস্থান', 'Position in section') }}</span><strong>{{ d(card.section_position) }}</strong></div>
      </div>

      <p class="rc-muted rc-note">
        {{ t(
          'জিপিএ হলো আবশ্যিক বিষয়গুলোর পয়েন্টের গড়; ৪র্থ বিষয়ের ২.০০-এর বেশি পয়েন্ট যোগ হয়। গ্রেডিং: A+ ৮০–১০০ (৫.০০), A ৭০–৭৯ (৪.০০), A- ৬০–৬৯ (৩.৫০), B ৫০–৫৯ (৩.০০), C ৪০–৪৯ (২.০০), D ৩৩–৩৯ (১.০০), F ০–৩২ (০.০০)।',
          'GPA is the average of the compulsory subject points; points of the 4th subject above 2.00 are added. Grading: A+ 80–100 (5.00), A 70–79 (4.00), A- 60–69 (3.50), B 50–59 (3.00), C 40–49 (2.00), D 33–39 (1.00), F 0–32 (0.00).'
        ) }}
      </p>

      <footer class="rc-signatures">
        <div><span></span>{{ t('শ্রেণি শিক্ষক', 'Class teacher') }}</div>
        <div><span></span>{{ t('প্রধান শিক্ষক', 'Head teacher') }}</div>
        <div><span></span>{{ t('অভিভাবক', 'Guardian') }}</div>
      </footer>
    </article>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { GROUP_LABELS } from '@/constants/academic'
import { banglaNumber } from '@/utils/banglaNumber'
import { examTitle } from '@/utils/examTitle'
import { MARK_PARTS } from '@/constants/exams'

const route = useRoute()

const exam = ref(null)
const school = ref(null)
const cards = ref([])
const loading = ref(true)
const notice = ref('')

// The card's language, paper size and orientation, chosen above the cards.
const language = ref('bn')
const paper = ref('a4')
const orientation = ref('portrait')
const bn = computed(() => language.value === 'bn')

const t = (bangla, english) => (bn.value ? bangla : english)
// Bangla digits in Bangla, as they are in English.
const d = (value) => (value === null || value === undefined ? '' : bn.value ? banglaNumber(value) : String(value))
const pick = (banglaName, englishName) => (bn.value ? banglaName || englishName : englishName || banglaName) || ''

// "148.50" -> "148.5", "90.00" -> "90".
const num = (value) => (value === null || value === undefined ? '-' : d(String(Number(value))))

const BANGLA_GROUPS = { science: 'বিজ্ঞান', business_studies: 'ব্যবসায় শিক্ষা', humanities: 'মানবিক' }
const BANGLA_PARTS = { written: 'লিখিত', mcq: 'বহুনির্বাচনী', practical: 'ব্যবহারিক' }
const groupLabel = (group) => (group ? (bn.value ? BANGLA_GROUPS[group] : GROUP_LABELS[group]) || '-' : '-')
const partLabel = (part) => (bn.value ? BANGLA_PARTS[part.key] : part.label)

// @page can't be bound in an SFC style block, so the page rule is written into a style
// element that follows the page size and orientation.
const PAGE_STYLE_ID = 'report-card-page'
const applyPageStyle = () => {
  let style = document.getElementById(PAGE_STYLE_ID)
  if (!style) {
    style = document.createElement('style')
    style.id = PAGE_STYLE_ID
    document.head.appendChild(style)
  }
  style.textContent = `@page { size: ${paper.value === 'legal' ? 'legal' : 'A4'} ${orientation.value}; margin: 12mm; }`
}
watch([paper, orientation], applyPageStyle)

const addressLine = computed(() => {
  const a = school.value?.address
  return a ? [a.village || a.street, a.upazila, a.district].filter(Boolean).join(', ') : ''
})

const print = () => window.print()

// Noto Sans Bengali, for the Bangla names. Added once, only on this page.
const FONT_ID = 'noto-sans-bengali'
const loadFont = () => {
  if (document.getElementById(FONT_ID)) return

  const link = document.createElement('link')
  link.id = FONT_ID
  link.rel = 'stylesheet'
  link.href = 'https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700&display=swap'
  document.head.appendChild(link)
}

// The query holds the class and section being printed (set by the results page).
const fetchCards = async () => {
  const base = `/exams/${route.params.id}/results`

  if (route.params.studentId) {
    const { data } = await api.get(`${base}/${route.params.studentId}`)
    return [data.data]
  }

  const params = { per_page: 100, with_subjects: 1 }
  if (route.query.class_id) params.class_id = route.query.class_id
  if (route.query.section_id) params.section_id = route.query.section_id

  const all = []
  let page = 1
  let last = 1

  do {
    const { data } = await api.get(base, { params: { ...params, page } })
    all.push(...data.data)
    last = data.meta.last_page
    page += 1
  } while (page <= last)

  return all
}

onMounted(async () => {
  // Marks the page so the print CSS can hide the admin chrome (sidebar and header).
  document.body.classList.add('report-cards-page')
  loadFont()
  applyPageStyle()

  try {
    const [examRes, schoolRes] = await Promise.all([
      api.get(`/exams/${route.params.id}`),
      api.get('/public/school'),
    ])
    exam.value = examRes.data.data
    school.value = schoolRes.data.data
    cards.value = await fetchCards()
  } catch (error) {
    notice.value = error.response?.data?.message || 'Failed to load the report cards'
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => {
  document.body.classList.remove('report-cards-page')
  document.getElementById(PAGE_STYLE_ID)?.remove()
})
</script>

<style>
.report-card {
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
}
.report-card-wide { max-width: 1100px; }
.rc-header { display: flex; align-items: center; justify-content: center; gap: 16px; text-align: center; }
.rc-logo { height: 64px; width: 64px; object-fit: contain; }
.rc-school-bn { font-size: 20px; font-weight: 700; margin: 0; }
.rc-school-en { font-size: 14px; font-weight: 600; margin: 0; }
.rc-title { text-align: center; font-size: 15px; font-weight: 700; margin: 12px 0; padding: 4px 0; border-top: 1px solid #111827; border-bottom: 1px solid #111827; }
.rc-muted { color: #6b7280; font-size: 11px; margin: 0; }
.rc-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px 16px; margin: 0 0 10px; }
.rc-grid dt { font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.03em; }
.rc-grid dd { margin: 0; font-weight: 600; }
.rc-student { padding: 8px 0; border-bottom: 1px dashed #9ca3af; }
.rc-wide { grid-column: span 4; }
.rc-name-bn { font-size: 15px; }
.rc-table { width: 100%; border-collapse: collapse; margin: 10px 0; }
.rc-table th, .rc-table td { border: 1px solid #9ca3af; padding: 4px 6px; text-align: center; }
.rc-table th { background: #f3f4f6; font-weight: 600; }
.rc-left { text-align: left !important; }
.rc-strong { font-weight: 700; }
.rc-fail td { color: #b91c1c; }
.rc-muted-row td { color: #6b7280; }
.rc-tag { display: inline-block; margin-left: 6px; padding: 0 5px; border: 1px solid #6b7280; border-radius: 3px; font-size: 10px; color: #374151; }
.rc-summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 12px 0; }
.rc-summary div { border: 1px solid #9ca3af; border-radius: 4px; padding: 6px 8px; }
.rc-summary span { display: block; font-size: 10px; color: #6b7280; text-transform: uppercase; }
.rc-summary strong { font-size: 15px; }
.rc-note { margin-top: 8px; }
.rc-signatures { display: grid; grid-template-columns: repeat(3, 1fr); gap: 32px; margin-top: 48px; text-align: center; font-size: 12px; }
.rc-signatures span { display: block; border-top: 1px solid #111827; margin-bottom: 4px; }

@media print {
  /* The admin chrome: sidebar, top bar and the page padding around the router view. */
  body.report-cards-page aside,
  body.report-cards-page header:not(.rc-header),
  body.report-cards-page .no-print { display: none !important; }
  body.report-cards-page .ml-64 { margin-left: 0 !important; }
  body.report-cards-page main { padding: 0 !important; }
  body.report-cards-page .min-h-screen { background: #fff !important; }

  .report-card {
    border: none;
    border-radius: 0;
    padding: 0;
    margin: 0;
    max-width: none;
    break-after: page;
    page-break-after: always;
    break-inside: avoid;
  }
  .report-card:last-of-type { break-after: auto; page-break-after: auto; }
}
</style>
