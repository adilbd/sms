<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">
          Exam schedule<span v-if="exam"> – {{ exam.name_en || exam.name_bn }}</span>
        </h1>
        <p v-if="exam" class="text-sm text-gray-500">
          {{ exam.start_date }} – {{ exam.end_date }}. Times are Asia/Dhaka. Subjects and part marks are a copy of each class's curriculum
          taken when the class was added; editing the marks is refused once any marks are entered for the subject.
        </p>
      </div>
      <div class="flex space-x-2">
        <router-link v-if="exam" :to="`/exams/${exam.id}/marks`" class="btn btn-secondary">Mark entry</router-link>
        <router-link to="/exams" class="btn btn-secondary">Back to exams</router-link>
      </div>
    </div>

    <div v-if="message" :class="['rounded-lg border px-4 py-3 text-sm', message.ok ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700']">
      {{ message.text }}
    </div>

    <div v-if="loading" class="card"><p class="text-gray-500 text-center py-8">Loading...</p></div>

    <template v-else-if="exam">
      <div class="card flex flex-wrap items-end gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Class</label>
          <select v-model="classId" class="input" @change="loadSubjects">
            <option v-for="klass in exam.classes" :key="klass.id" :value="klass.id">{{ klass.name }}</option>
          </select>
        </div>
        <button type="button" class="btn btn-secondary" :disabled="!classId || regenerating" @click="regenerate">
          {{ regenerating ? 'Regenerating...' : 'Regenerate from curriculum' }}
        </button>
      </div>

      <div class="card">
        <p v-if="rows.length === 0" class="text-center text-gray-500 py-8">No subjects scheduled for this class.</p>

        <div v-else class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Subject</th>
                <th>Date</th>
                <th>Start</th>
                <th>End</th>
                <th v-for="part in MARK_PARTS" :key="part.key" class="text-center">{{ part.label }}<br /><span class="font-normal text-xs">full / pass</span></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.id">
                <td class="font-medium">
                  {{ row.subject?.name }}
                  <span v-if="row.subject?.name_bn" class="text-gray-500">({{ row.subject.name_bn }})</span>
                  <div class="mt-1 space-x-1">
                    <span v-if="row.group" class="badge">{{ GROUP_LABELS[row.group] || row.group }}</span>
                    <span v-if="row.choice_group" class="badge badge-info">Either/or</span>
                    <span v-else-if="row.type === 'optional'" class="badge badge-info">Optional (4th)</span>
                    <span v-if="row.paper_group" class="badge badge-info">Paired: {{ row.paper_group }}</span>
                  </div>
                </td>
                <td><input v-model="row.exam_date" type="date" class="input" /></td>
                <td><input v-model="row.start_time" type="time" class="input" /></td>
                <td><input v-model="row.end_time" type="time" class="input" /></td>
                <td v-for="part in MARK_PARTS" :key="part.key">
                  <div class="flex items-center space-x-1">
                    <input v-model="row[`${part.key}_full`]" type="number" min="0" max="1000" class="input w-20" placeholder="-" />
                    <span class="text-gray-400">/</span>
                    <input v-model="row[`${part.key}_pass`]" type="number" min="0" max="1000" class="input w-20" placeholder="-" />
                  </div>
                  <p v-for="text in fieldErrors(row, part.key)" :key="text" class="text-xs text-red-600 mt-1">{{ text }}</p>
                </td>
                <td>
                  <button type="button" class="btn btn-primary" :disabled="savingId === row.id" @click="saveRow(row)">
                    {{ savingId === row.id ? 'Saving...' : 'Save' }}
                  </button>
                  <p v-for="text in fieldErrors(row, 'time')" :key="text" class="text-xs text-red-600 mt-1">{{ text }}</p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { GROUP_LABELS } from '@/constants/academic'
import { MARK_PARTS } from '@/constants/exams'

const route = useRoute()

const exam = ref(null)
const classId = ref('')
const rows = ref([])
const loading = ref(false)
const savingId = ref(null)
const regenerating = ref(false)
const message = ref(null)
// Per-row validation errors, keyed by exam subject id.
const rowErrors = ref({})

const toRow = (subject) => ({
  ...subject,
  exam_date: subject.exam_date || '',
  start_time: subject.start_time || '',
  end_time: subject.end_time || '',
  written_full: subject.written_full ?? '',
  written_pass: subject.written_pass ?? '',
  mcq_full: subject.mcq_full ?? '',
  mcq_pass: subject.mcq_pass ?? '',
  practical_full: subject.practical_full ?? '',
  practical_pass: subject.practical_pass ?? '',
})

// A part's errors can land on its full or pass field; the time errors sit by the Save button.
const fieldErrors = (row, key) => {
  const errors = rowErrors.value[row.id] ?? {}
  const fields = key === 'time' ? ['exam_date', 'start_time', 'end_time'] : [`${key}_full`, `${key}_pass`]

  return fields.flatMap((field) => errors[field] ?? [])
}

const loadSubjects = async () => {
  message.value = null
  rowErrors.value = {}
  const { data } = await api.get(`/exams/${exam.value.id}/subjects`, { params: { class_id: classId.value } })
  rows.value = data.data.map(toRow)
}

const load = async () => {
  loading.value = true
  try {
    const { data } = await api.get(`/exams/${route.params.id}`)
    exam.value = data.data
    classId.value = exam.value.classes?.[0]?.id ?? ''
    if (classId.value) await loadSubjects()
  } catch (error) {
    console.error('Failed to load the exam schedule:', error)
    alert(error.response?.data?.message || 'Failed to load the exam schedule')
  } finally {
    loading.value = false
  }
}

const number = (value) => (value === '' || value === null ? null : Number(value))

const saveRow = async (row) => {
  message.value = null
  rowErrors.value = { ...rowErrors.value, [row.id]: {} }
  savingId.value = row.id

  try {
    const { data } = await api.put(`/exams/${exam.value.id}/subjects/${row.id}`, {
      exam_date: row.exam_date || null,
      start_time: row.start_time || null,
      end_time: row.end_time || null,
      ...Object.fromEntries(MARK_PARTS.flatMap((p) => [`${p.key}_full`, `${p.key}_pass`]).map((field) => [field, number(row[field])])),
    })
    rows.value = rows.value.map((r) => (r.id === row.id ? toRow(data.data) : r))
    message.value = { ok: true, text: `${row.subject?.name}: saved.` }
  } catch (error) {
    if (error.response?.status === 422) {
      rowErrors.value = { ...rowErrors.value, [row.id]: error.response.data.errors ?? {} }
      message.value = { ok: false, text: `${row.subject?.name}: ${error.response.data.message}` }
    } else {
      message.value = { ok: false, text: error.response?.data?.message || 'Failed to save the subject' }
    }
  } finally {
    savingId.value = null
  }
}

const regenerate = async () => {
  if (!confirm('Replace this class\'s subjects and part marks with its current curriculum? Dates and times are kept for subjects that stay.')) return

  regenerating.value = true
  message.value = null
  try {
    const { data } = await api.post(`/exams/${exam.value.id}/classes/${classId.value}/regenerate`)
    await loadSubjects()
    message.value = { ok: true, text: data.message || 'Schedule regenerated.' }
  } catch (error) {
    // 409: marks already exist for the class. 422: the curriculum is empty or has a subject with no marks part.
    message.value = { ok: false, text: error.response?.data?.message || 'Failed to regenerate the schedule' }
  } finally {
    regenerating.value = false
  }
}

onMounted(load)
</script>
