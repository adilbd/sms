<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">
          Subject teachers<span v-if="section"> – {{ section.class?.name }} – {{ section.name }}<template v-if="section.shift"> ({{ section.shift.name_en }})</template></span>
        </h1>
        <p v-if="section" class="text-sm text-gray-500">
          {{ section.shift?.name_en }} shift<template v-if="section.group"> · {{ GROUP_LABELS[section.group] || section.group }} group</template>.
          Tick every teacher of a subject for the chosen academic year; each of them (or an admin) can enter its marks.
        </p>
      </div>
      <router-link to="/sections" class="btn btn-secondary">Back to sections</router-link>
    </div>

    <div v-if="loading" class="card"><p class="text-gray-500 text-center py-8">Loading...</p></div>

    <template v-else>
      <div class="card">
        <label class="block text-sm font-medium text-gray-700 mb-1">Academic year</label>
        <select v-model="selectedYearId" class="input max-w-xs" @change="loadAssignments">
          <option v-for="year in years" :key="year.id" :value="year.id">
            {{ year.name }} <template v-if="year.is_active">(active)</template>
          </option>
        </select>
      </div>

      <div v-if="errorSummary" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <p>{{ errorSummary }}</p>
        <p v-for="message in topLevelErrors" :key="message" class="mt-1">{{ message }}</p>
      </div>

      <div class="card">
        <p v-if="rows.length === 0" class="text-center text-gray-500 py-8">
          This class has no subjects in its curriculum yet.
          <router-link v-if="section" :to="`/classes/${section.class_id}/subjects`" class="text-primary-600">Edit the curriculum</router-link>
        </p>

        <div v-else class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Subject</th>
                <th>Type</th>
                <th>Teacher</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in rows" :key="row.subject_id">
                <td class="font-medium">
                  {{ row.subject?.name }}
                  <span v-if="row.subject?.name_bn" class="text-gray-500">({{ row.subject.name_bn }})</span>
                  <span class="badge ml-1">{{ row.subject?.code }}</span>
                </td>
                <td>{{ row.type === 'optional' ? 'Optional (4th)' : 'Compulsory' }}</td>
                <td>
                  <div class="flex flex-wrap gap-x-4 gap-y-1" role="group" :aria-label="`Teachers of ${row.subject?.name}`">
                    <label v-for="staff in teachers" :key="staff.id" class="inline-flex items-center gap-1.5 text-sm">
                      <input v-model="row.staff_ids" type="checkbox" :value="staff.id" @change="clearErrors" />
                      {{ staff.name_en || staff.name_bn }}
                    </label>
                    <span v-if="teachers.length === 0" class="text-sm text-gray-500">No active teachers in this shift.</span>
                  </div>
                  <p v-for="message in rowErrors(index)" :key="message" class="text-sm text-red-600 mt-1">{{ message }}</p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="flex justify-end">
        <button type="button" class="btn btn-primary" :disabled="saving || rows.length === 0 || !selectedYearId" @click="save">
          {{ saving ? 'Saving...' : 'Save subject teachers' }}
        </button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { GROUP_LABELS } from '@/constants/academic'

const route = useRoute()

const section = ref(null)
const years = ref([])
const teachers = ref([])
const rows = ref([])
const selectedYearId = ref('')
const loading = ref(false)
const saving = ref(false)
const errors = ref({})
const errorSummary = ref('')

const clearErrors = () => {
  errors.value = {}
  errorSummary.value = ''
}

const topLevelErrors = computed(() => [...(errors.value.academic_year_id ?? []), ...(errors.value.assignments ?? [])])

// 422 errors are keyed `assignments.{index}.{field}`, the index being the row's position
// in the array that was sent (the same order as `rows`).
const rowErrors = (index) =>
  Object.entries(errors.value)
    .filter(([key]) => key.startsWith(`assignments.${index}.`))
    .flatMap(([, messages]) => messages)

// Every assignment row is one teacher of one subject: group them per subject row.
const withTeachers = (assignments) =>
  rows.value.map((r) => ({
    ...r,
    staff_ids: assignments.filter((a) => a.subject_id === r.subject_id).map((a) => a.staff_id),
  }))

const loadAssignments = async () => {
  clearErrors()
  if (!selectedYearId.value) {
    rows.value = rows.value.map((r) => ({ ...r, staff_ids: [] }))
    return
  }
  try {
    const { data } = await api.get(`/sections/${section.value.id}/subject-teachers`, {
      params: { academic_year_id: selectedYearId.value },
    })
    rows.value = withTeachers(data.data)
  } catch (error) {
    alert(error.response?.data?.message || 'Failed to load subject teachers')
  }
}

const load = async () => {
  loading.value = true
  try {
    const { data: sectionRes } = await api.get(`/sections/${route.params.id}`)
    section.value = sectionRes.data

    const [curriculumRes, yearsRes, staffRes] = await Promise.all([
      api.get(`/classes/${section.value.class_id}/subjects`, {
        params: section.value.group ? { group: section.value.group } : {},
      }),
      api.get('/academic-years', { params: { per_page: 100 } }),
      api.get('/staff', { params: { per_page: 100, category: 'teacher', is_active: true, shift: section.value.shift_id } }),
    ])

    years.value = yearsRes.data.data
    teachers.value = staffRes.data.data
    const active = years.value.find((y) => y.is_active)
    selectedYearId.value = active ? active.id : (years.value[0]?.id || '')

    // One row per subject: without a group filter a subject can appear once per group.
    const seen = new Set()
    rows.value = curriculumRes.data.data
      .filter((c) => !seen.has(c.subject_id) && seen.add(c.subject_id))
      .map((c) => ({ subject_id: c.subject_id, subject: c.subject, type: c.type, staff_ids: [] }))

    await loadAssignments()
  } catch (error) {
    console.error('Failed to load subject teachers:', error)
    alert(error.response?.data?.message || 'Failed to load subject teachers')
  } finally {
    loading.value = false
  }
}

const save = async () => {
  clearErrors()
  saving.value = true
  try {
    const { data } = await api.put(`/sections/${section.value.id}/subject-teachers`, {
      academic_year_id: selectedYearId.value,
      assignments: rows.value.map((r) => ({ subject_id: r.subject_id, staff_ids: r.staff_ids })),
    })
    rows.value = withTeachers(data.data)
    alert(data.message || 'Subject teachers updated successfully')
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors ?? {}
      errorSummary.value = error.response.data.message || 'Some assignments have errors.'
    } else {
      console.error('Failed to save subject teachers:', error)
      alert(error.response?.data?.message || 'Failed to save subject teachers')
    }
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>
