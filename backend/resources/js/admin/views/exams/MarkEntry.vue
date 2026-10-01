<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">
          Mark entry<span v-if="exam"> – {{ exam.name_en || exam.name_bn }}</span>
        </h1>
        <p class="text-sm text-gray-500">
          Choose a class, section and subject. Only the assigned subject teacher or an admin can enter marks.
        </p>
      </div>
      <router-link to="/exams" class="btn btn-secondary">Back to exams</router-link>
    </div>

    <div v-if="exam && exam.status !== 'marks_entry'" class="rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-800">
      <template v-if="exam.status === 'draft'">Mark entry has not been opened for this exam yet. Open it from the exam list first.</template>
      <template v-else>Marks can no longer be entered for this exam.</template>
    </div>

    <div v-if="notice" :class="['rounded-lg border px-4 py-3 text-sm', notice.ok ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700']">
      {{ notice.text }}
    </div>

    <div class="card grid grid-cols-1 gap-4 md:grid-cols-3">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Class</label>
        <select v-model="classId" class="input" @change="onClassChange">
          <option value="" disabled>Select a class</option>
          <option v-for="klass in exam?.classes || []" :key="klass.id" :value="klass.id">{{ klass.name }}</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
        <select v-model="sectionId" class="input" :disabled="!classId" @change="loadSheet">
          <option value="" disabled>Select a section</option>
          <option v-for="section in sections" :key="section.id" :value="section.id">
            {{ section.name }}<template v-if="section.group"> · {{ GROUP_LABELS[section.group] || section.group }}</template>
          </option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
        <select v-model="examSubjectId" class="input" :disabled="!classId" @change="loadSheet">
          <option value="" disabled>Select a subject</option>
          <option v-for="subject in subjects" :key="subject.id" :value="subject.id">
            {{ subject.subject?.name }}<template v-if="subject.group"> ({{ GROUP_LABELS[subject.group] || subject.group }})</template><template v-if="subject.type === 'optional'"> – 4th</template>
          </option>
        </select>
      </div>
    </div>

    <div v-if="loadingSheet" class="card"><p class="text-gray-500 text-center py-8">Loading sheet...</p></div>

    <div v-else-if="sheet" class="card">
      <p v-if="rows.length === 0" class="text-center text-gray-500 py-8">No students in this section take this subject.</p>

      <template v-else>
        <div class="overflow-x-auto" @keydown="onKeydown">
          <table class="table">
            <thead>
              <tr>
                <th>Roll</th>
                <th>Student</th>
                <th v-for="part in parts" :key="part.key" class="text-center">
                  {{ part.label }}<br /><span class="font-normal text-xs">out of {{ part.full }} (pass {{ part.pass }})</span>
                </th>
                <th class="text-center">Absent</th>
                <th class="text-right">Total<br /><span class="font-normal text-xs">out of {{ fullTotal }}</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, r) in rows" :key="row.student_id" :class="hasErrors(r) ? 'bg-red-50' : ''">
                <td>{{ row.roll_number ?? '-' }}</td>
                <td class="font-medium">
                  {{ row.name_en || row.name_bn }}
                  <span v-if="row.name_en && row.name_bn" class="text-gray-500">({{ row.name_bn }})</span>
                  <div class="text-xs text-gray-500">{{ row.student_code }}</div>
                  <p v-for="text in rowErrors(r, 'student_id')" :key="text" class="text-xs text-red-600">{{ text }}</p>
                </td>
                <td v-for="(part, c) in parts" :key="part.key" class="text-center">
                  <input
                    v-model="row[part.key]"
                    type="text"
                    inputmode="decimal"
                    :data-cell="`${r}-${c}`"
                    :disabled="row.is_absent"
                    :class="['input w-24 text-right', overFull(row, part) ? 'border-red-500' : '']"
                    :aria-label="`${part.label} marks for ${row.name_en || row.name_bn}`"
                    @input="clearRowErrors(r)"
                  />
                  <p v-for="text in rowErrors(r, part.key)" :key="text" class="text-xs text-red-600 mt-1">{{ text }}</p>
                </td>
                <td class="text-center">
                  <input
                    v-model="row.is_absent"
                    type="checkbox"
                    :data-cell="`${r}-${parts.length}`"
                    :aria-label="`Absent: ${row.name_en || row.name_bn}`"
                    @change="onAbsentChange(row, r)"
                  />
                  <p v-for="text in rowErrors(r, 'is_absent')" :key="text" class="text-xs text-red-600 mt-1">{{ text }}</p>
                </td>
                <td class="text-right font-medium">{{ row.is_absent ? 'Absent' : total(row) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="mt-3 text-xs text-gray-500">
          Enter or the arrow keys move between cells. Leave a part empty if it isn't marked yet; clear a whole row to remove its marks.
        </p>

        <div class="mt-4 flex justify-end">
          <button type="button" class="btn btn-primary" :disabled="saving || exam?.status !== 'marks_entry'" @click="save">
            {{ saving ? 'Saving...' : 'Save marks' }}
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { GROUP_LABELS } from '@/constants/academic'
import { MARK_PARTS } from '@/constants/exams'

const route = useRoute()

const exam = ref(null)
const classId = ref('')
const sectionId = ref('')
const examSubjectId = ref('')
const sections = ref([])
const subjects = ref([])
const sheet = ref(null)
const rows = ref([])
const loadingSheet = ref(false)
const saving = ref(false)
const notice = ref(null)
const errors = ref({})

// The parts the chosen subject has, each with its full and pass marks.
const parts = computed(() => {
  const subject = sheet.value?.exam_subject
  if (!subject) return []

  return MARK_PARTS.filter((p) => subject[`${p.key}_full`] !== null).map((p) => ({
    ...p,
    full: subject[`${p.key}_full`],
    pass: subject[`${p.key}_pass`],
  }))
})

const fullTotal = computed(() => parts.value.reduce((sum, p) => sum + p.full, 0))

const parse = (value) => {
  const text = String(value ?? '').trim()
  return text === '' || Number.isNaN(Number(text)) ? null : Number(text)
}

const total = (row) => {
  const sum = parts.value.reduce((acc, p) => acc + (parse(row[p.key]) ?? 0), 0)
  return Math.round(sum * 100) / 100
}

const overFull = (row, part) => {
  const value = parse(row[part.key])
  return value !== null && (value < 0 || value > part.full)
}

// 422 errors are keyed `marks.{index}.{field}`, the index being the row's position in the
// array that was sent (the same order as `rows`).
const rowErrors = (index, field) => errors.value[`marks.${index}.${field}`] ?? []
const hasErrors = (index) => Object.keys(errors.value).some((key) => key.startsWith(`marks.${index}.`))
const clearRowErrors = (index) => {
  errors.value = Object.fromEntries(Object.entries(errors.value).filter(([key]) => !key.startsWith(`marks.${index}.`)))
}

const toRow = (student) => ({
  ...student,
  written: student.written ?? '',
  mcq: student.mcq ?? '',
  practical: student.practical ?? '',
})

const onClassChange = async () => {
  sectionId.value = ''
  examSubjectId.value = ''
  sheet.value = null
  rows.value = []
  notice.value = null

  try {
    const [sectionsRes, subjectsRes] = await Promise.all([
      api.get('/sections', { params: { class_id: classId.value, per_page: 100 } }),
      api.get(`/exams/${exam.value.id}/subjects`, { params: { class_id: classId.value } }),
    ])
    sections.value = sectionsRes.data.data
    subjects.value = subjectsRes.data.data
  } catch (error) {
    notice.value = { ok: false, text: error.response?.data?.message || 'Failed to load the sections and subjects' }
  }
}

const applySheet = (data) => {
  sheet.value = data
  rows.value = data.students.map(toRow)
}

const loadSheet = async () => {
  if (!sectionId.value || !examSubjectId.value) return

  errors.value = {}
  notice.value = null
  sheet.value = null
  loadingSheet.value = true

  try {
    const { data } = await api.get(`/exams/${exam.value.id}/marks`, {
      params: { section_id: sectionId.value, exam_subject_id: examSubjectId.value },
    })
    applySheet(data.data)
  } catch (error) {
    notice.value = { ok: false, text: failureMessage(error, 'Failed to load the mark sheet') }
  } finally {
    loadingSheet.value = false
  }
}

const failureMessage = (error, fallback) => {
  if (error.response?.status === 403) return 'Only the assigned subject teacher or an admin can enter marks.'
  return error.response?.data?.message || fallback
}

const onAbsentChange = (row, index) => {
  if (row.is_absent) {
    row.written = ''
    row.mcq = ''
    row.practical = ''
  }
  clearRowErrors(index)
}

const save = async () => {
  errors.value = {}
  notice.value = null
  saving.value = true

  try {
    const { data } = await api.put(`/exams/${exam.value.id}/marks`, {
      section_id: sectionId.value,
      exam_subject_id: examSubjectId.value,
      marks: rows.value.map((row) => ({
        student_id: row.student_id,
        ...Object.fromEntries(parts.value.map((p) => [p.key, row.is_absent ? null : parse(row[p.key])])),
        is_absent: !!row.is_absent,
      })),
    })
    applySheet(data.data)
    notice.value = { ok: true, text: data.message || 'Marks saved successfully' }
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors ?? {}
      notice.value = { ok: false, text: error.response.data.message || 'Some rows have errors.' }
    } else {
      // 403: not the assigned teacher. 409: mark entry isn't open (any more) for this exam.
      notice.value = { ok: false, text: failureMessage(error, 'Failed to save the marks') }
    }
  } finally {
    saving.value = false
  }
}

// Spreadsheet-style movement: Enter and Down go to the next row, Up to the previous,
// Left/Right to the neighbouring column once the caret is at the edge of the cell.
const focusCell = (r, c) => {
  const cell = document.querySelector(`[data-cell="${r}-${c}"]:not([disabled])`)
  if (cell) {
    cell.focus()
    if (typeof cell.select === 'function' && cell.type === 'text') cell.select()
    return true
  }
  return false
}

const onKeydown = (event) => {
  const cell = event.target?.dataset?.cell
  if (!cell) return

  const [r, c] = cell.split('-').map(Number)
  const input = event.target
  const atStart = input.type !== 'text' || (input.selectionStart === 0 && input.selectionEnd === 0)
  const atEnd = input.type !== 'text' || (input.selectionStart === input.value.length)

  const moves = {
    Enter: [r + 1, c],
    ArrowDown: [r + 1, c],
    ArrowUp: [r - 1, c],
    ArrowLeft: atStart ? [r, c - 1] : null,
    ArrowRight: atEnd ? [r, c + 1] : null,
  }
  const target = moves[event.key]

  if (target) {
    event.preventDefault()
    focusCell(target[0], target[1])
  }
}

onMounted(async () => {
  try {
    const { data } = await api.get(`/exams/${route.params.id}`)
    exam.value = data.data
    if (exam.value.classes?.length === 1) {
      classId.value = exam.value.classes[0].id
      await onClassChange()
    }
  } catch (error) {
    console.error('Failed to load the exam:', error)
    notice.value = { ok: false, text: error.response?.data?.message || 'Failed to load the exam' }
  }
  await nextTick()
})
</script>
