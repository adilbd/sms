<template>
  <div class="admission-form-print">
    <div class="no-print flex flex-wrap items-center justify-between gap-3 mb-6">
      <h1 class="text-2xl font-bold text-gray-900">Print application form</h1>
      <div class="flex flex-wrap items-end gap-2">
        <label class="text-xs text-gray-600">Language
          <select v-model="language" class="input mt-0.5 block">
            <option value="bn">বাংলা</option>
            <option value="en">English</option>
          </select>
        </label>
        <router-link :to="`/admissions/applications/${route.params.id}`" class="btn btn-secondary">Back</router-link>
        <button type="button" class="btn btn-primary" :disabled="loading || !app" @click="print">Print</button>
      </div>
    </div>

    <div v-if="notice" class="no-print rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 mb-4">{{ notice }}</div>
    <p v-if="loading" class="no-print text-center text-gray-500 py-8">Loading application...</p>

    <article v-if="app" class="af-sheet" :lang="language">
      <header class="af-header">
        <img v-if="school?.logo_url" :src="school.logo_url" alt="" class="af-logo" />
        <div>
          <h2 class="af-school">{{ bn ? school?.name_bn || school?.name : school?.name || school?.name_bn }}</h2>
          <p v-if="bn ? school?.name : school?.name_bn" class="af-school-2">{{ bn ? school?.name : school?.name_bn }}</p>
          <p v-if="addressLine" class="af-muted">{{ addressLine }}</p>
        </div>
      </header>

      <h3 class="af-title">{{ t('ভর্তির আবেদনপত্র', 'Admission application form') }} – {{ pick(app.round?.name_bn, app.round?.name_en) }}</h3>

      <div class="af-top">
        <dl class="af-grid af-grid-2">
          <div><dt>{{ t('আবেদন নম্বর', 'Application number') }}</dt><dd class="af-strong">{{ app.application_no }}</dd></div>
          <div><dt>{{ t('জমার সময়', 'Submitted') }}</dt><dd>{{ dateTime(app.created_at) }}</dd></div>
          <div><dt>{{ t('বর্তমান অবস্থা', 'Status') }}</dt><dd>{{ statusLabel }}</dd></div>
          <div v-if="app.decided_at"><dt>{{ t('সিদ্ধান্তের সময়', 'Decided at') }}</dt><dd>{{ dateTime(app.decided_at) }}</dd></div>
        </dl>
        <img v-if="photoUrl" :src="photoUrl" alt="" class="af-photo" />
        <div v-else class="af-photo af-photo-empty">{{ t('ছবি নেই', 'No photo') }}</div>
      </div>

      <h4 class="af-section">{{ t('শিক্ষার্থী', 'Student') }}</h4>
      <dl class="af-grid">
        <div v-for="row in studentRows" :key="row.label"><dt>{{ row.label }}</dt><dd>{{ row.value || '-' }}</dd></div>
      </dl>

      <h4 class="af-section">{{ t('পিতা-মাতা ও অভিভাবক', 'Parents and guardian') }}</h4>
      <dl class="af-grid">
        <div v-for="row in familyRows" :key="row.label"><dt>{{ row.label }}</dt><dd>{{ row.value || '-' }}</dd></div>
      </dl>

      <h4 class="af-section">{{ t('পর্যালোচনা', 'Review') }}</h4>
      <dl class="af-grid">
        <div><dt>{{ t('পরীক্ষা / সাক্ষাৎকার', 'Test / interview') }}</dt><dd>{{ app.test_at ? dateTime(app.test_at) : '-' }}</dd></div>
        <div><dt>{{ t('স্থান', 'Venue') }}</dt><dd>{{ app.test_venue || '-' }}</dd></div>
        <div><dt>{{ t('নম্বর', 'Score') }}</dt><dd>{{ app.test_score !== null && app.test_score !== undefined ? d(app.test_score) : '-' }}</dd></div>
        <div v-if="'admin_note' in app" class="af-wide"><dt>{{ t('মন্তব্য', 'Note') }}</dt><dd class="af-pre">{{ app.admin_note || '-' }}</dd></div>
      </dl>

      <h4 class="af-section">{{ t('কাগজপত্রের তালিকা', 'Document checklist') }}</h4>
      <table class="af-table">
        <thead><tr><th>{{ t('কাগজ', 'Document') }}</th><th>{{ t('অবস্থা', 'Status') }}</th></tr></thead>
        <tbody>
          <tr v-for="doc in documents" :key="doc.kind">
            <td>{{ doc.label }}</td>
            <td>{{ app.files?.[doc.kind] ? '☑ ' + t('জমা হয়েছে', 'Received') : '☐ ' + t('জমা হয়নি', 'Missing') }}</td>
          </tr>
        </tbody>
      </table>

      <div class="af-signatures">
        <div><span></span>{{ t('অভিভাবকের স্বাক্ষর', "Guardian's signature") }}</div>
        <div><span></span>{{ t('ভর্তি কমিটি', 'Admission committee') }}</div>
        <div><span></span>{{ t('প্রধান শিক্ষক', 'Head teacher') }}</div>
      </div>
    </article>
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
const GENDERS = { male: ['ছেলে', 'Male'], female: ['মেয়ে', 'Female'], other: ['অন্যান্য', 'Other'] }
const RELIGIONS = { islam: ['ইসলাম', 'Islam'], hinduism: ['হিন্দু', 'Hinduism'], buddhism: ['বৌদ্ধ', 'Buddhism'], christianity: ['খ্রিষ্টান', 'Christianity'], other: ['অন্যান্য', 'Other'] }
const RELATIONS = { father: ['পিতা', 'Father'], mother: ['মাতা', 'Mother'], other: ['অন্যান্য', 'Other'] }

const route = useRoute()
const authStore = useAuthStore()
const app = ref(null)
const school = ref(null)
const photoUrl = ref('')
const loading = ref(true)
const notice = ref('')
const language = ref('bn')

const bn = computed(() => language.value === 'bn')
const t = (bangla, english) => (bn.value ? bangla : english)
const pick = (banglaValue, englishValue) => (bn.value ? banglaValue || englishValue : englishValue || banglaValue)
const d = (value) => (bn.value ? banglaNumber(value) : String(value))
const dateTime = (iso) => {
  if (!iso) return ''
  if (bn.value) return banglaDateTime(iso)
  return new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Dhaka', dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))
}
const dateOnly = (ymd) => {
  if (!ymd) return ''
  if (bn.value) return banglaDate(`${ymd}T00:00:00+06:00`)
  return new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Dhaka', dateStyle: 'medium' }).format(new Date(`${ymd}T00:00:00+06:00`))
}
const label = (map, key) => (map[key] ? map[key][bn.value ? 0 : 1] : '')
const join = (...parts) => parts.filter(Boolean).join(' / ')

const statusLabel = computed(() => (bn.value ? BANGLA_STATUS[app.value.status] : STATUS_LABELS[app.value.status]))
const addressLine = computed(() => {
  const a = school.value?.address
  return a ? [a.village || a.street, a.upazila, a.district].filter(Boolean).join(', ') : ''
})

const documents = computed(() => [
  { kind: 'photo', label: t('ছবি', 'Photo') },
  { kind: 'birth_certificate', label: t('জন্ম নিবন্ধন সনদ', 'Birth certificate') },
  { kind: 'previous_school_doc', label: t('ছাড়পত্র / পূর্ববর্তী স্কুলের কাগজ (TC)', 'Transfer certificate / previous school document (TC)') },
])

// Sensitive fields come back only for users with edit-students; the rows keep the same
// shape and show "-" when the API omitted them.
const studentRows = computed(() => {
  const a = app.value
  return [
    { label: t('শ্রেণি', 'Class'), value: pick(a.class?.name_bn, a.class?.name) },
    { label: t('গ্রুপ', 'Group'), value: bn.value ? BANGLA_GROUPS[a.group] : GROUP_LABELS[a.group] },
    { label: t('শিফট', 'Preferred shift'), value: pick(a.shift?.name_bn, a.shift?.name_en) },
    { label: t('নাম (বাংলা)', 'Name (Bangla)'), value: a.name_bn },
    { label: t('নাম (ইংরেজি)', 'Name (English)'), value: a.name_en },
    { label: t('জন্ম তারিখ', 'Date of birth'), value: dateOnly(a.date_of_birth) },
    { label: t('লিঙ্গ', 'Gender'), value: label(GENDERS, a.gender) },
    { label: t('ধর্ম', 'Religion'), value: label(RELIGIONS, a.religion) },
    { label: t('জন্ম নিবন্ধন নম্বর', 'Birth registration no.'), value: a.birth_registration_number },
    { label: t('রক্তের গ্রুপ', 'Blood group'), value: a.blood_group },
    { label: t('জাতীয়তা', 'Nationality'), value: a.nationality },
    { label: t('পূর্ববর্তী স্কুল', 'Previous school'), value: a.previous_school },
    { label: t('পূর্ববর্তী শ্রেণি', 'Previous class'), value: a.previous_class },
    { label: t('বর্তমান ঠিকানা', 'Present address'), value: a.present_address },
    { label: t('স্থায়ী ঠিকানা', 'Permanent address'), value: a.permanent_address },
    { label: t('জেলা', 'District'), value: a.district },
  ]
})

const familyRows = computed(() => {
  const a = app.value
  return [
    { label: t('পিতার নাম', 'Father'), value: join(a.father?.name_bn, a.father?.name_en) },
    { label: t('পিতার মোবাইল', 'Father mobile'), value: a.father?.mobile && d(a.father.mobile) },
    { label: t('মাতার নাম', 'Mother'), value: join(a.mother?.name_bn, a.mother?.name_en) },
    { label: t('মাতার মোবাইল', 'Mother mobile'), value: a.mother?.mobile && d(a.mother.mobile) },
    { label: t('অভিভাবক', 'Guardian'), value: [a.guardian?.name, label(RELATIONS, a.guardian?.relation) && `(${label(RELATIONS, a.guardian.relation)})`].filter(Boolean).join(' ') },
    { label: t('অভিভাবকের মোবাইল', 'Guardian mobile'), value: a.guardian?.mobile && d(a.guardian.mobile) },
    { label: t('অভিভাবকের ইমেইল', 'Guardian email'), value: a.guardian?.email },
  ]
})

// @page can't be bound in an SFC style block, so the page rule goes in a style element.
const PAGE_STYLE_ID = 'admission-form-page'
const applyPageStyle = () => {
  let style = document.getElementById(PAGE_STYLE_ID)
  if (!style) {
    style = document.createElement('style')
    style.id = PAGE_STYLE_ID
    document.head.appendChild(style)
  }
  style.textContent = '@page { size: A4 portrait; margin: 12mm; }'
}

const print = () => window.print()

// The photo is on the private disk behind the bearer token, so it is fetched as a blob
// through the API client (like ApplicationDetail.vue) and shown from an object URL.
const loadPhoto = async () => {
  try {
    const response = await api.get(`/admission-applications/${route.params.id}/files/photo`, { responseType: 'blob' })
    photoUrl.value = URL.createObjectURL(response.data)
  } catch (error) {
    photoUrl.value = ''
  }
}

onMounted(async () => {
  document.body.classList.add('admission-print-page')
  applyPageStyle()

  try {
    const [appRes, schoolRes] = await Promise.all([
      api.get(`/admission-applications/${route.params.id}`),
      api.get('/public/school'),
    ])
    app.value = appRes.data.data
    school.value = schoolRes.data.data
    // Only users with edit-students may download documents.
    if (app.value.files?.photo && authStore.hasPermission('edit-students')) await loadPhoto()
  } catch (error) {
    notice.value = error.response?.data?.message || 'Failed to load the application'
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => {
  document.body.classList.remove('admission-print-page')
  document.getElementById(PAGE_STYLE_ID)?.remove()
  if (photoUrl.value) URL.revokeObjectURL(photoUrl.value)
})
</script>

<style>
.af-sheet { font-family: 'Noto Sans Bengali', 'Noto Sans', system-ui, sans-serif; background: #fff; color: #111827; border: 1px solid #d1d5db; border-radius: 8px; padding: 24px 28px; margin: 0 auto; max-width: 800px; font-size: 13px; line-height: 1.45; }
.af-header { display: flex; align-items: center; justify-content: center; gap: 16px; text-align: center; }
.af-logo { height: 64px; width: 64px; object-fit: contain; }
.af-school { font-size: 20px; font-weight: 700; margin: 0; }
.af-school-2 { font-size: 14px; font-weight: 600; margin: 0; }
.af-muted { color: #6b7280; font-size: 11px; margin: 0; }
.af-title { text-align: center; font-size: 15px; font-weight: 700; margin: 12px 0; padding: 4px 0; border-top: 1px solid #111827; border-bottom: 1px solid #111827; }
.af-top { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
.af-photo { width: 110px; height: 130px; object-fit: cover; border: 1px solid #6b7280; flex-shrink: 0; }
.af-photo-empty { display: flex; align-items: center; justify-content: center; color: #6b7280; font-size: 11px; }
.af-section { font-size: 13px; font-weight: 700; margin: 14px 0 6px; padding-bottom: 2px; border-bottom: 1px solid #9ca3af; }
.af-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px 16px; margin: 0; }
.af-grid-2 { grid-template-columns: repeat(2, 1fr); flex: 1; }
.af-grid dt { font-size: 10px; color: #6b7280; }
.af-grid dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
.af-wide { grid-column: span 3; }
.af-strong { font-size: 15px; font-weight: 700 !important; }
.af-pre { white-space: pre-line; }
.af-table { width: 100%; border-collapse: collapse; }
.af-table th, .af-table td { border: 1px solid #9ca3af; padding: 4px 8px; text-align: left; }
.af-table th { background: #f3f4f6; }
.af-signatures { display: grid; grid-template-columns: repeat(3, 1fr); gap: 32px; margin-top: 56px; text-align: center; font-size: 12px; }
.af-signatures span { display: block; border-top: 1px solid #111827; margin-bottom: 4px; }

@media print {
  body.admission-print-page aside,
  body.admission-print-page header:not(.af-header),
  body.admission-print-page .no-print { display: none !important; }
  body.admission-print-page .ml-64 { margin-left: 0 !important; }
  body.admission-print-page main { padding: 0 !important; }
  body.admission-print-page .min-h-screen { background: #fff !important; }
  .af-sheet { border: none; border-radius: 0; padding: 0; margin: 0; max-width: none; break-inside: avoid; }
}
</style>
