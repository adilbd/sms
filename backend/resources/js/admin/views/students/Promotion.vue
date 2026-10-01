<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">Promote a section</h1>
      <router-link to="/students" class="btn btn-secondary">Back to students</router-link>
    </div>

    <!-- Step 1: choose the section and years -->
    <div v-if="step === 1" class="card space-y-4">
      <h2 class="font-semibold">1. Choose the section</h2>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <label class="block">
          <span class="text-sm text-gray-600">From academic year</span>
          <select v-model="form.from_academic_year_id" class="input" @change="onFromYearChange">
            <option v-for="year in years" :key="year.id" :value="year.id">{{ year.name }}{{ year.is_active ? ' (current)' : '' }}</option>
          </select>
        </label>
        <label class="block">
          <span class="text-sm text-gray-600">Section</span>
          <select v-model="form.section_id" class="input">
            <option value="">Select a section</option>
            <option v-for="section in sections" :key="section.id" :value="section.id">{{ sectionLabel(section) }}</option>
          </select>
        </label>
        <label class="block">
          <span class="text-sm text-gray-600">To academic year</span>
          <select v-model="form.to_academic_year_id" class="input">
            <option v-for="year in years" :key="year.id" :value="year.id">{{ year.name }}</option>
          </select>
        </label>
      </div>
      <p v-if="loadError" class="text-red-600 text-sm">{{ loadError }}</p>
      <button class="btn btn-primary" :disabled="!form.section_id || !form.to_academic_year_id || loading" @click="loadPreview">
        {{ loading ? 'Loading...' : 'Load students' }}
      </button>
    </div>

    <!-- Step 2: review one row per student -->
    <div v-if="step === 2" class="space-y-4">
      <div class="card space-y-3">
        <h2 class="font-semibold">
          2. Review: {{ preview.class.name }} / {{ preview.section.name }} ({{ preview.section.shift?.name_en }}) -
          {{ fromYearName }} to {{ toYearName }}
        </h2>

        <label v-if="!isFinal" class="block max-w-md">
          <span class="text-sm text-gray-600">Default target section ({{ preview.next_class?.name || 'next class' }})</span>
          <select v-model="form.default_target_section_id" class="input">
            <option value="">Select a section</option>
            <option v-for="section in promoteTargets" :key="section.id" :value="section.id">{{ sectionLabel(section) }}</option>
          </select>
        </label>
        <p v-if="preview.needs_group_choice" class="text-sm text-amber-700">
          Students moving into {{ preview.next_class?.name }} need a group unless the target section has one.
        </p>

        <div class="flex gap-2">
          <button class="btn btn-secondary" @click="promoteAll">{{ isFinal ? 'Graduate all' : 'Promote all' }}</button>
          <button v-if="!isFinal" class="btn btn-secondary" @click="retainFailed">Retain failed</button>
        </div>

        <div v-if="hasErrors" class="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-700 space-y-1">
          <p v-for="(message, key) in sharedErrors" :key="key">{{ message }}</p>
          <p v-if="rowErrorCount">{{ rowErrorCount }} row(s) need attention (marked below).</p>
        </div>
      </div>

      <div class="card overflow-x-auto">
        <p v-if="rows.length === 0" class="text-gray-500 py-4 text-center">No active students in this section.</p>
        <table v-else class="table">
          <thead>
            <tr>
              <th>Roll</th>
              <th>Name</th>
              <th>Annual result</th>
              <th>Action</th>
              <th>Target section</th>
              <th>Group</th>
              <th>4th subject</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, i) in rows" :key="row.enrolment_id" :class="{ 'opacity-50': row.already_enrolled_in_target }">
              <td>{{ row.roll_number ?? '-' }}</td>
              <td>
                <p class="font-medium">{{ row.student.name_en || row.student.name_bn }}</p>
                <p class="text-xs text-gray-500">{{ row.student.student_id }}</p>
                <p v-if="row.already_enrolled_in_target" class="text-xs text-amber-700">Already enrolled in {{ toYearName }}</p>
              </td>
              <td>
                <span v-if="row.exam_result" :class="['badge', row.exam_result.is_pass ? 'badge-success' : 'badge-danger']">
                  {{ row.exam_result.gpa }} / {{ row.exam_result.grade }}
                </span>
                <span v-else class="text-xs text-gray-500">No annual result</span>
              </td>
              <td>
                <select v-model="row.action" class="input" :disabled="row.already_enrolled_in_target" @change="onRowChange(row)">
                  <option value="promote">{{ isFinal ? 'Graduate' : 'Promote' }}</option>
                  <option value="retain">Retain</option>
                  <option value="leave">Leave school</option>
                  <option value="skip">Skip (leave untouched)</option>
                </select>
                <p class="text-xs text-red-600">{{ rowError(i, 'student_id') }}</p>
              </td>
              <td>
                <template v-if="enrols(row)">
                  <select v-model="row.target_section_id" class="input" :disabled="row.already_enrolled_in_target" @change="onRowChange(row)">
                    <option value="">{{ row.action === 'retain' ? 'Same section' : 'Default' }}</option>
                    <option v-for="section in targetsFor(row)" :key="section.id" :value="section.id">{{ sectionLabel(section) }}</option>
                  </select>
                  <p class="text-xs text-red-600">{{ rowError(i, 'target_section_id') }}</p>
                </template>
                <span v-else class="text-gray-400">-</span>
              </td>
              <td>
                <template v-if="targetHasGroups(row)">
                  <select v-model="row.group" class="input" :disabled="row.already_enrolled_in_target" @change="onGroupChange(row)">
                    <option value="">Choose group</option>
                    <option v-for="group in GROUPS" :key="group.value" :value="group.value">{{ group.label }}</option>
                  </select>
                  <p class="text-xs text-red-600">{{ rowError(i, 'group') }}</p>
                </template>
                <span v-else class="text-gray-400">-</span>
              </td>
              <td>
                <template v-if="targetHasGroups(row) && row.group">
                  <select v-model="row.optional_subject_id" class="input" :disabled="row.already_enrolled_in_target">
                    <option value="">None</option>
                    <option v-for="subject in optionsFor(row)" :key="subject.subject_id" :value="subject.subject_id">
                      {{ subject.subject?.name }}
                    </option>
                  </select>
                  <p class="text-xs text-red-600">{{ rowError(i, 'optional_subject_id') }}</p>
                </template>
                <span v-else class="text-gray-400">-</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="flex gap-2">
        <button class="btn btn-secondary" @click="step = 1">Back</button>
        <button class="btn btn-primary" :disabled="rows.length === 0" @click="step = 3">Review summary</button>
      </div>
    </div>

    <!-- Step 3: summary and confirm -->
    <div v-if="step === 3" class="card space-y-4">
      <h2 class="font-semibold">3. Confirm</h2>
      <ul class="text-sm space-y-1">
        <li v-if="!isFinal">Promote: <strong>{{ counts.promote }}</strong></li>
        <li v-if="isFinal">Graduate: <strong>{{ counts.promote }}</strong></li>
        <li>Retain: <strong>{{ counts.retain }}</strong></li>
        <li>Leave school: <strong>{{ counts.leave }}</strong></li>
        <li>Skip (untouched): <strong>{{ counts.skip }}</strong></li>
      </ul>
      <div v-if="targetCounts.length" class="text-sm">
        <p class="font-medium">New enrolments in {{ toYearName }}</p>
        <ul class="list-disc ml-5">
          <li v-for="target in targetCounts" :key="target.id">{{ sectionLabel(target.section) }}: {{ target.count }}</li>
        </ul>
      </div>
      <p class="text-sm text-gray-600">Roll numbers are left blank in the new year. This cannot be undone.</p>
      <div class="flex gap-2">
        <button class="btn btn-secondary" :disabled="applying" @click="step = 2">Back</button>
        <button class="btn btn-primary" :disabled="applying" @click="apply">{{ applying ? 'Applying...' : 'Apply promotion' }}</button>
      </div>
    </div>

    <!-- Result -->
    <div v-if="step === 4" class="card space-y-4">
      <h2 class="font-semibold">Promotion applied</h2>
      <ul class="text-sm space-y-1">
        <li>Promoted: <strong>{{ result.summary.promoted }}</strong></li>
        <li>Retained: <strong>{{ result.summary.retained }}</strong></li>
        <li>Left: <strong>{{ result.summary.left }}</strong></li>
        <li>Graduated: <strong>{{ result.summary.graduated }}</strong></li>
        <li>Skipped: <strong>{{ result.summary.skipped }}</strong></li>
      </ul>
      <ul v-if="result.target_sections.length" class="text-sm list-disc ml-5">
        <li v-for="target in result.target_sections" :key="target.id">{{ resultSectionLabel(target) }}: {{ target.enrolled_after }} students enrolled</li>
      </ul>
      <div class="flex gap-2">
        <button class="btn btn-secondary" @click="reset">Promote another section</button>
        <router-link to="/students" class="btn btn-primary">Back to students</router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { GROUPS } from '@/constants/academic'

const step = ref(1)
const years = ref([])
const allSections = ref([])
const loading = ref(false)
const applying = ref(false)
const loadError = ref('')
const preview = ref(null)
const rows = ref([])
const errors = ref({})
const exceptionIndex = ref({})
const result = ref(null)
const optionCache = reactive({})

const form = reactive({
  from_academic_year_id: '',
  to_academic_year_id: '',
  section_id: '',
  default_target_section_id: '',
})

const sections = computed(() => allSections.value.filter((s) => s.is_active))
const sectionLabel = (section) =>
  `${section.class?.name ?? ''} - ${section.name}${section.shift ? ` (${section.shift.name_en})` : ''}`
// "Class 7 – Section A (Morning)", from the names the apply response carries.
const resultSectionLabel = (target) =>
  `${target.class_name ? `${target.class_name} – ` : ''}${target.name}${target.shift_name ? ` (${target.shift_name})` : ''}`
const sectionById = (id) => allSections.value.find((s) => s.id === Number(id))

const yearName = (id) => years.value.find((y) => y.id === Number(id))?.name ?? ''
const fromYearName = computed(() => yearName(form.from_academic_year_id))
const toYearName = computed(() => yearName(form.to_academic_year_id))

const isFinal = computed(() => preview.value?.class.number === 12)
const sourceNumber = computed(() => preview.value?.class.number)
const promoteTargets = computed(() =>
  sections.value.filter((s) => s.class_id === preview.value?.next_class?.id)
)
const retainTargets = computed(() => sections.value.filter((s) => s.class_id === preview.value?.class.id))

const enrols = (row) => !(row.action === 'leave' || row.action === 'skip' || (isFinal.value && row.action === 'promote'))
const targetsFor = (row) => (row.action === 'retain' ? retainTargets.value : promoteTargets.value)

const effectiveTarget = (row) => {
  if (!enrols(row)) return null
  if (row.target_section_id) return sectionById(row.target_section_id)
  return row.action === 'retain' ? sectionById(form.section_id) : sectionById(form.default_target_section_id)
}
const targetClassNumber = (row) => {
  if (!enrols(row)) return null
  const section = effectiveTarget(row)
  if (section?.class) return section.class.number
  return row.action === 'retain' ? sourceNumber.value : sourceNumber.value + 1
}
const targetHasGroups = (row) => (targetClassNumber(row) ?? 0) >= 9
// A student entering Class 9 or 11 has no group yet; otherwise it carries over.
const entersGroups = (row) => row.action === 'promote' && [8, 10].includes(sourceNumber.value)

const optionsFor = (row) => {
  const section = effectiveTarget(row)
  const classId = section?.class_id ?? (row.action === 'retain' ? preview.value.class.id : preview.value.next_class?.id)
  return optionCache[`${classId}|${row.group}`] ?? []
}

const loadOptions = async (row) => {
  const section = effectiveTarget(row)
  const classId = section?.class_id ?? (row.action === 'retain' ? preview.value.class.id : preview.value.next_class?.id)
  if (!classId || !row.group || !targetHasGroups(row)) return
  const key = `${classId}|${row.group}`
  if (optionCache[key]) return
  try {
    const { data } = await api.get(`/classes/${classId}/subjects`, { params: { group: row.group } })
    optionCache[key] = data.data.filter((r) => r.type === 'optional')
  } catch (error) {
    console.error('Failed to fetch the 4th-subject choices:', error)
  }
}

const settleRow = (row) => {
  const section = effectiveTarget(row)
  if (!targetHasGroups(row)) {
    row.group = ''
    row.optional_subject_id = ''
  } else if (entersGroups(row)) {
    // A target section with a group fixes it; otherwise the admin chooses.
    if (section?.group) row.group = section.group
  } else if (section?.group && row.group !== section.group) {
    row.group = section.group
  }
  return loadOptions(row)
}
const onRowChange = (row) => {
  if (row.action === 'leave' || row.action === 'skip') row.target_section_id = ''
  // The target class can change (promote vs retain), so the old choice may no longer apply.
  if (!targetsFor(row).some((s) => s.id === Number(row.target_section_id))) row.target_section_id = ''
  settleRow(row)
}
const onGroupChange = (row) => {
  row.optional_subject_id = ''
  loadOptions(row)
}

const buildRow = (item) => {
  const row = reactive({
    enrolment_id: item.enrolment_id,
    student: item.student,
    roll_number: item.roll_number,
    exam_result: item.exam_result,
    already_enrolled_in_target: item.already_enrolled_in_target === true,
    // The server suggests skip for a student already enrolled in the target year.
    action: ['retain', 'skip'].includes(item.suggested_action) ? item.suggested_action : 'promote',
    target_section_id: '',
    // Carried over by default; reset on entering Class 9 or 11.
    group: item.group ?? '',
    optional_subject_id: item.optional_subject_id ?? '',
  })
  return row
}

const settleAll = () => Promise.all(rows.value.map((row) => settleRow(row)))

const promoteAll = () => {
  rows.value.forEach((row) => {
    if (!row.already_enrolled_in_target) row.action = 'promote'
    onRowChange(row)
  })
}
const retainFailed = () => {
  rows.value.forEach((row) => {
    if (!row.already_enrolled_in_target && row.exam_result && row.exam_result.is_pass === false) row.action = 'retain'
    onRowChange(row)
  })
}

const counts = computed(() => {
  const c = { promote: 0, retain: 0, leave: 0, skip: 0 }
  rows.value.forEach((row) => {
    c[row.action]++
  })
  return c
})
const targetCounts = computed(() => {
  const map = new Map()
  rows.value.forEach((row) => {
    const section = row.already_enrolled_in_target ? null : effectiveTarget(row)
    if (section) map.set(section.id, { id: section.id, section, count: (map.get(section.id)?.count ?? 0) + 1 })
  })
  return [...map.values()]
})

const fetchLookups = async () => {
  const [yearRes, sectionRes] = await Promise.all([
    api.get('/academic-years', { params: { per_page: 100 } }),
    api.get('/sections', { params: { per_page: 100 } }),
  ])
  years.value = yearRes.data.data
  allSections.value = sectionRes.data.data
  const active = years.value.find((y) => y.is_active)
  form.from_academic_year_id = active?.id ?? years.value[0]?.id ?? ''
  onFromYearChange()
}

const onFromYearChange = () => {
  const from = years.value.find((y) => y.id === form.from_academic_year_id)
  form.to_academic_year_id = years.value.find((y) => from && y.year === from.year + 1)?.id ?? ''
}

const loadPreview = async () => {
  loading.value = true
  loadError.value = ''
  errors.value = {}
  try {
    const section = sectionById(form.section_id)
    const { data } = await api.get('/promotions/preview', {
      params: {
        from_academic_year_id: form.from_academic_year_id,
        to_academic_year_id: form.to_academic_year_id,
        section_id: form.section_id,
        class_id: section?.class_id,
      },
    })
    preview.value = data.data
    form.default_target_section_id = data.data.suggested_target_section_id ?? ''
    rows.value = data.data.rows.map(buildRow)
    await settleAll()
    step.value = 2
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Failed to load the students.'
  } finally {
    loading.value = false
  }
}

const buildPayload = () => {
  const map = {}
  const exceptions = []
  rows.value.forEach((row) => {
    if (row.already_enrolled_in_target && row.action === 'promote') return
    const needsExplicit = row.action !== 'promote' || row.target_section_id || targetHasGroups(row)
    if (!needsExplicit) return
    const exception = { student_id: row.student.id, action: row.action }
    if (enrols(row)) {
      if (row.target_section_id) exception.target_section_id = Number(row.target_section_id)
      if (targetHasGroups(row)) {
        exception.group = row.group || null
        exception.optional_subject_id = row.optional_subject_id ? Number(row.optional_subject_id) : null
      }
    }
    map[row.enrolment_id] = exceptions.length
    exceptions.push(exception)
  })
  exceptionIndex.value = map
  return {
    from_academic_year_id: form.from_academic_year_id,
    to_academic_year_id: form.to_academic_year_id,
    section_id: Number(form.section_id),
    class_id: preview.value.class.id,
    default_target_section_id: form.default_target_section_id ? Number(form.default_target_section_id) : null,
    exceptions,
  }
}

const apply = async () => {
  applying.value = true
  errors.value = {}
  try {
    const { data } = await api.post('/promotions', buildPayload())
    result.value = data.data
    step.value = 4
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors || {}
      if (!Object.keys(errors.value).length) errors.value = { _: [error.response.data.message] }
    } else {
      errors.value = { _: [error.response?.data?.message || 'Failed to apply the promotion.'] }
    }
    step.value = 2
  } finally {
    applying.value = false
  }
}

const rowError = (rowIndex, field) => {
  const row = rows.value[rowIndex]
  const index = exceptionIndex.value[row.enrolment_id]
  return index === undefined ? '' : (errors.value[`exceptions.${index}.${field}`] || [])[0] || ''
}
const sharedErrors = computed(() =>
  Object.fromEntries(Object.entries(errors.value).filter(([key]) => !key.startsWith('exceptions.')).map(([key, list]) => [key, list[0]]))
)
const rowErrorCount = computed(() => Object.keys(errors.value).filter((key) => key.startsWith('exceptions.')).length)
const hasErrors = computed(() => Object.keys(errors.value).length > 0)

const reset = () => {
  step.value = 1
  rows.value = []
  errors.value = {}
  form.section_id = ''
}

onMounted(fetchLookups)
</script>
