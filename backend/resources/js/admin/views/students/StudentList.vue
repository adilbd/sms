<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">Students</h1>
      <div class="flex gap-2">
        <router-link v-if="canSeeSensitive" to="/students/promotion" class="btn btn-secondary">
          Promote a section
        </router-link>
        <router-link v-if="authStore.hasPermission('create-students')" to="/students/create" class="btn btn-primary">
          ➕ Add Student
        </router-link>
      </div>
    </div>

    <!-- Filters -->
    <div class="card">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <input
          v-model="filters.search"
          type="text"
          :placeholder="canSeeSensitive ? 'Search name, student ID, guardian mobile...' : 'Search name or student ID...'"
          class="input md:col-span-2"
          @input="onSearch"
        />
        <select v-model="filters.academic_year_id" class="input" @change="fetchStudents()">
          <option v-for="year in years" :key="year.id" :value="year.id">
            {{ year.name }}{{ year.is_active ? ' (current)' : '' }}
          </option>
        </select>
        <select v-model="filters.status" class="input" @change="fetchStudents()">
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="left">Left</option>
          <option value="graduated">Graduated</option>
        </select>
        <select v-model="filters.class_id" class="input" @change="onClassChange">
          <option value="">All classes</option>
          <option v-for="cls in classes" :key="cls.id" :value="cls.id">{{ cls.name }}</option>
        </select>
        <select v-model="filters.section_id" class="input" @change="fetchStudents()">
          <option value="">All sections</option>
          <option v-for="section in sectionOptions" :key="section.id" :value="section.id">
            {{ section.class?.name }} - {{ section.name }}
          </option>
        </select>
        <select v-model="filters.shift_id" class="input" @change="fetchStudents()">
          <option value="">All shifts</option>
          <option v-for="shift in shifts" :key="shift.id" :value="shift.id">{{ shift.name_en }}</option>
        </select>
        <select v-model="filters.group" class="input" @change="fetchStudents()">
          <option value="">All groups</option>
          <option v-for="(label, value) in GROUP_LABELS" :key="value" :value="value">{{ label }}</option>
        </select>
      </div>
    </div>

    <!-- Students Table -->
    <div class="card">
      <div v-if="loading" class="text-center py-8">
        <p class="text-gray-500">Loading students...</p>
      </div>

      <div v-else-if="students.length === 0" class="text-center py-8">
        <p class="text-gray-500">No students found</p>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Student ID</th>
              <th>Name</th>
              <th>Class / Section / Roll</th>
              <th>Group</th>
              <th v-if="canSeeSensitive">Guardian mobile</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="student in students" :key="student.id" class="hover:bg-gray-50">
              <td class="font-medium">{{ student.student_id }}</td>
              <td>
                <div class="flex items-center">
                  <img v-if="student.photo_url" :src="student.photo_url" alt="" class="w-8 h-8 rounded-full object-cover mr-3" />
                  <div v-else class="w-8 h-8 rounded-full bg-primary-600 text-white flex items-center justify-center text-xs font-bold mr-3">
                    {{ getInitials(student.name_en || student.name_bn) }}
                  </div>
                  <div>
                    <p class="font-medium">{{ student.name_en || student.name_bn }}</p>
                    <p v-if="student.name_en && student.name_bn" class="text-xs text-gray-500">{{ student.name_bn }}</p>
                  </div>
                </div>
              </td>
              <td>
                <template v-if="student.current_enrolment">
                  {{ student.current_enrolment.class?.name }} / {{ student.current_enrolment.section?.name }}
                  <span class="text-gray-500">/ Roll {{ student.current_enrolment.roll_number ?? '-' }}</span>
                </template>
                <span v-else class="text-gray-400">-</span>
              </td>
              <td>{{ GROUP_LABELS[student.current_enrolment?.group] || '-' }}</td>
              <td v-if="canSeeSensitive">{{ student.guardian?.mobile }}</td>
              <td>
                <span :class="['badge', student.status === 'active' ? 'badge-success' : 'badge-danger']" class="capitalize">
                  {{ student.status }}
                </span>
              </td>
              <td>
                <div class="flex space-x-2">
                  <router-link :to="`/students/${student.id}`" class="text-primary-600 hover:text-primary-800" title="View">
                    👁️
                  </router-link>
                  <router-link v-if="authStore.hasPermission('edit-students')" :to="`/students/${student.id}/edit`" class="text-primary-600 hover:text-primary-800" title="Edit">
                    ✏️
                  </router-link>
                  <button v-if="authStore.hasPermission('delete-students')" @click="deleteStudent(student)" class="text-red-600 hover:text-red-800" title="Delete">
                    🗑️
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="pagination.total > 0" class="mt-4 flex justify-between items-center">
        <p class="text-sm text-gray-700">
          Showing {{ pagination.from }} to {{ pagination.to }} of {{ pagination.total }} results
        </p>
        <div class="flex space-x-2">
          <button
            @click="changePage(pagination.current_page - 1)"
            :disabled="pagination.current_page === 1"
            class="btn btn-secondary disabled:opacity-50"
          >
            Previous
          </button>
          <button
            @click="changePage(pagination.current_page + 1)"
            :disabled="pagination.current_page === pagination.last_page"
            class="btn btn-secondary disabled:opacity-50"
          >
            Next
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const route = useRoute()
// The API omits guardian mobiles (and ignores them in search) without edit-students.
const canSeeSensitive = computed(() => authStore.hasPermission('edit-students'))

const GROUP_LABELS = {
  science: 'Science',
  business_studies: 'Business Studies',
  humanities: 'Humanities',
}

const students = ref([])
const classes = ref([])
const sections = ref([])
const shifts = ref([])
const years = ref([])
const loading = ref(false)

const filters = reactive({
  search: '',
  academic_year_id: '',
  class_id: '',
  section_id: '',
  shift_id: '',
  group: '',
  status: '',
})

const pagination = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 15,
  total: 0,
  from: 0,
  to: 0,
})

const sectionOptions = computed(() =>
  filters.class_id ? sections.value.filter((s) => s.class_id === filters.class_id) : sections.value
)

let searchTimeout = null
const onSearch = () => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => fetchStudents(), 300)
}

const onClassChange = () => {
  filters.section_id = ''
  fetchStudents()
}

const fetchStudents = async (page = 1) => {
  loading.value = true
  try {
    const { data } = await api.get('/students', {
      params: { page, per_page: pagination.per_page, ...filters },
    })
    students.value = data.data
    Object.assign(pagination, {
      current_page: data.meta.current_page,
      last_page: data.meta.last_page,
      total: data.meta.total,
      from: data.meta.from,
      to: data.meta.to,
    })
  } catch (error) {
    console.error('Failed to fetch students:', error)
  } finally {
    loading.value = false
  }
}

const fetchLookups = async () => {
  try {
    // The API caps per_page at 100, so a school with more than 100 sections would see
    // only the first 100 here; page through meta.last_page if that ever happens.
    const [classRes, sectionRes, shiftRes, yearRes] = await Promise.all([
      api.get('/classes', { params: { per_page: 100 } }),
      api.get('/sections', { params: { per_page: 100 } }),
      api.get('/shifts', { params: { per_page: 100 } }),
      api.get('/academic-years', { params: { per_page: 100 } }),
    ])
    classes.value = classRes.data.data
    sections.value = sectionRes.data.data
    shifts.value = shiftRes.data.data
    years.value = yearRes.data.data
    // Default to the current academic year, like the API does.
    filters.academic_year_id = years.value.find((y) => y.is_active)?.id ?? ''
  } catch (error) {
    console.error('Failed to fetch filters:', error)
  }
}

const deleteStudent = async (student) => {
  if (!confirm(`Delete ${student.name_en || student.name_bn} (${student.student_id})? Their logins will be deactivated.`)) return

  try {
    await api.delete(`/students/${student.id}`)
    await fetchStudents(pagination.current_page)
  } catch (error) {
    console.error('Failed to delete student:', error)
    alert(error.response?.data?.message || 'Failed to delete student')
  }
}

const changePage = (page) => {
  if (page >= 1 && page <= pagination.last_page) {
    fetchStudents(page)
  }
}

const getInitials = (name) => {
  return name
    ?.split(' ')
    .map((n) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2) || '??'
}

onMounted(async () => {
  await fetchLookups()
  // "View students" on My subjects links here with the section to show.
  if (route.query.section_id) filters.section_id = Number(route.query.section_id)
  fetchStudents()
})
</script>
