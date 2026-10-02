<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">Sections</h1>
      <router-link to="/sections/create" class="btn btn-primary">➕ Add Section</router-link>
    </div>

    <div class="card">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <select v-model="filters.class_id" class="input" @change="fetchSections">
          <option value="">All classes</option>
          <option v-for="cls in classes" :key="cls.id" :value="cls.id">{{ cls.name }}</option>
        </select>
        <select v-model="filters.shift_id" class="input" @change="fetchSections">
          <option value="">All shifts</option>
          <option v-for="shift in shifts" :key="shift.id" :value="shift.id">{{ shift.name_en }}</option>
        </select>
        <select v-model="filters.group" class="input" @change="fetchSections">
          <option value="">All groups</option>
          <option v-for="group in GROUPS" :key="group.value" :value="group.value">{{ group.label }}</option>
        </select>
        <select v-model="selectedYearId" class="input" @change="fetchClassTeachers">
          <option v-for="year in years" :key="year.id" :value="year.id">
            {{ year.name }} <template v-if="year.is_active">(active)</template>
          </option>
        </select>
      </div>
    </div>

    <div class="card">
      <div v-if="loading" class="text-center py-8">
        <p class="text-gray-500">Loading sections...</p>
      </div>
      <div v-else-if="sections.length === 0" class="text-center py-8">
        <p class="text-gray-500">No sections found</p>
      </div>
      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Class</th>
              <th>Section</th>
              <th>Shift</th>
              <th>Group</th>
              <th>Capacity</th>
              <th>Class teachers</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="section in sections" :key="section.id" class="hover:bg-gray-50">
              <td>{{ section.class?.name }}</td>
              <td class="font-medium">{{ section.name }} ({{ section.code }})</td>
              <td>{{ section.shift?.name_en || '-' }}</td>
              <td>{{ section.group ? (GROUP_LABELS[section.group] || section.group) : '-' }}</td>
              <td>{{ section.capacity }}</td>
              <td class="min-w-[16rem]">
                <ul v-if="classTeachers[section.id]?.length" class="space-y-0.5 text-sm">
                  <li v-for="row in classTeachers[section.id]" :key="row.staff_id">
                    {{ row.staff?.name_en || row.staff?.name_bn }}
                    <span v-if="row.is_main" class="badge ml-1">Main</span>
                  </li>
                </ul>
                <span v-else class="text-sm text-gray-500">Unassigned</span>
                <button
                  type="button"
                  class="mt-1 text-sm text-primary-600 hover:text-primary-800"
                  :disabled="!selectedYearId"
                  @click="openEditor(section)"
                >
                  Edit class teachers
                </button>
              </td>
              <td>
                <div class="flex space-x-2">
                  <router-link :to="`/sections/${section.id}/subject-teachers`" class="text-primary-600 hover:text-primary-800" title="Subject teachers">👩‍🏫</router-link>
                  <router-link :to="`/sections/${section.id}/edit`" class="text-primary-600 hover:text-primary-800" title="Edit">✏️</router-link>
                  <button @click="deleteSection(section)" class="text-red-600 hover:text-red-800" title="Delete">🗑️</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div
      v-if="editing"
      class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="class-teachers-title"
    >
      <div class="card w-full max-w-lg space-y-4">
        <h2 id="class-teachers-title" class="text-lg font-semibold text-gray-900">
          Class teachers – {{ editing.class?.name }}, Section {{ editing.name }}
        </h2>
        <p class="text-sm text-gray-500">
          Tick every class teacher and pick one as the main teacher. All of them can take attendance; the main one is
          shown on report cards and the dashboard.
        </p>

        <fieldset class="space-y-1">
          <legend class="sr-only">Class teachers</legend>
          <div v-for="staff in eligibleTeachers[editing.shift_id] || []" :key="staff.id" class="flex items-center justify-between gap-3">
            <label class="inline-flex items-center gap-2 text-sm">
              <input v-model="draftIds" type="checkbox" :value="staff.id" @change="onToggle(staff.id)" />
              {{ staff.name_en || staff.name_bn }}
            </label>
            <label class="inline-flex items-center gap-1.5 text-sm text-gray-600">
              <input v-model="draftMainId" type="radio" name="main-class-teacher" :value="staff.id" :disabled="!draftIds.includes(staff.id)" />
              Main
            </label>
          </div>
          <p v-if="(eligibleTeachers[editing.shift_id] || []).length === 0" class="text-sm text-gray-500">No active teachers in this shift.</p>
        </fieldset>

        <p v-for="message in draftErrors" :key="message" class="text-sm text-red-600">{{ message }}</p>

        <div class="flex justify-end gap-2">
          <button type="button" class="btn btn-secondary" @click="editing = null">Cancel</button>
          <button type="button" class="btn btn-primary" :disabled="saving" @click="saveClassTeachers">
            {{ saving ? 'Saving...' : 'Save' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { GROUPS, GROUP_LABELS } from '@/constants/academic'

const sections = ref([])
const classes = ref([])
const shifts = ref([])
const years = ref([])
const loading = ref(false)
const selectedYearId = ref('')
const classTeachers = reactive({})
const eligibleTeachers = reactive({})
const editing = ref(null)
const draftIds = ref([])
const draftMainId = ref(null)
const draftErrors = ref([])
const saving = ref(false)

const filters = reactive({ class_id: '', shift_id: '', group: '' })

const fetchLookups = async () => {
  const [classesRes, shiftsRes, yearsRes] = await Promise.all([
    api.get('/classes', { params: { per_page: 100 } }),
    api.get('/shifts', { params: { per_page: 100, is_active: true } }),
    api.get('/academic-years', { params: { per_page: 100 } }),
  ])
  classes.value = classesRes.data.data
  shifts.value = shiftsRes.data.data
  years.value = yearsRes.data.data
  const active = years.value.find((year) => year.is_active)
  selectedYearId.value = active ? active.id : (years.value[0]?.id || '')
}

const fetchSections = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/sections', { params: { per_page: 100, ...filters } })
    sections.value = data.data
    await fetchClassTeachers()
    await fetchEligibleTeachers()
  } catch (error) {
    console.error('Failed to fetch sections:', error)
  } finally {
    loading.value = false
  }
}

const fetchClassTeachers = async () => {
  if (!selectedYearId.value) return

  await Promise.all(sections.value.map(async (section) => {
    try {
      const { data } = await api.get(`/sections/${section.id}/class-teachers`)
      classTeachers[section.id] = data.data.filter((row) => row.academic_year_id === selectedYearId.value)
    } catch (error) {
      console.error('Failed to fetch class teachers:', error)
    }
  }))
}

const fetchEligibleTeachers = async () => {
  const shiftIds = [...new Set(sections.value.map((s) => s.shift_id).filter(Boolean))]

  await Promise.all(shiftIds.map(async (shiftId) => {
    if (eligibleTeachers[shiftId]) return
    try {
      const { data } = await api.get('/staff', { params: { per_page: 100, category: 'teacher', is_active: true, shift: shiftId } })
      eligibleTeachers[shiftId] = data.data
    } catch (error) {
      console.error('Failed to fetch staff:', error)
    }
  }))
}

const openEditor = (section) => {
  const rows = classTeachers[section.id] || []
  editing.value = section
  draftIds.value = rows.map((row) => row.staff_id)
  draftMainId.value = rows.find((row) => row.is_main)?.staff_id ?? null
  draftErrors.value = []
}

// The first teacher ticked becomes the main one; unticking the main one hands it to the next.
const onToggle = (staffId) => {
  if (!draftIds.value.includes(staffId)) {
    if (draftMainId.value === staffId) draftMainId.value = draftIds.value[0] ?? null
  } else if (draftMainId.value === null) {
    draftMainId.value = staffId
  }
}

const saveClassTeachers = async () => {
  draftErrors.value = []
  saving.value = true
  try {
    const { data } = await api.put(`/sections/${editing.value.id}/class-teachers`, {
      academic_year_id: selectedYearId.value,
      teachers: draftIds.value.map((id) => ({ staff_id: id, is_main: id === draftMainId.value })),
    })
    classTeachers[editing.value.id] = data.data
    editing.value = null
  } catch (error) {
    if (error.response?.status === 422) {
      draftErrors.value = Object.values(error.response.data.errors ?? {}).flat()
    } else {
      draftErrors.value = [error.response?.data?.message || 'Failed to save class teachers']
    }
  } finally {
    saving.value = false
  }
}

const deleteSection = async (section) => {
  if (!confirm(`Delete section ${section.name}?`)) return

  try {
    await api.delete(`/sections/${section.id}`)
    await fetchSections()
  } catch (error) {
    alert(error.response?.data?.message || 'Failed to delete section')
  }
}

onMounted(async () => {
  await fetchLookups()
  await fetchSections()
})
</script>
