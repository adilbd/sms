<template>
  <div class="space-y-6">
    <div class="flex flex-wrap justify-between items-center gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Mark attendance</h1>
        <p class="text-sm text-gray-500">
          One sheet per section and day. Only the section's class teacher or an admin can save it.
        </p>
      </div>
      <router-link
        :to="{ path: '/attendance', query: sectionId ? { section_id: sectionId, month: date.slice(0, 7) } : {} }"
        class="btn btn-secondary"
      >
        Monthly report
      </router-link>
    </div>

    <div v-if="notice" :class="['rounded-lg border px-4 py-3 text-sm', notice.ok ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700']" role="status">
      {{ notice.text }}
    </div>

    <div class="card grid grid-cols-1 gap-4 md:grid-cols-2">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="attendance-section">Section</label>
        <select id="attendance-section" v-model="sectionId" class="input" :disabled="loadingSections" @change="loadSheet">
          <option value="" disabled>
            {{ sections.length === 0 && !loadingSections ? (teacherOnly ? 'You are not a class teacher' : 'No sections') : 'Select a section' }}
          </option>
          <option v-for="section in sections" :key="section.id" :value="section.id">{{ sectionLabel(section) }}</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="attendance-date">Date</label>
        <input id="attendance-date" v-model="date" type="date" class="input" :max="today" @change="loadSheet" />
        <p v-if="errors.date" class="text-sm text-red-600 mt-1">{{ errors.date[0] }}</p>
      </div>
    </div>

    <div
      v-if="sheet?.is_holiday"
      class="rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-800"
      role="status"
    >
      <template v-if="sheet.holiday.type === 'weekly'">{{ sheet.holiday.name_en }} is a weekly holiday, so no attendance is taken.</template>
      <template v-else>Holiday: {{ sheet.holiday.name_en || sheet.holiday.name_bn }}. No attendance is taken.</template>
    </div>

    <div v-if="loadingSheet" class="card"><p class="text-gray-500 text-center py-8">Loading sheet...</p></div>

    <div v-else-if="sheet" class="card">
      <p v-if="rows.length === 0" class="text-center text-gray-500 py-8">No active students are enrolled in this section.</p>

      <template v-else>
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
          <div class="flex flex-wrap gap-2 text-sm">
            <span v-for="status in ATTENDANCE_STATUSES" :key="status.value" :class="['px-2 py-1 rounded border', status.classes]">
              {{ status.label }}: {{ counts[status.value] }}
            </span>
          </div>
          <button type="button" class="btn btn-secondary" :disabled="sheet.is_holiday" @click="markAllPresent">Mark all present</button>
        </div>

        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Roll</th>
                <th>Student</th>
                <th class="text-center">Status</th>
                <th>Remarks</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in rows" :key="row.student_id" :class="hasErrors(index) ? 'bg-red-50' : ''">
                <td>{{ row.roll_number ?? '-' }}</td>
                <td class="font-medium">
                  {{ row.name_en || row.name_bn }}
                  <span v-if="row.name_en && row.name_bn" class="text-gray-500">({{ row.name_bn }})</span>
                  <div class="text-xs text-gray-500">
                    {{ row.student_code }}
                    <span v-if="row.status === null && !row.touched">· not marked yet</span>
                  </div>
                  <p v-for="text in rowErrors(index, 'student_id')" :key="text" class="text-xs text-red-600">{{ text }}</p>
                </td>
                <td class="text-center">
                  <button
                    type="button"
                    :class="['w-24 px-3 py-2 rounded border font-semibold', ATTENDANCE_STATUS[row.current].classes]"
                    :disabled="sheet.is_holiday"
                    :aria-label="`${row.name_en || row.name_bn}: ${ATTENDANCE_STATUS[row.current].label}. Click to change.`"
                    @click="cycle(row)"
                  >
                    {{ ATTENDANCE_STATUS[row.current].label }}
                  </button>
                  <p v-for="text in rowErrors(index, 'status')" :key="text" class="text-xs text-red-600 mt-1">{{ text }}</p>
                </td>
                <td>
                  <input v-model="row.remarks" type="text" maxlength="255" class="input" placeholder="Optional" :disabled="sheet.is_holiday" />
                  <p v-for="text in rowErrors(index, 'remarks')" :key="text" class="text-xs text-red-600 mt-1">{{ text }}</p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="mt-3 text-xs text-gray-500">
          Click a status to cycle Present, Absent, Late and Leave. Students nobody has marked yet start as Present.
        </p>

        <div class="mt-4 flex justify-end">
          <button type="button" class="btn btn-primary" :disabled="saving || sheet.is_holiday" @click="save">
            {{ saving ? 'Saving...' : 'Save attendance' }}
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { ATTENDANCE_STATUS, ATTENDANCE_STATUSES, nextAttendanceStatus } from '@/constants/attendance'
import { useAttendanceSections } from '@/composables/useAttendanceSections'
import { todayInDhaka } from '@/utils/dhakaDate'

const route = useRoute()
const { sections, loading: loadingSections, teacherOnly, load: loadSections, sectionLabel } = useAttendanceSections()

const today = todayInDhaka()
const sectionId = ref('')
const date = ref(today)
const sheet = ref(null)
const rows = ref([])
const loadingSheet = ref(false)
const saving = ref(false)
const notice = ref(null)
const errors = ref({})

const counts = computed(() => Object.fromEntries(
  ATTENDANCE_STATUSES.map((status) => [status.value, rows.value.filter((row) => row.current === status.value).length])
))

// 422 errors are keyed `entries.{index}.{field}`, the index being the row's position in
// the array that was sent (the same order as `rows`).
const rowErrors = (index, field) => errors.value[`entries.${index}.${field}`] ?? []
const hasErrors = (index) => Object.keys(errors.value).some((key) => key.startsWith(`entries.${index}.`))

// Anyone nobody has marked yet defaults to present; `touched` stops the "not marked yet"
// hint once the teacher has acted on that row.
const applySheet = (data) => {
  sheet.value = data
  rows.value = data.students.map((student) => ({
    ...student,
    current: student.status ?? 'present',
    remarks: student.remarks ?? '',
    touched: false,
  }))
}

const failureMessage = (error, fallback) => {
  if (error.response?.status === 403) return "Only the section's class teacher or an admin can use this attendance sheet."
  return error.response?.data?.message || fallback
}

const loadSheet = async () => {
  if (!sectionId.value || !date.value) return

  errors.value = {}
  notice.value = null
  sheet.value = null
  loadingSheet.value = true

  try {
    const { data } = await api.get('/attendance/sheet', { params: { section_id: sectionId.value, date: date.value } })
    applySheet(data.data)
  } catch (error) {
    if (error.response?.status === 422) errors.value = error.response.data.errors ?? {}
    notice.value = { ok: false, text: failureMessage(error, 'Failed to load the attendance sheet') }
  } finally {
    loadingSheet.value = false
  }
}

const cycle = (row) => {
  row.current = nextAttendanceStatus(row.current)
  row.touched = true
  delete errors.value[`entries.${rows.value.indexOf(row)}.status`]
}

const markAllPresent = () => {
  rows.value.forEach((row) => {
    row.current = 'present'
    row.touched = true
  })
}

const save = async () => {
  errors.value = {}
  notice.value = null
  saving.value = true

  try {
    const { data } = await api.put('/attendance/sheet', {
      section_id: sectionId.value,
      date: date.value,
      entries: rows.value.map((row) => ({
        student_id: row.student_id,
        status: row.current,
        remarks: row.remarks?.trim() ? row.remarks.trim() : null,
      })),
    })
    applySheet(data.data)
    notice.value = { ok: true, text: data.message || 'Attendance saved successfully' }
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors ?? {}
      notice.value = { ok: false, text: error.response.data.errors?.date?.[0] || error.response.data.message || 'Some rows have errors.' }
    } else {
      notice.value = { ok: false, text: failureMessage(error, 'Failed to save the attendance') }
    }
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await loadSections()

  const requested = Number(route.query.section_id)
  if (requested && sections.value.some((section) => section.id === requested)) {
    sectionId.value = requested
  } else if (sections.value.length === 1) {
    sectionId.value = sections.value[0].id
  }

  await loadSheet()
})
</script>
