<template>
  <div class="space-y-6">
    <div class="flex flex-wrap justify-between items-center gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Class routine<span v-if="section"> – {{ sectionLabel(section) }}</span></h1>
        <p class="text-sm text-gray-500">
          A weekly grid per section: pick a subject for each period, then one of the teachers assigned to it and a room.
          Saving replaces the whole grid.
        </p>
      </div>
      <div class="flex gap-2">
        <router-link v-if="routine && canEdit" :to="`/shifts/${section.shift_id}/periods`" class="btn btn-secondary">Periods</router-link>
        <router-link v-if="routine && sectionId" :to="{ path: `/routines/sections/${sectionId}/print`, query: { academic_year_id: yearId } }" class="btn btn-secondary">🖨️ Print</router-link>
      </div>
    </div>

    <div class="card grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Academic year</label>
        <select v-model="yearId" class="input" @change="loadRoutine">
          <option v-for="year in years" :key="year.id" :value="year.id">{{ year.name }}<template v-if="year.is_active"> (active)</template></option>
        </select>
      </div>
      <div class="md:col-span-2">
        <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
        <select v-model="sectionId" class="input" @change="loadRoutine">
          <option value="">Choose a section...</option>
          <option v-for="item in sections" :key="item.id" :value="item.id">
            {{ sectionLabel(item) }}<template v-if="item.shift"> ({{ item.shift.name_en }})</template><template v-if="item.group"> · {{ GROUP_LABELS[item.group] || item.group }}</template>
          </option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="card"><p class="text-gray-500 text-center py-8">Loading...</p></div>
    <div v-else-if="loadError" class="card"><p class="text-red-600 text-center py-8">{{ loadError }}</p></div>

    <template v-else-if="routine">
      <div v-if="summary" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <p>{{ summary }}</p>
        <p v-for="message in generalErrors" :key="message" class="mt-1">{{ message }}</p>
      </div>

      <div v-if="periods.length === 0" class="card text-center text-gray-500 py-8">
        This section's shift has no periods yet.
        <router-link v-if="canEdit" :to="`/shifts/${section.shift_id}/periods`" class="text-primary-600">Add the periods</router-link>
      </div>
      <div v-else-if="subjects.length === 0 && canEdit" class="card text-center text-gray-500 py-8">
        This section's class has no subjects in its curriculum.
        <router-link :to="`/classes/${section.class_id}/subjects`" class="text-primary-600">Edit the curriculum</router-link>
      </div>

      <div v-else-if="canEdit" class="card overflow-x-auto">
        <table class="table routine-editor">
          <thead>
            <tr>
              <th>Period</th>
              <th v-for="day in routine.days" :key="day">{{ dayLabel(day) }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="period in periods" :key="period.id">
              <th class="text-left whitespace-nowrap">
                {{ period.name_en }}
                <span class="block text-xs font-normal text-gray-500">{{ period.start_time }} – {{ period.end_time }}</span>
              </th>
              <td v-if="period.is_break" :colspan="routine.days.length" class="text-center text-gray-500 bg-gray-50">{{ period.name_en }}</td>
              <template v-else>
                <td v-for="day in routine.days" :key="day" class="align-top min-w-[11rem]">
                  <select v-model="grid[key(period.id, day)].subject_id" class="input" :aria-label="`${dayLabel(day)} ${period.name_en} subject`" @change="onSubjectChange(period.id, day)">
                    <option value="">-</option>
                    <option v-for="subject in subjects" :key="subject.id" :value="subject.id">{{ subject.name }}</option>
                  </select>
                  <template v-if="grid[key(period.id, day)].subject_id">
                    <select v-model="grid[key(period.id, day)].staff_id" class="input mt-1" :aria-label="`${dayLabel(day)} ${period.name_en} teacher`">
                      <option value="">No teacher</option>
                      <option v-for="teacher in teachersFor(grid[key(period.id, day)].subject_id)" :key="teacher.id" :value="teacher.id">{{ teacher.name_en || teacher.name_bn }}</option>
                    </select>
                    <input v-model="grid[key(period.id, day)].room" type="text" maxlength="50" class="input mt-1" placeholder="Room" :aria-label="`${dayLabel(day)} ${period.name_en} room`" />
                  </template>
                  <p v-for="message in cellErrors(period.id, day)" :key="message" class="text-xs text-red-600 mt-1">{{ message }}</p>
                </td>
              </template>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-else class="card">
        <RoutineGrid :routine="routine" />
      </div>

      <div v-if="canEdit && periods.length" class="flex justify-end">
        <button type="button" class="btn btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving...' : 'Save routine' }}</button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { GROUP_LABELS } from '@/constants/academic'
import { dayLabel, sectionLabel } from '@/utils/routine'
import RoutineGrid from '@/components/RoutineGrid.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const canEdit = computed(() => auth.hasPermission('edit-classes'))

const years = ref([])
const sections = ref([])
const yearId = ref('')
const sectionId = ref(route.query.section_id ? Number(route.query.section_id) : '')

const routine = ref(null)
const subjects = ref([])
const assignments = ref([])
const grid = reactive({})
const loading = ref(false)
const saving = ref(false)
const loadError = ref('')
const errors = ref({})
const summary = ref('')
// The index of each cell in the payload that was sent, to place `slots.N.field` errors.
const sentOrder = ref([])

const section = computed(() => routine.value?.section ?? null)
const periods = computed(() => routine.value?.periods ?? [])

const key = (periodId, day) => `${periodId}|${day}`

const generalErrors = computed(() => [...(errors.value.academic_year_id ?? []), ...(errors.value.slots ?? [])])

const cellErrors = (periodId, day) => {
  const index = sentOrder.value.indexOf(key(periodId, day))
  if (index < 0) return []

  return Object.entries(errors.value)
    .filter(([name]) => name.startsWith(`slots.${index}.`))
    .flatMap(([, messages]) => messages)
}

// Only the teachers assigned to the subject in this section and year.
const teachersFor = (subjectId) => {
  const seen = new Set()

  return assignments.value
    .filter((a) => a.subject_id === subjectId && a.staff && !seen.has(a.staff_id) && seen.add(a.staff_id))
    .map((a) => a.staff)
}

const onSubjectChange = (periodId, day) => {
  const cell = grid[key(periodId, day)]
  if (!cell.subject_id) {
    Object.assign(cell, { staff_id: '', room: '' })
  } else if (cell.staff_id && !teachersFor(cell.subject_id).some((t) => t.id === cell.staff_id)) {
    cell.staff_id = ''
  }
}

const fillGrid = () => {
  for (const name of Object.keys(grid)) delete grid[name]

  for (const period of periods.value) {
    for (const day of routine.value.days) {
      grid[key(period.id, day)] = { subject_id: '', staff_id: '', room: '' }
    }
  }

  for (const slot of routine.value.slots) {
    if (grid[key(slot.period_id, slot.day)]) {
      grid[key(slot.period_id, slot.day)] = { subject_id: slot.subject_id, staff_id: slot.staff_id ?? '', room: slot.room ?? '' }
    }
  }
}

const loadRoutine = async () => {
  errors.value = {}
  summary.value = ''
  loadError.value = ''
  routine.value = null

  if (!sectionId.value || !yearId.value) return

  loading.value = true
  router.replace({ query: { section_id: sectionId.value } })
  try {
    const { data } = await api.get(`/routines/sections/${sectionId.value}`, { params: { academic_year_id: yearId.value } })
    routine.value = data.data

    if (canEdit.value) {
      const [curriculumRes, assignmentsRes] = await Promise.all([
        api.get(`/classes/${section.value.class_id}/subjects`, { params: section.value.group ? { group: section.value.group } : {} }),
        api.get(`/sections/${sectionId.value}/subject-teachers`, { params: { academic_year_id: yearId.value } }),
      ])
      const seen = new Set()
      subjects.value = curriculumRes.data.data
        .filter((c) => !seen.has(c.subject_id) && seen.add(c.subject_id))
        .map((c) => c.subject)
      assignments.value = assignmentsRes.data.data
    }

    fillGrid()
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Failed to load the routine'
  } finally {
    loading.value = false
  }
}

const save = async () => {
  errors.value = {}
  summary.value = ''
  saving.value = true

  const cells = []
  for (const period of periods.value) {
    if (period.is_break) continue
    for (const day of routine.value.days) {
      const cell = grid[key(period.id, day)]
      if (cell?.subject_id) cells.push({ id: key(period.id, day), day, period_id: period.id, subject_id: cell.subject_id, staff_id: cell.staff_id || null, room: cell.room || null })
    }
  }
  sentOrder.value = cells.map((c) => c.id)

  try {
    const { data } = await api.put(`/routines/sections/${sectionId.value}`, {
      academic_year_id: yearId.value,
      slots: cells.map(({ id, ...rest }) => rest),
    })
    routine.value = data.data
    fillGrid()
    alert(data.message || 'Routine saved successfully')
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors ?? {}
      summary.value = error.response.data.message || 'Some cells have errors.'
    } else {
      alert(error.response?.data?.message || 'Failed to save the routine')
    }
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  try {
    const [yearsRes, sectionsRes] = await Promise.all([
      api.get('/academic-years', { params: { per_page: 100 } }),
      api.get('/sections', { params: { per_page: 100, is_active: 1 } }),
    ])
    years.value = yearsRes.data.data
    sections.value = sectionsRes.data.data
    const active = years.value.find((y) => y.is_active)
    yearId.value = active ? active.id : (years.value[0]?.id || '')
    await loadRoutine()
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Failed to load sections'
  }
})
</script>
