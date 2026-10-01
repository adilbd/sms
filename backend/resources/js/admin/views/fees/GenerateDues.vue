<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Generate dues</h1>
      <p class="text-sm text-gray-500">
        Creates the fee dues of the active students from the rates. Generating again never duplicates or changes a due that exists.
        Waivers are applied as the dues are created. Without a month, every month from January up to the current month is generated.
      </p>
    </div>

    <div v-if="notice" :class="['rounded-lg border px-4 py-3 text-sm', notice.ok ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700']" role="status">
      {{ notice.text }}
    </div>

    <form class="card space-y-4" @submit.prevent="generate">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="gen-year">Academic year</label>
          <select id="gen-year" v-model="form.academic_year_id" class="input" required @change="onYearChange">
            <option v-for="year in years" :key="year.id" :value="year.id">{{ year.name }}{{ year.is_active ? ' (current)' : '' }}</option>
          </select>
          <p v-if="errors.academic_year_id" class="text-sm text-red-600 mt-1">{{ errors.academic_year_id[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="gen-month">Month (optional)</label>
          <input id="gen-month" v-model="form.month" type="month" class="input" @change="clearPreview" />
          <p v-if="errors.month" class="text-sm text-red-600 mt-1">{{ errors.month[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="gen-head">Fee head (optional)</label>
          <select id="gen-head" v-model="form.fee_head_id" class="input" @change="onHeadChange">
            <option value="">All monthly and one-time heads</option>
            <option v-for="head in heads" :key="head.id" :value="head.id">{{ head.name_en || head.name_bn }} ({{ KIND_LABELS[head.kind] }})</option>
          </select>
          <p v-if="errors.fee_head_id" class="text-sm text-red-600 mt-1">{{ errors.fee_head_id[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="gen-class">Class (optional)</label>
          <select id="gen-class" v-model="form.class_id" class="input" @change="onClassChange">
            <option value="">All classes</option>
            <option v-for="cls in classes" :key="cls.id" :value="cls.id">{{ cls.name }}</option>
          </select>
          <p v-if="errors.class_id" class="text-sm text-red-600 mt-1">{{ errors.class_id[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="gen-section">Section (optional)</label>
          <select id="gen-section" v-model="form.section_id" class="input" @change="clearPreview">
            <option value="">All sections</option>
            <option v-for="section in sectionOptions" :key="section.id" :value="section.id">{{ section.class?.name }} - {{ section.name }}</option>
          </select>
          <p v-if="errors.section_id" class="text-sm text-red-600 mt-1">{{ errors.section_id[0] }}</p>
        </div>
        <div v-if="isExamHead">
          <label class="block text-sm font-medium text-gray-700 mb-1" for="gen-exam">Exam</label>
          <select id="gen-exam" v-model="form.exam_id" class="input" required @change="clearPreview">
            <option value="">Choose the exam</option>
            <option v-for="exam in examOptions" :key="exam.id" :value="exam.id">{{ exam.name_en || exam.name_bn }}</option>
          </select>
          <p v-if="errors.exam_id" class="text-sm text-red-600 mt-1">{{ errors.exam_id[0] }}</p>
        </div>
      </div>

      <div v-if="preview" class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800" role="status">
        <strong>{{ preview.created }}</strong> new due{{ preview.created === 1 ? '' : 's' }} would be created,
        <strong>{{ preview.skipped }}</strong> already exist<template v-if="preview.no_rate">, and <strong>{{ preview.no_rate }}</strong> have no rate</template>.
      </div>

      <div class="flex gap-2">
        <button type="button" class="btn btn-secondary" :disabled="busy" @click="run(true)">Preview</button>
        <button type="submit" class="btn btn-primary" :disabled="busy">{{ busy ? 'Working...' : 'Generate dues' }}</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { KIND_LABELS } from '@/constants/fees'

const years = ref([])
const heads = ref([])
const classes = ref([])
const sections = ref([])
const exams = ref([])
const notice = ref(null)
const errors = ref({})
const preview = ref(null)
const busy = ref(false)
const form = reactive({ academic_year_id: '', month: '', fee_head_id: '', class_id: '', section_id: '', exam_id: '' })

const selectedHead = computed(() => heads.value.find((head) => head.id === form.fee_head_id))
const isExamHead = computed(() => selectedHead.value?.kind === 'per_exam')
const sectionOptions = computed(() => sections.value.filter((section) => !form.class_id || section.class_id === form.class_id))
const examOptions = computed(() => exams.value.filter((exam) => exam.academic_year_id === form.academic_year_id))

const clearPreview = () => {
  preview.value = null
}

const onYearChange = () => {
  form.exam_id = ''
  clearPreview()
}

const onHeadChange = () => {
  form.exam_id = ''
  clearPreview()
}

const onClassChange = () => {
  form.section_id = ''
  clearPreview()
}

// Empty fields are left out so the server applies its defaults.
const payload = (dryRun) => {
  const body = { academic_year_id: form.academic_year_id }
  for (const key of ['month', 'fee_head_id', 'class_id', 'section_id']) {
    if (form[key] !== '' && form[key] !== null) body[key] = form[key]
  }
  if (isExamHead.value && form.exam_id) body.exam_id = form.exam_id
  if (dryRun) body.dry_run = true

  return body
}

const run = async (dryRun) => {
  errors.value = {}
  notice.value = null
  busy.value = true

  try {
    const { data } = await api.post('/fee-dues/generate', payload(dryRun))
    if (dryRun) {
      preview.value = data.data
    } else {
      preview.value = null
      notice.value = {
        ok: true,
        text: `${data.data.created} due${data.data.created === 1 ? '' : 's'} created, ${data.data.skipped} already existed${data.data.no_rate ? `, ${data.data.no_rate} without a rate` : ''}.`,
      }
    }
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors ?? {}
    } else {
      notice.value = { ok: false, text: error.response?.data?.message || 'Failed to generate the dues' }
    }
  } finally {
    busy.value = false
  }
}

const generate = () => {
  if (!confirm('Generate these fee dues now?')) return

  run(false)
}

onMounted(async () => {
  try {
    const [yearsRes, headsRes, classesRes, sectionsRes] = await Promise.all([
      api.get('/academic-years', { params: { per_page: 100 } }),
      api.get('/fee-heads', { params: { per_page: 100, is_active: true } }),
      api.get('/classes', { params: { per_page: 100 } }),
      api.get('/sections', { params: { per_page: 100 } }),
    ])
    years.value = yearsRes.data.data
    heads.value = headsRes.data.data
    classes.value = classesRes.data.data
    sections.value = sectionsRes.data.data
    form.academic_year_id = years.value.find((year) => year.is_active)?.id ?? years.value[0]?.id ?? ''
  } catch (error) {
    notice.value = { ok: false, text: error.response?.data?.message || 'Failed to load the page' }
  }

  // The exam list needs the exams permission, which the office role does not have.
  try {
    const { data } = await api.get('/exams', { params: { per_page: 100 } })
    exams.value = data.data
  } catch (error) {
    exams.value = []
  }
})
</script>
