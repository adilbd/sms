<template>
  <div class="space-y-6">
    <div class="flex flex-wrap justify-between items-center gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">
          Results<span v-if="exam"> – {{ exam.name_en || exam.name_bn }}</span>
        </h1>
        <p v-if="exam" class="text-sm text-gray-500">
          <span :class="['badge', EXAM_STATUS_BADGES[exam.status]]">{{ EXAM_STATUS_LABELS[exam.status] || exam.status }}</span>
          <span v-if="exam.published_at" class="ml-2">published {{ new Date(exam.published_at).toLocaleString() }}</span>
        </p>
      </div>
      <div class="flex flex-wrap gap-2">
        <router-link to="/exams" class="btn btn-secondary">Back to exams</router-link>
        <button
          v-if="canProcess"
          type="button"
          class="btn btn-primary"
          :disabled="busy"
          @click="process"
        >
          {{ exam.status === 'processed' ? 'Process again' : 'Process results' }}
        </button>
        <button v-if="exam?.status === 'processed'" type="button" class="btn btn-primary" :disabled="busy" @click="publish">Publish</button>
        <button v-if="exam?.status === 'processed'" type="button" class="btn btn-secondary" :disabled="busy" @click="reopen">Reopen mark entry</button>
        <button v-if="exam?.status === 'published'" type="button" class="btn btn-secondary" :disabled="busy" @click="unpublish">Unpublish</button>
      </div>
    </div>

    <div v-if="notice" :class="['rounded-lg border px-4 py-3 text-sm', notice.ok ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700']">
      {{ notice.text }}
    </div>

    <div v-if="exam?.status === 'draft'" class="rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-800">
      Mark entry has not been opened for this exam yet, so there is nothing to process.
    </div>
    <div v-else-if="exam?.status === 'marks_entry'" class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      Marks are still being entered. Process the results to grade everything entered so far; anything missing counts as absent (an F).
    </div>

    <div v-if="summary" class="card">
      <h2 class="text-lg font-semibold text-gray-900 mb-3">Processing summary</h2>
      <div class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Class</th>
              <th>Section</th>
              <th class="text-right">Students</th>
              <th class="text-right">Passed</th>
              <th class="text-right">Failed</th>
              <th class="text-right">Missing marks</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in summary" :key="`${row.class_id}-${row.section_id}`">
              <td>{{ row.class_name }}</td>
              <td>{{ row.section_name }}</td>
              <td class="text-right">{{ row.students }}</td>
              <td class="text-right text-green-700">{{ row.passed }}</td>
              <td class="text-right text-red-700">{{ row.failed }}</td>
              <td :class="['text-right', row.missing_marks > 0 ? 'font-semibold text-yellow-700' : '']">{{ row.missing_marks }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="mt-2 text-xs text-gray-500">
        Missing marks are papers with no marks entered (or only some parts). They were graded as absent.
      </p>
    </div>

    <div class="card">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Class</label>
          <select v-model="filters.class_id" class="input" @change="onClassChange">
            <option value="">All classes</option>
            <option v-for="klass in exam?.classes || []" :key="klass.id" :value="klass.id">{{ klass.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
          <select v-model="filters.section_id" class="input" :disabled="!filters.class_id" @change="fetchResults(1)">
            <option value="">All sections</option>
            <option v-for="section in sections" :key="section.id" :value="section.id">{{ section.name }}</option>
          </select>
        </div>
        <div class="flex items-end">
          <router-link
            v-if="filters.class_id && results.length > 0"
            :to="{ path: `/exams/${route.params.id}/report-cards`, query: { class_id: filters.class_id, ...(filters.section_id ? { section_id: filters.section_id } : {}) } }"
            class="btn btn-secondary"
          >
            Print report cards
          </router-link>
          <p v-else-if="results.length > 0" class="text-sm text-gray-500">Pick a class to print its report cards.</p>
        </div>
      </div>
    </div>

    <div class="card">
      <div v-if="loading" class="text-center py-8"><p class="text-gray-500">Loading results...</p></div>
      <div v-else-if="results.length === 0" class="text-center py-8">
        <p class="text-gray-500">No results yet. Process the exam to calculate them.</p>
      </div>
      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th class="text-right">{{ filters.section_id ? 'Section pos.' : 'Class pos.' }}</th>
              <th>Roll</th>
              <th>Student</th>
              <th>Group</th>
              <th class="text-right">Total</th>
              <th class="text-right">GPA</th>
              <th>Grade</th>
              <th class="text-right">Subjects passed</th>
              <th class="text-right">Failed</th>
              <th>Report card</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in results" :key="row.id" :class="row.is_pass ? '' : 'bg-red-50'">
              <td class="text-right font-medium">{{ filters.section_id ? row.section_position : row.class_position }}</td>
              <td>{{ row.roll_number ?? '-' }}</td>
              <td class="font-medium">
                {{ row.student?.name_en || row.student?.name_bn }}
                <span v-if="row.student?.name_en && row.student?.name_bn" class="text-gray-500">({{ row.student.name_bn }})</span>
                <div class="text-xs text-gray-500">{{ row.student?.student_code }} · {{ row.class?.name }}</div>
              </td>
              <td>{{ GROUP_LABELS[row.group] || '-' }}</td>
              <td class="text-right">{{ Number(row.total_obtained) }} / {{ Number(row.total_full) }}</td>
              <td class="text-right font-medium">{{ row.gpa }}</td>
              <td><span :class="['badge', row.is_pass ? 'badge-success' : 'badge-danger']">{{ row.grade }}</span></td>
              <td class="text-right">{{ row.passed_count }} of {{ row.passed_count + row.failed_count }}</td>
              <td class="text-right">{{ row.failed_count }}</td>
              <td>
                <router-link :to="`/exams/${route.params.id}/report-cards/${row.student_id}`" class="text-sm text-primary-600 hover:text-primary-800">Print</router-link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="pagination.total > 0" class="mt-4 flex justify-between items-center">
        <p class="text-sm text-gray-700">Showing {{ pagination.from }} to {{ pagination.to }} of {{ pagination.total }} results</p>
        <div class="flex space-x-2">
          <button :disabled="pagination.current_page === 1" class="btn btn-secondary disabled:opacity-50" @click="fetchResults(pagination.current_page - 1)">Previous</button>
          <button :disabled="pagination.current_page === pagination.last_page" class="btn btn-secondary disabled:opacity-50" @click="fetchResults(pagination.current_page + 1)">Next</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { GROUP_LABELS } from '@/constants/academic'
import { EXAM_STATUS_BADGES, EXAM_STATUS_LABELS } from '@/constants/exams'

const route = useRoute()

const exam = ref(null)
const results = ref([])
const sections = ref([])
const summary = ref(null)
const loading = ref(false)
const busy = ref(false)
const notice = ref(null)
const filters = reactive({ class_id: '', section_id: '' })
const pagination = reactive({ current_page: 1, last_page: 1, per_page: 25, total: 0, from: 0, to: 0 })

const canProcess = computed(() => ['marks_entry', 'processed'].includes(exam.value?.status))

const fetchExam = async () => {
  const { data } = await api.get(`/exams/${route.params.id}`)
  exam.value = data.data
}

const fetchResults = async (page = 1) => {
  loading.value = true
  try {
    const params = { page, per_page: pagination.per_page }
    if (filters.class_id) params.class_id = filters.class_id
    if (filters.section_id) params.section_id = filters.section_id

    const { data } = await api.get(`/exams/${route.params.id}/results`, { params })
    results.value = data.data
    Object.assign(pagination, {
      current_page: data.meta.current_page,
      last_page: data.meta.last_page,
      total: data.meta.total,
      from: data.meta.from,
      to: data.meta.to,
    })
  } catch (error) {
    notice.value = { ok: false, text: error.response?.data?.message || 'Failed to load the results' }
  } finally {
    loading.value = false
  }
}

const onClassChange = async () => {
  filters.section_id = ''
  sections.value = []

  if (filters.class_id) {
    try {
      const { data } = await api.get('/sections', { params: { class_id: filters.class_id, per_page: 100 } })
      sections.value = data.data
    } catch (error) {
      notice.value = { ok: false, text: error.response?.data?.message || 'Failed to load the sections' }
    }
  }

  await fetchResults(1)
}

// Runs one of the exam's status actions and reloads what it changed.
const act = async (path, confirmText, done) => {
  if (confirmText && !confirm(confirmText)) return

  busy.value = true
  notice.value = null
  try {
    const { data } = await api.post(`/exams/${route.params.id}/${path}`)
    done(data)
    await fetchExam()
    await fetchResults(1)
    notice.value = { ok: true, text: data.message }
  } catch (error) {
    // 409: the exam isn't in the right state (published, marks changed since processing...).
    notice.value = { ok: false, text: error.response?.data?.message || 'The action failed' }
    await fetchExam().catch(() => {})
  } finally {
    busy.value = false
  }
}

const process = () => act('process', null, (data) => {
  summary.value = data.data.summary
})

const publish = () => act(
  'publish',
  'Publish these results? Students and guardians will be able to see them.',
  () => {},
)

const reopen = () => act(
  'reopen',
  'Reopen mark entry? The processed results will be cleared and must be processed again.',
  () => {
    summary.value = null
  },
)

const unpublish = () => act(
  'unpublish',
  'Unpublish these results? Students and guardians will no longer see them.',
  () => {},
)

onMounted(async () => {
  try {
    await fetchExam()
  } catch (error) {
    notice.value = { ok: false, text: error.response?.data?.message || 'Failed to load the exam' }
    return
  }
  await fetchResults(1)
})
</script>
