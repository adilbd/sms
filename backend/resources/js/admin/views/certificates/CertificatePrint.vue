<template>
  <div class="certificate-wrap">
    <div class="no-print flex flex-wrap items-center justify-between gap-3 mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Certificate<span v-if="certificate"> {{ certificate.serial_no }}</span></h1>
        <p class="text-sm text-gray-500">A reprint always shows what was issued. Choose "Save as PDF" in the print dialog to make a PDF.</p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="text-xs text-gray-600">Language
          <select v-model="language" class="input mt-0.5 block">
            <option value="bn">বাংলা</option>
            <option value="en">English</option>
          </select>
        </label>
        <router-link to="/certificates" class="btn btn-secondary">Back to register</router-link>
        <button type="button" class="btn btn-primary" :disabled="!certificate" @click="print">🖨️ Print</button>
      </div>
    </div>

    <div v-if="notice" class="no-print rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 mb-4">{{ notice }}</div>
    <p v-if="loading" class="no-print text-center text-gray-500 py-8">Loading certificate...</p>

    <article v-if="certificate" class="certificate" :lang="language">
      <div v-if="certificate.status === 'cancelled'" class="cert-watermark" aria-hidden="true">{{ t('বাতিল', 'CANCELLED') }}</div>

      <header class="cert-header">
        <img v-if="logoUrl" :src="logoUrl" alt="" class="cert-logo" @error="logoFailed" />
        <div>
          <h2 class="cert-school">{{ bn ? school.name_bn || school.name_en : school.name_en || school.name_bn }}</h2>
          <p v-if="school.address" class="cert-muted">{{ school.address }}</p>
          <p v-if="school.eiin" class="cert-muted">{{ t('ইআইআইএন', 'EIIN') }}: {{ d(school.eiin) }}</p>
        </div>
      </header>

      <div class="cert-meta">
        <span>{{ t('ক্রমিক নং', 'Serial no.') }}: <strong>{{ certificate.serial_no }}</strong></span>
        <span>{{ t('তারিখ', 'Date') }}: <strong>{{ date(certificate.issued_on) }}</strong></span>
      </div>

      <h3 class="cert-title">{{ titleText }}</h3>

      <!-- Transfer certificate: a table of particulars. -->
      <table v-if="certificate.type === 'transfer'" class="cert-table">
        <tbody>
          <tr v-for="row in transferRows" :key="row[0]"><th>{{ row[0] }}</th><td>{{ row[1] }}</td></tr>
        </tbody>
      </table>

      <template v-else>
        <p class="cert-body">{{ bodyText }}</p>
        <p v-if="examText" class="cert-body">{{ examText }}</p>
        <p v-if="conductText" class="cert-body">{{ conductText }}</p>
        <p v-if="data.remarks" class="cert-body">{{ data.remarks }}</p>
      </template>

      <p v-if="certificate.status === 'cancelled'" class="cert-cancelled">
        {{ t('এই সনদ বাতিল করা হয়েছে', 'This certificate has been cancelled') }}<template v-if="certificate.cancel_reason">: {{ certificate.cancel_reason }}</template>
      </p>

      <footer class="cert-signatures">
        <div>
          <span></span>
          <strong v-if="data.class_teacher">{{ pick(data.class_teacher.name_bn, data.class_teacher.name_en) }}</strong>
          {{ t('শ্রেণি শিক্ষক', 'Class teacher') }}
        </div>
        <div>
          <span></span>
          <strong v-if="data.head_teacher">{{ pick(data.head_teacher.name_bn, data.head_teacher.name_en) }}</strong>
          {{ data.head_teacher?.designation || t('প্রধান শিক্ষক', 'Head teacher') }}
        </div>
      </footer>
    </article>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { CERTIFICATE_TYPES, DEFAULT_CONDUCT_BN, digits, longDate } from '@/utils/certificates'

const route = useRoute()

const certificate = ref(null)
const loading = ref(true)
const notice = ref('')
const language = ref('bn')
const bn = computed(() => language.value === 'bn')

const t = (bangla, english) => (bn.value ? bangla : english)
const d = (value) => digits(value, bn.value)
const date = (ymd) => longDate(ymd, bn.value)
const pick = (banglaText, englishText) => (bn.value ? banglaText || englishText : englishText || banglaText) || ''

// Everything printed comes from the snapshot taken when the certificate was issued.
const data = computed(() => certificate.value?.snapshot ?? {})
const school = computed(() => data.value.school ?? {})

// A reprint uses the logo URL stored in the snapshot. If that file has since been replaced
// or removed, fall back once to the current logo, and hide the image when that fails too.
const logoUrl = ref('')
const triedCurrentLogo = ref(false)
const logoFailed = async () => {
  if (triedCurrentLogo.value) {
    logoUrl.value = ''
    return
  }
  triedCurrentLogo.value = true
  try {
    const { data: response } = await api.get('/public/school')
    const current = response.data?.logo_url
    logoUrl.value = current && current !== logoUrl.value ? current : ''
  } catch (error) {
    logoUrl.value = ''
  }
}
const student = computed(() => data.value.student ?? {})
const placement = computed(() => data.value.placement ?? {})

const titleText = computed(() => {
  const type = CERTIFICATE_TYPES.find((item) => item.value === certificate.value?.type)
  return bn.value ? type?.bn : type?.label
})

const className = computed(() => pick(placement.value.class_name_bn, placement.value.class_name_en))
const studentName = computed(() => pick(student.value.name_bn, student.value.name_en))
const fatherName = computed(() => pick(student.value.father_name_bn, student.value.father_name_en))
const motherName = computed(() => pick(student.value.mother_name_bn, student.value.mother_name_en))
const schoolName = computed(() => pick(school.value.name_bn, school.value.name_en))
const groupName = computed(() => pick(placement.value.group_bn, placement.value.group_en))

// "Rahim, son/daughter of Karim and Rahima, born on 15 March 2012" in the printed language.
const identity = computed(() => {
  const parents = [fatherName.value, motherName.value].filter(Boolean).join(bn.value ? ' ও ' : ' and ')
  const parts = [studentName.value]
  if (parents) parts.push(bn.value ? `পিতা/মাতা: ${parents}` : `child of ${parents}`)
  if (student.value.date_of_birth) parts.push(bn.value ? `জন্ম তারিখ ${date(student.value.date_of_birth)}` : `born on ${date(student.value.date_of_birth)}`)
  return parts.join(', ')
})

const classText = computed(() => {
  const p = placement.value
  const parts = [className.value]
  if (groupName.value) parts.push(groupName.value)
  if (p.section) parts.push(bn.value ? `সেকশন ${p.section}` : `Section ${p.section}`)
  if (p.roll_number != null) parts.push(bn.value ? `রোল ${d(p.roll_number)}` : `roll ${p.roll_number}`)
  return parts.filter(Boolean).join(', ')
})

const bodyText = computed(() => {
  const type = certificate.value?.type
  const year = d(placement.value.academic_year)

  if (type === 'study') {
    return bn.value
      ? `এই মর্মে প্রত্যয়ন করা যাচ্ছে যে, ${identity.value}, ${schoolName.value}-এর একজন নিয়মিত শিক্ষার্থী এবং ${year} শিক্ষাবর্ষে ${classText.value}-এ অধ্যয়নরত আছে। শিক্ষার্থী আইডি ${d(student.value.student_id)}।`
      : `This is to certify that ${identity.value}, is a bona fide student of ${schoolName.value} and is currently studying in ${classText.value} in the academic year ${year}. Student ID ${student.value.student_id}.`
  }

  if (type === 'character') {
    return bn.value
      ? `এই মর্মে প্রত্যয়ন করা যাচ্ছে যে, ${identity.value}, ${schoolName.value}-এর ${classText.value}-এর শিক্ষার্থী (আইডি ${d(student.value.student_id)})। আমার জানা মতে তার চরিত্র ভালো এবং সে কোনো অনৈতিক কাজে জড়িত ছিল না।`
      : `This is to certify that ${identity.value}, is a student of ${classText.value} of ${schoolName.value} (ID ${student.value.student_id}). To the best of my knowledge, the student bears a good moral character and has not been involved in any improper activity.`
  }

  return bn.value
    ? `এই মর্মে প্রত্যয়ন করা যাচ্ছে যে, ${identity.value}, ${schoolName.value}-এর ${classText.value}-এর শিক্ষার্থী ছিল (আইডি ${d(student.value.student_id)})।`
    : `This is to certify that ${identity.value}, was a student of ${classText.value} of ${schoolName.value} (ID ${student.value.student_id}).`
})

// The testimonial's exam details, when any were entered.
const examText = computed(() => {
  if (certificate.value?.type !== 'testimonial') return ''
  const x = data.value
  const bits = []

  if (x.exam) bits.push(bn.value ? `${x.exam.toUpperCase()} পরীক্ষা` : `${x.exam.toUpperCase()} examination`)
  if (x.board) bits.push(bn.value ? `${x.board} বোর্ড` : `${x.board} Board`)
  if (x.passing_year) bits.push(bn.value ? `${d(x.passing_year)} সাল` : `year ${x.passing_year}`)
  if (x.session) bits.push(bn.value ? `সেশন ${d(x.session)}` : `session ${x.session}`)
  if (x.exam_roll) bits.push(bn.value ? `রোল ${d(x.exam_roll)}` : `roll ${x.exam_roll}`)
  if (x.registration_no) bits.push(bn.value ? `রেজিস্ট্রেশন নং ${d(x.registration_no)}` : `registration no. ${x.registration_no}`)
  if (x.gpa) bits.push(bn.value ? `জিপিএ ${d(x.gpa)}` : `GPA ${x.gpa}`)

  return bits.length ? bits.join(', ') + '.' : ''
})

// The default conduct is stored in Bangla, so print its English equivalent in English.
const conductText = computed(() => {
  const conduct = data.value.conduct
  if (!conduct || certificate.value?.type === 'study') return ''
  const shown = !bn.value && conduct === DEFAULT_CONDUCT_BN ? 'Good' : conduct

  return bn.value ? `তার আচরণ: ${shown}। আমি তার সর্বাঙ্গীণ উন্নতি কামনা করি।` : `Conduct: ${shown}. I wish the student every success.`
})

const dues = computed(() => data.value.dues ?? {})

const transferRows = computed(() => {
  const x = data.value
  const rows = [
    [t('শিক্ষার্থীর নাম', 'Student name'), studentName.value],
    [t('শিক্ষার্থী আইডি', 'Student ID'), d(student.value.student_id)],
    [t('পিতার নাম', "Father's name"), fatherName.value],
    [t('মাতার নাম', "Mother's name"), motherName.value],
    [t('জন্ম তারিখ', 'Date of birth'), date(student.value.date_of_birth)],
    [t('ভর্তির তারিখ', 'Admission date'), date(x.admission_date)],
    [t('সর্বশেষ শ্রেণি ও সেকশন', 'Last class and section'), [className.value, groupName.value, placement.value.section && `${t('সেকশন', 'Section')} ${placement.value.section}`].filter(Boolean).join(', ')],
    [t('সর্বশেষ উপস্থিতির তারিখ', 'Last attendance date'), date(x.last_attendance_date)],
    [t('ছাড়পত্র প্রদানের তারিখ', 'Date of leaving'), date(certificate.value.issued_on)],
    [t('ছাড়পত্র গ্রহণের কারণ', 'Reason for leaving'), x.reason],
    [t('আচরণ', 'Conduct'), !bn.value && x.conduct === DEFAULT_CONDUCT_BN ? 'Good' : x.conduct],
    [t('বকেয়া ফি', 'Fee dues'), dues.value.status === 'overridden' ? `${t('বকেয়া সহ ছাড়পত্র', 'Issued with dues outstanding')}: ${dues.value.note || ''}` : t('কোনো বকেয়া নেই', 'No dues')],
  ]
  return rows.filter((row) => row[1])
})

// @page can't be bound in an SFC style block, so the rule is written into a style element.
const PAGE_STYLE_ID = 'certificate-page-style'
const applyPageStyle = () => {
  let style = document.getElementById(PAGE_STYLE_ID)
  if (!style) {
    style = document.createElement('style')
    style.id = PAGE_STYLE_ID
    document.head.appendChild(style)
  }
  style.textContent = '@page { size: A4 portrait; margin: 15mm; }'
}

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

onMounted(async () => {
  // Marks the page so the print CSS can hide the admin chrome (sidebar and header).
  document.body.classList.add('certificate-page')
  loadFont()
  applyPageStyle()

  try {
    const { data: response } = await api.get(`/certificates/${route.params.id}`)
    certificate.value = response.data
    logoUrl.value = response.data.snapshot?.school?.logo_url || ''
  } catch (error) {
    notice.value = error.response?.data?.message || 'Failed to load the certificate'
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => {
  document.body.classList.remove('certificate-page')
  document.getElementById(PAGE_STYLE_ID)?.remove()
})
</script>

<style>
.certificate {
  position: relative;
  font-family: 'Noto Sans Bengali', 'Noto Sans', system-ui, sans-serif;
  background: #fff;
  color: #111827;
  border: 1px solid #d1d5db;
  padding: 32px 40px;
  margin: 0 auto 24px;
  max-width: 794px;
  min-height: 900px;
  font-size: 15px;
  line-height: 1.8;
  overflow: hidden;
}
.cert-header { display: flex; align-items: center; justify-content: center; gap: 18px; text-align: center; }
.cert-logo { height: 70px; width: 70px; object-fit: contain; }
.cert-school { font-size: 24px; font-weight: 700; margin: 0; line-height: 1.3; }
.cert-muted { color: #4b5563; font-size: 12px; margin: 0; line-height: 1.4; }
.cert-meta { display: flex; justify-content: space-between; margin-top: 20px; font-size: 13px; }
.cert-title { text-align: center; font-size: 22px; font-weight: 700; margin: 22px auto 18px; padding: 2px 24px; border-bottom: 2px solid #111827; width: fit-content; }
.cert-body { text-align: justify; margin: 0 0 12px; }
.cert-table { width: 100%; border-collapse: collapse; font-size: 14px; line-height: 1.5; }
.cert-table th, .cert-table td { border: 1px solid #9ca3af; padding: 6px 10px; text-align: left; vertical-align: top; }
.cert-table th { width: 40%; background: #f3f4f6; font-weight: 600; }
.cert-cancelled { color: #b91c1c; font-weight: 600; margin-top: 16px; }
.cert-signatures { display: grid; grid-template-columns: repeat(2, 1fr); gap: 80px; margin-top: 110px; text-align: center; font-size: 13px; line-height: 1.4; }
.cert-signatures span { display: block; border-top: 1px solid #111827; margin-bottom: 4px; }
.cert-signatures strong { display: block; }
.cert-watermark {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 130px;
  font-weight: 800;
  letter-spacing: 0.1em;
  color: rgba(185, 28, 28, 0.18);
  transform: rotate(-30deg);
  pointer-events: none;
  user-select: none;
}

@media print {
  body.certificate-page aside,
  body.certificate-page header:not(.cert-header),
  body.certificate-page .no-print { display: none !important; }
  body.certificate-page .ml-64 { margin-left: 0 !important; }
  body.certificate-page main { padding: 0 !important; }
  body.certificate-page .min-h-screen { background: #fff !important; }

  .certificate { border: none; padding: 0; margin: 0; max-width: none; min-height: 0; }
  .cert-watermark { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
