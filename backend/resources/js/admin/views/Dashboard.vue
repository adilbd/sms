<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
      <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
      <p v-if="d" class="text-sm text-gray-500">
        {{ d.today }}<span v-if="d.academic_year"> &middot; Academic year {{ d.academic_year.name || d.academic_year.year }}</span>
      </p>
    </div>

    <div v-if="loading" class="card text-gray-500" role="status">Loading...</div>
    <div v-else-if="error" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ error }}</div>

    <template v-else-if="d">
      <div v-if="!d.academic_year" class="card text-gray-600">
        There is no active academic year, so there is nothing to show yet. An administrator can activate one under Academic Years.
      </div>

      <template v-else>
        <!-- Quick actions follow the role's permissions -->
        <section v-if="actions.length" aria-label="Quick actions" class="flex flex-wrap gap-3">
          <router-link v-for="action in actions" :key="action.to" :to="action.to" class="btn btn-primary">{{ action.label }}</router-link>
        </section>

        <!-- Admin: school-wide counts -->
        <section v-if="d.counts" class="space-y-6">
          <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div v-for="stat in stats" :key="stat.label" class="card">
              <p class="text-sm font-medium text-gray-500">{{ stat.label }}</p>
              <p class="mt-1 text-3xl font-bold text-gray-900">{{ stat.value }}</p>
              <p v-if="stat.note" class="mt-1 text-xs text-gray-500">{{ stat.note }}</p>
            </div>
          </div>

          <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="card">
              <h2 class="mb-4 text-lg font-semibold text-gray-900">Students by level</h2>
              <BarList :items="levelBars" label="Students by level" />
            </div>
            <div class="card">
              <h2 class="mb-4 text-lg font-semibold text-gray-900">Students by group (Class 9+)</h2>
              <BarList :items="groupBars" label="Students by group" />
            </div>
            <div class="card">
              <h2 class="mb-4 text-lg font-semibold text-gray-900">Students by shift</h2>
              <p v-if="shiftBars.length === 0" class="text-sm text-gray-500">No shifts yet.</p>
              <BarList v-else :items="shiftBars" label="Students by shift" />
            </div>
          </div>
        </section>

        <!-- Attendance today (admin: every section, teacher: the sections they lead) -->
        <section v-if="d.attendance_today" class="card" aria-labelledby="attendance-heading">
          <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="attendance-heading" class="text-lg font-semibold text-gray-900">
              {{ d.role === 'teacher' ? 'My sections: attendance today' : 'Attendance today' }}
            </h2>
            <p v-if="attendance.percentage !== null" class="text-sm text-gray-600">
              Overall <strong class="text-gray-900">{{ attendance.percentage }}%</strong> present ({{ attendance.sections_marked }} marked,
              {{ attendance.sections_not_marked }} not yet)
            </p>
          </div>

          <p v-if="!attendance.is_school_day" class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-700" role="status">
            No school today: {{ holidayName }}.
          </p>
          <p v-else-if="attendance.sections.length === 0" class="text-sm text-gray-500">
            {{ d.role === 'teacher' ? 'You are not the class teacher of any section this year.' : 'No section has students enrolled yet.' }}
          </p>
          <template v-else>
            <BarList :items="attendanceBars" label="Present percentage by section" />
            <div v-if="attendance.not_marked.length" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
              <p class="font-medium">Not marked yet</p>
              <ul class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                <li v-for="section in attendance.not_marked" :key="section.id">{{ sectionLabel(section) }}</li>
              </ul>
            </div>
            <p v-else class="mt-4 text-sm text-green-700">Every section has been marked.</p>
          </template>
        </section>

        <!-- Exams: admin (latest exam and mark entry) -->
        <section v-if="d.exams" class="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <div class="card">
            <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
              <h2 class="text-lg font-semibold text-gray-900">Latest exam: pass rate by class</h2>
              <span v-if="d.exams.latest" class="badge" :class="EXAM_STATUS_BADGES[d.exams.latest.status]">{{ EXAM_STATUS_LABELS[d.exams.latest.status] }}</span>
            </div>
            <p v-if="!d.exams.latest" class="text-sm text-gray-500">No exam has started yet.</p>
            <template v-else>
              <p class="mb-3 text-sm text-gray-600">{{ d.exams.latest.name }}</p>
              <p v-if="d.exams.classes.length === 0" class="text-sm text-gray-500">No results have been processed for this exam yet.</p>
              <BarList v-else :items="classBars" label="Pass rate by class" />
            </template>
          </div>
          <div class="card">
            <h2 class="mb-4 text-lg font-semibold text-gray-900">Mark entry</h2>
            <p v-if="d.exams.marks_entry.total_sheets === 0" class="text-sm text-gray-500">No exam is open for mark entry.</p>
            <template v-else>
              <p class="mb-3 text-sm text-gray-600">
                <strong class="text-gray-900">{{ d.exams.marks_entry.incomplete_sheets }}</strong> of {{ d.exams.marks_entry.total_sheets }} mark sheets are
                still incomplete.
              </p>
              <BarList :items="markEntryBars" label="Completed mark sheets by exam" />
            </template>
          </div>
        </section>

        <!-- Teacher: mark sheets and pass rate -->
        <section v-if="d.mark_sheets" class="card" aria-labelledby="sheets-heading">
          <h2 id="sheets-heading" class="mb-4 text-lg font-semibold text-gray-900">My mark sheets</h2>
          <p v-if="d.mark_sheets.length === 0" class="text-sm text-gray-500">No exam is open for mark entry in your subjects.</p>
          <BarList v-else :items="sheetBars" label="Marks entered by mark sheet" />
        </section>

        <section v-if="d.pass_rates" class="card" aria-labelledby="rates-heading">
          <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="rates-heading" class="text-lg font-semibold text-gray-900">My sections: latest pass rate</h2>
            <span v-if="d.pass_rates.exam" class="text-sm text-gray-500">{{ d.pass_rates.exam.name }}</span>
          </div>
          <p v-if="d.pass_rates.sections.length === 0" class="text-sm text-gray-500">No results for your sections yet.</p>
          <BarList v-else :items="sectionRateBars" label="Pass rate by section" />
        </section>

        <!-- Fees (admin: d.fees, office: top level) -->
        <section v-if="month" class="space-y-6">
          <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="card">
              <p class="text-sm font-medium text-gray-500">This month's dues</p>
              <p class="mt-1 text-2xl font-bold text-gray-900">{{ taka(month.net_amount) }}</p>
            </div>
            <div class="card">
              <p class="text-sm font-medium text-gray-500">Collected this month</p>
              <p class="mt-1 text-2xl font-bold text-gray-900">{{ taka(month.collected_amount) }}</p>
            </div>
            <div class="card">
              <p class="text-sm font-medium text-gray-500">Outstanding this month</p>
              <p class="mt-1 text-2xl font-bold text-gray-900">{{ taka(month.outstanding_amount) }}</p>
            </div>
            <div v-if="d.fees" class="card">
              <p class="text-sm font-medium text-gray-500">Overdue dues</p>
              <p class="mt-1 text-2xl font-bold text-gray-900">{{ taka(d.fees.overdue.outstanding_amount) }}</p>
              <p class="mt-1 text-xs text-gray-500">{{ d.fees.overdue.count }} dues past their date</p>
            </div>
            <div v-else class="card">
              <p class="text-sm font-medium text-gray-500">Students with dues</p>
              <p class="mt-1 text-2xl font-bold text-gray-900">{{ d.students_with_outstanding_dues }}</p>
            </div>
          </div>

          <div class="card">
            <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
              <h2 class="text-lg font-semibold text-gray-900">Collected today</h2>
              <p class="text-sm text-gray-600">
                <strong class="text-gray-900">{{ taka(collection.amount) }}</strong> in {{ collection.count }} {{ collection.count === 1 ? 'receipt' : 'receipts' }}
              </p>
            </div>
            <p v-if="collection.count === 0" class="text-sm text-gray-500">Nothing has been collected today.</p>
            <BarList v-else :items="methodBars" label="Collected today by method" />
          </div>
        </section>

        <!-- Recent -->
        <section class="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <div v-if="payments" class="card overflow-x-auto" :class="d.recent ? '' : 'lg:col-span-2'">
            <h2 class="mb-4 text-lg font-semibold text-gray-900">{{ d.recent ? 'Recent payments' : 'Recent receipts' }}</h2>
            <p v-if="payments.length === 0" class="text-sm text-gray-500">No payments yet.</p>
            <table v-else class="table">
              <thead>
                <tr><th>Receipt</th><th>Student</th><th>Method</th><th class="text-right">Amount</th><th>Time</th></tr>
              </thead>
              <tbody>
                <tr v-for="payment in payments" :key="payment.id">
                  <td><router-link :to="`/fees/receipts/${payment.id}`" class="text-primary-600 hover:underline">{{ payment.receipt_no }}</router-link></td>
                  <td>{{ studentName(payment.student) }}</td>
                  <td>{{ METHOD_LABELS[payment.method] || payment.method }}</td>
                  <td class="text-right">{{ taka(payment.amount) }}</td>
                  <td class="whitespace-nowrap">{{ dhakaDateTime(payment.paid_at) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <template v-if="d.recent">
            <div class="card">
              <h2 class="mb-4 text-lg font-semibold text-gray-900">Recently admitted</h2>
              <p v-if="d.recent.students.length === 0" class="text-sm text-gray-500">No students yet.</p>
              <ul v-else class="divide-y divide-gray-100">
                <li v-for="student in d.recent.students" :key="student.id" class="flex items-center justify-between gap-3 py-2 text-sm">
                  <span>
                    <router-link :to="`/students/${student.id}`" class="font-medium text-primary-600 hover:underline">{{ studentName(student) }}</router-link>
                    <span class="ml-1 text-gray-500">{{ student.student_id }}</span>
                  </span>
                  <span class="text-right text-gray-500">
                    <span v-if="student.class">{{ student.class.name }}<span v-if="student.section"> - {{ student.section.name }}</span> &middot; </span>{{ student.admission_date }}
                  </span>
                </li>
              </ul>
            </div>
            <div class="card">
              <h2 class="mb-4 text-lg font-semibold text-gray-900">Recently processed exams</h2>
              <p v-if="d.recent.exams.length === 0" class="text-sm text-gray-500">No exam has been processed yet.</p>
              <ul v-else class="divide-y divide-gray-100">
                <li v-for="exam in d.recent.exams" :key="exam.id" class="flex items-center justify-between gap-3 py-2 text-sm">
                  <span class="font-medium text-gray-900">{{ exam.name }}</span>
                  <span class="badge" :class="EXAM_STATUS_BADGES[exam.status]">{{ EXAM_STATUS_LABELS[exam.status] }}</span>
                </li>
              </ul>
            </div>
          </template>
        </section>
      </template>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import BarList from '@/components/BarList.vue'
import { useAuthStore } from '@/stores/auth'
import { canAccess } from '@/utils/access'
import { taka } from '@/utils/money'
import { dhakaDateTime, studentName as nameOf } from '@/utils/fees'
import { EXAM_STATUS_BADGES, EXAM_STATUS_LABELS } from '@/constants/exams'
import { METHOD_LABELS } from '@/constants/fees'

const auth = useAuthStore()
const d = ref(null)
const loading = ref(true)
const error = ref('')

const LEVEL_LABELS = {
  primary: 'Primary (Class 1-5)',
  junior_secondary: 'Junior Secondary (6-8)',
  secondary: 'Secondary (9-10)',
  higher_secondary: 'Higher Secondary (11-12)',
}
const GROUP_LABELS = { science: 'Science', business_studies: 'Business Studies', humanities: 'Humanities' }

const ACTIONS = {
  admin: [
    { label: 'Add student', to: '/students/create', access: { permission: 'create-students' } },
    { label: 'Mark attendance', to: '/attendance/mark', access: { permission: 'mark-attendance' } },
    { label: 'Manage exams', to: '/exams', access: { permission: 'view-exams' } },
    { label: 'Collect fee', to: '/fees/collect', access: { permission: 'collect-fees' } },
  ],
  teacher: [
    { label: 'Mark attendance', to: '/attendance/mark', access: { permission: 'mark-attendance' } },
    { label: 'Enter marks', to: '/exams', access: { permission: 'enter-results' } },
  ],
  office: [
    { label: 'Collect fee', to: '/fees/collect', access: { permission: 'collect-fees' } },
    { label: 'Fee dues', to: '/fees/dues', access: { permission: 'view-fees' } },
    { label: 'Add student', to: '/students/create', access: { permission: 'create-students' } },
  ],
}

const actions = computed(() => (ACTIONS[d.value?.role] || []).filter((action) => canAccess(auth, action.access)))

// A bar's share of the largest value, so the longest bar fills the track.
const share = (value, max) => (max > 0 ? (value / max) * 100 : 0)

const stats = computed(() => {
  const { students, staff, sections } = d.value.counts
  return [
    { label: 'Students', value: students.total },
    { label: 'Teachers', value: staff.teachers, note: `${staff.active} staff in all` },
    { label: 'Staff with a login', value: staff.with_login, note: `of ${staff.active} active` },
    { label: 'Active sections', value: sections },
  ]
})

const levelBars = computed(() => {
  const entries = Object.entries(d.value.counts.students.by_level)
  const max = Math.max(...entries.map(([, n]) => n), 0)
  return entries.map(([key, n]) => ({ key, label: LEVEL_LABELS[key] || key, value: share(n, max), text: String(n) }))
})

const groupBars = computed(() => {
  const entries = Object.entries(d.value.counts.students.by_group)
  const max = Math.max(...entries.map(([, n]) => n), 0)
  return entries.map(([key, n]) => ({ key, label: GROUP_LABELS[key] || key, value: share(n, max), text: String(n) }))
})

const shiftBars = computed(() => {
  const rows = d.value.counts.students.by_shift
  const max = Math.max(...rows.map((row) => row.students), 0)
  return rows.map((row) => ({ key: row.shift.id, label: row.shift.name_en || row.shift.name_bn, value: share(row.students, max), text: String(row.students) }))
})

const attendance = computed(() => d.value.attendance_today)

const holidayName = computed(() => {
  const holiday = attendance.value.holiday
  return holiday ? holiday.name_en || holiday.name_bn : 'holiday'
})

// The main class teacher (never a co-teacher) is named after the section when there is one.
const sectionLabel = (section) => {
  const label = [section?.class?.name, section?.name].filter(Boolean).join(' - ')
  const teacher = section?.class_teacher
  return teacher ? `${label} (${teacher.name_en || teacher.name_bn})` : label
}

const attendanceBars = computed(() =>
  attendance.value.sections.map((row) => ({
    key: row.section.id,
    label: sectionLabel(row.section),
    value: row.percentage === null ? null : Number(row.percentage),
    text: row.marked ? `${row.percentage}% (${row.present + row.late} of ${row.present + row.absent + row.late + row.leave})` : 'Not marked',
  })),
)

const classBars = computed(() =>
  d.value.exams.classes.map((row) => ({
    key: row.class.id,
    label: row.class.name,
    value: Number(row.pass_rate),
    text: `${row.pass_rate}% (${row.passed}/${row.students}), top GPA ${row.top_gpa}`,
  })),
)

const markEntryBars = computed(() =>
  d.value.exams.marks_entry.exams.map((row) => ({
    key: row.exam.id,
    label: row.exam.name,
    value: share(row.sheets - row.incomplete, row.sheets),
    text: `${row.sheets - row.incomplete} of ${row.sheets} complete`,
  })),
)

const sheetBars = computed(() =>
  d.value.mark_sheets.map((sheet, index) => ({
    key: `${sheet.exam.id}-${sheet.subject.id}-${sheet.section.id}-${index}`,
    label: `${sheet.subject.name}, ${sectionLabel(sheet.section)}`,
    value: share(sheet.entered, sheet.total),
    text: `${sheet.entered} of ${sheet.total} entered`,
  })),
)

const sectionRateBars = computed(() =>
  d.value.pass_rates.sections.map((row) => ({
    key: row.section.id,
    label: sectionLabel(row.section),
    value: Number(row.pass_rate),
    text: `${row.pass_rate}% (${row.passed}/${row.students})`,
  })),
)

const month = computed(() => d.value?.fees?.month ?? d.value?.month ?? null)
const collection = computed(() => d.value?.fees?.today ?? d.value?.collection_today ?? null)
const payments = computed(() => d.value?.recent?.payments ?? d.value?.recent_payments ?? null)

const methodBars = computed(() => {
  const rows = collection.value.by_method
  const max = Math.max(...rows.map((row) => Number(row.amount)), 0)
  return rows.map((row) => ({
    key: row.method,
    label: METHOD_LABELS[row.method] || row.method,
    value: share(Number(row.amount), max),
    text: `${taka(row.amount)} (${row.count})`,
  }))
})

const studentName = (student) => nameOf(student) || '-'

onMounted(async () => {
  try {
    const { data } = await api.get('/dashboard')
    d.value = data.data
  } catch (e) {
    error.value = e.response?.status === 403
      ? 'The dashboard is for school staff. Use the menu to open your pages.'
      : e.response?.data?.message || 'The dashboard could not be loaded.'
  } finally {
    loading.value = false
  }
})
</script>
