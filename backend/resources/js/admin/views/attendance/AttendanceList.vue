<template>
  <div class="attendance-report space-y-6">
    <div class="no-print flex flex-wrap justify-between items-center gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Attendance</h1>
        <p class="text-sm text-gray-500">Monthly grid for a section: P present, A absent, L late, Lv leave.</p>
      </div>
      <div class="flex gap-2">
        <router-link
          :to="{ path: '/attendance/mark', query: sectionId ? { section_id: sectionId } : {} }"
          class="btn btn-primary"
          v-if="canMark"
        >
          Mark attendance
        </router-link>
        <button type="button" class="btn btn-secondary" :disabled="!report" @click="print">🖨️ Print</button>
      </div>
    </div>

    <div v-if="notice" class="no-print rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="status">{{ notice }}</div>

    <div class="no-print card grid grid-cols-1 gap-4 md:grid-cols-2">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="report-section">Section</label>
        <select id="report-section" v-model="sectionId" class="input" :disabled="loadingSections" @change="loadReport">
          <option value="" disabled>
            {{ sections.length === 0 && !loadingSections ? (teacherOnly ? 'You are not a class teacher' : 'No sections') : 'Select a section' }}
          </option>
          <option v-for="section in sections" :key="section.id" :value="section.id">{{ sectionLabel(section) }}</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="report-month">Month</label>
        <input id="report-month" v-model="month" type="month" class="input" @change="loadReport" />
        <p v-if="errors.month" class="text-sm text-red-600 mt-1">{{ errors.month[0] }}</p>
      </div>
    </div>

    <div v-if="loading" class="card"><p class="text-gray-500 text-center py-8">Loading report...</p></div>

    <div v-else-if="report" class="card">
      <h2 class="report-title">
        {{ selectedLabel }} – {{ monthTitle }}
      </h2>

      <p v-if="report.students.length === 0" class="text-center text-gray-500 py-8">No students to show for this section and month.</p>
      <p v-else-if="report.school_days.length === 0" class="text-center text-gray-500 py-8">
        There are no school days in this month yet.
      </p>

      <div v-else class="overflow-x-auto">
        <table class="attendance-grid">
          <thead>
            <tr>
              <th rowspan="2" class="left">Roll</th>
              <th rowspan="2" class="left">Student</th>
              <th v-for="day in report.school_days" :key="day">{{ dayOfMonth(day) }}</th>
              <th rowspan="2" title="Present">P</th>
              <th rowspan="2" title="Absent">A</th>
              <th rowspan="2" title="Late">L</th>
              <th rowspan="2" title="Leave">Lv</th>
              <th rowspan="2">%</th>
            </tr>
            <tr>
              <th v-for="day in report.school_days" :key="day" class="weekday">{{ weekdayShort(day) }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in report.students" :key="row.student.id">
              <td class="left">{{ row.student.roll_number ?? '-' }}</td>
              <td class="left name">{{ row.student.name_en || row.student.name_bn }}</td>
              <td v-for="day in report.school_days" :key="day">
                <span v-if="row.days[day]" :class="['cell', row.days[day]]" :title="ATTENDANCE_STATUS[row.days[day]].label">
                  {{ ATTENDANCE_STATUS[row.days[day]].short }}
                </span>
              </td>
              <td>{{ row.totals.present }}</td>
              <td>{{ row.totals.absent }}</td>
              <td>{{ row.totals.late }}</td>
              <td>{{ row.totals.leave }}</td>
              <td class="strong">{{ row.percentage }}</td>
            </tr>
          </tbody>
        </table>
        <p class="mt-3 text-xs text-gray-500">
          The percentage is (present + late) of the {{ report.school_days.length }} school days so far this month.
          A student who left is measured over the days recorded.
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { ATTENDANCE_STATUS } from '@/constants/attendance'
import { useAttendanceSections } from '@/composables/useAttendanceSections'
import { currentMonthInDhaka, dayOfMonth, weekdayShort } from '@/utils/dhakaDate'

const route = useRoute()
const authStore = useAuthStore()
const { sections, loading: loadingSections, teacherOnly, load: loadSections, sectionLabel } = useAttendanceSections()

const canMark = computed(() => authStore.hasPermission('mark-attendance'))
const sectionId = ref('')
const month = ref(/^\d{4}-\d{2}$/.test(String(route.query.month)) ? String(route.query.month) : currentMonthInDhaka())
const report = ref(null)
const loading = ref(false)
const notice = ref('')
const errors = ref({})

const selectedLabel = computed(() => {
  const section = sections.value.find((s) => s.id === sectionId.value)
  return section ? sectionLabel(section) : ''
})

const monthTitle = computed(() => {
  if (!report.value) return ''
  const [year, number] = report.value.month.split('-').map(Number)
  return new Intl.DateTimeFormat('en-GB', { timeZone: 'UTC', month: 'long', year: 'numeric' }).format(new Date(Date.UTC(year, number - 1, 1)))
})

const loadReport = async () => {
  if (!sectionId.value || !month.value) return

  errors.value = {}
  notice.value = ''
  report.value = null
  loading.value = true

  try {
    const { data } = await api.get('/attendance/report', { params: { section_id: sectionId.value, month: month.value } })
    report.value = data.data
  } catch (error) {
    if (error.response?.status === 422) errors.value = error.response.data.errors ?? {}
    notice.value = error.response?.status === 403
      ? "Only the section's class teacher or an admin can see this report."
      : error.response?.data?.message || 'Failed to load the report'
  } finally {
    loading.value = false
  }
}

const print = () => window.print()

onMounted(async () => {
  // Marks the page so the print CSS can hide the admin chrome (sidebar and header).
  document.body.classList.add('attendance-report-page')

  await loadSections()

  const requested = Number(route.query.section_id)
  if (requested && sections.value.some((section) => section.id === requested)) {
    sectionId.value = requested
  } else if (sections.value.length === 1) {
    sectionId.value = sections.value[0].id
  }

  await loadReport()
})

onBeforeUnmount(() => document.body.classList.remove('attendance-report-page'))
</script>

<style>
.attendance-report .report-title { font-size: 16px; font-weight: 700; margin: 0 0 12px; }
.attendance-grid { border-collapse: collapse; font-size: 12px; width: 100%; }
.attendance-grid th, .attendance-grid td { border: 1px solid #d1d5db; padding: 3px 5px; text-align: center; white-space: nowrap; }
.attendance-grid th { background: #f3f4f6; font-weight: 600; }
.attendance-grid .left { text-align: left; }
.attendance-grid .weekday { font-size: 10px; font-weight: 400; color: #6b7280; }
.attendance-grid .strong { font-weight: 700; }
.attendance-grid .cell { display: inline-block; min-width: 22px; border-radius: 3px; font-weight: 600; }
.attendance-grid .cell.present { color: #166534; background: #dcfce7; }
.attendance-grid .cell.absent { color: #991b1b; background: #fee2e2; }
.attendance-grid .cell.late { color: #854d0e; background: #fef9c3; }
.attendance-grid .cell.leave { color: #1e40af; background: #dbeafe; }

@media print {
  /* The admin chrome: sidebar, top bar and the page padding around the router view. */
  body.attendance-report-page aside,
  body.attendance-report-page header,
  body.attendance-report-page .no-print { display: none !important; }
  body.attendance-report-page .ml-64 { margin-left: 0 !important; }
  body.attendance-report-page main { padding: 0 !important; }
  body.attendance-report-page .min-h-screen { background: #fff !important; }
  body.attendance-report-page .card { box-shadow: none !important; padding: 0 !important; }
  .attendance-grid { font-size: 10px; }
  .attendance-grid .cell { background: none !important; }
}
@page { size: A4 landscape; margin: 10mm; }
</style>
