<template>
  <div class="routine-print">
    <div class="no-print flex flex-wrap items-center justify-between gap-3 mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Print routine</h1>
        <p class="text-sm text-gray-500">A4 landscape. Choose "Save as PDF" in the print dialog to make a PDF.</p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="text-xs text-gray-600">Language
          <select v-model="language" class="input mt-0.5 block">
            <option value="bn">বাংলা</option>
            <option value="en">English</option>
          </select>
        </label>
        <button type="button" class="btn btn-secondary" @click="router.back()">Back</button>
        <button type="button" class="btn btn-primary" :disabled="loading || !routine" @click="print">🖨️ Print</button>
      </div>
    </div>

    <div v-if="notice" class="no-print rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 mb-4">{{ notice }}</div>
    <p v-if="loading" class="no-print text-center text-gray-500 py-8">Loading routine...</p>

    <article v-if="routine" class="routine-sheet" :lang="language">
      <header class="rs-header">
        <img v-if="school?.logo_url" :src="school.logo_url" alt="" class="rs-logo" />
        <div>
          <h2 class="rs-school">{{ bn ? school?.name_bn || school?.name : school?.name || school?.name_bn }}</h2>
          <p v-if="addressLine" class="rs-muted">{{ addressLine }}</p>
        </div>
      </header>

      <h3 class="rs-title">
        {{ bn ? 'ক্লাস রুটিন' : 'Class routine' }}
        <template v-if="year"> – {{ bn ? banglaNumber(year.year) : year.year }}</template>
      </h3>

      <p class="rs-subtitle">
        <template v-if="routine.kind === 'teacher'">
          {{ bn ? 'শিক্ষক' : 'Teacher' }}: <strong>{{ pick(routine.staff?.name_bn, routine.staff?.name_en, bn) }}</strong>
          <span v-if="routine.staff?.designation"> ({{ routine.staff.designation }})</span>
        </template>
        <template v-else-if="routine.section">
          {{ bn ? 'শ্রেণি' : 'Class' }}: <strong>{{ pick(routine.section.class?.name_bn, routine.section.class?.name, bn) }}</strong>
          · {{ bn ? 'সেকশন' : 'Section' }}: <strong>{{ routine.section.name }}</strong>
          <template v-if="routine.section.shift"> · {{ bn ? 'শিফট' : 'Shift' }}: <strong>{{ pick(routine.section.shift.name_bn, routine.section.shift.name_en, bn) }}</strong></template>
          <template v-if="routine.section.group"> · {{ bn ? 'গ্রুপ' : 'Group' }}: <strong>{{ bn ? BANGLA_GROUPS[routine.section.group] : GROUP_LABELS[routine.section.group] }}</strong></template>
        </template>
      </p>

      <RoutineGrid :routine="routine" :bn="bn" />
    </article>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { banglaNumber } from '@/utils/banglaNumber'
import { GROUP_LABELS } from '@/constants/academic'
import { pick } from '@/utils/routine'
import RoutineGrid from '@/components/RoutineGrid.vue'

const route = useRoute()
const router = useRouter()

// route.meta.kind: 'section' (/routines/sections/:id/print), 'teacher'
// (/routines/teachers/:id/print) or 'mine' (/my-routine/print, the signed-in teacher).
const routine = ref(null)
const school = ref(null)
const loading = ref(true)
const notice = ref('')
const language = ref('bn')
const bn = computed(() => language.value === 'bn')
const year = computed(() => routine.value?.academic_year ?? null)

const BANGLA_GROUPS = { science: 'বিজ্ঞান', business_studies: 'ব্যবসায় শিক্ষা', humanities: 'মানবিক' }

const addressLine = computed(() => {
  const a = school.value?.address
  return a ? [a.village || a.street, a.upazila, a.district].filter(Boolean).join(', ') : ''
})

const print = () => window.print()

const urlFor = () => {
  switch (route.meta.kind) {
    case 'section': return `/routines/sections/${route.params.id}`
    case 'teacher': return `/routines/teachers/${route.params.id}`
    default: return '/my/routine'
  }
}

const PAGE_STYLE_ID = 'routine-page'

onMounted(async () => {
  // Marks the page so the print CSS can hide the admin chrome (sidebar and header).
  document.body.classList.add('routine-print-page')

  const style = document.createElement('style')
  style.id = PAGE_STYLE_ID
  style.textContent = '@page { size: A4 landscape; margin: 10mm; }'
  document.head.appendChild(style)

  try {
    const params = route.query.academic_year_id && route.meta.kind !== 'mine' ? { academic_year_id: route.query.academic_year_id } : {}
    const [routineRes, schoolRes] = await Promise.all([api.get(urlFor(), { params }), api.get('/public/school')])
    routine.value = routineRes.data.data
    school.value = schoolRes.data.data
  } catch (error) {
    notice.value = error.response?.data?.message || 'Failed to load the routine'
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => {
  document.body.classList.remove('routine-print-page')
  document.getElementById(PAGE_STYLE_ID)?.remove()
})
</script>

<style>
.routine-sheet {
  font-family: 'Noto Sans Bengali', 'Noto Sans', system-ui, sans-serif;
  background: #fff;
  color: #111827;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  padding: 20px 24px;
  margin: 0 auto;
  max-width: 1100px;
  font-size: 13px;
}
.rs-header { display: flex; align-items: center; justify-content: center; gap: 16px; text-align: center; }
.rs-logo { height: 56px; width: 56px; object-fit: contain; }
.rs-school { font-size: 20px; font-weight: 700; margin: 0; }
.rs-muted { color: #6b7280; font-size: 11px; margin: 0; }
.rs-title { text-align: center; font-size: 15px; font-weight: 700; margin: 10px 0; padding: 4px 0; border-top: 1px solid #111827; border-bottom: 1px solid #111827; }
.rs-subtitle { text-align: center; margin: 0 0 10px; }

@media print {
  body.routine-print-page aside,
  body.routine-print-page header:not(.rs-header),
  body.routine-print-page .no-print { display: none !important; }
  body.routine-print-page .ml-64 { margin-left: 0 !important; }
  body.routine-print-page main { padding: 0 !important; }
  body.routine-print-page .min-h-screen { background: #fff !important; }
  .routine-sheet { border: none; border-radius: 0; padding: 0; max-width: none; }
}
</style>
