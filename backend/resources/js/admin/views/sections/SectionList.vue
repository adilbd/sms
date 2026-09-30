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
              <th>Class teacher</th>
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
              <td>
                <select
                  class="input"
                  :value="classTeachers[section.id]?.staff?.id || ''"
                  :disabled="!selectedYearId"
                  @change="assignClassTeacher(section, $event.target.value)"
                >
                  <option value="">Unassigned</option>
                  <option v-for="staff in eligibleTeachers[section.shift_id] || []" :key="staff.id" :value="staff.id">
                    {{ staff.name_en || staff.name_bn }}
                  </option>
                </select>
              </td>
              <td>
                <div class="flex space-x-2">
                  <router-link :to="`/sections/${section.id}/edit`" class="text-primary-600 hover:text-primary-800" title="Edit">✏️</router-link>
                  <button @click="deleteSection(section)" class="text-red-600 hover:text-red-800" title="Delete">🗑️</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
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
      classTeachers[section.id] = data.data.find((row) => row.academic_year_id === selectedYearId.value) || null
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

const assignClassTeacher = async (section, staffId) => {
  try {
    const { data } = await api.put(`/sections/${section.id}/class-teacher`, {
      academic_year_id: selectedYearId.value,
      staff_id: staffId || null,
    })
    classTeachers[section.id] = data.data
  } catch (error) {
    alert(error.response?.data?.message || 'Failed to assign class teacher')
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
