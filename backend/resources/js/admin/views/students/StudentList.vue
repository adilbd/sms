<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">Students</h1>
      <router-link to="/students/create" class="btn btn-primary">
        ➕ Add Student
      </router-link>
    </div>

    <!-- Filters -->
    <div class="card">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <input
          v-model="filters.search"
          type="text"
          placeholder="Search students..."
          class="input"
          @input="fetchStudents"
        />
        <select v-model="filters.class_id" class="input" @change="fetchStudents">
          <option value="">All Classes</option>
          <option v-for="cls in classes" :key="cls.id" :value="cls.id">
            {{ cls.name }}
          </option>
        </select>
        <select v-model="filters.section_id" class="input" @change="fetchStudents">
          <option value="">All Sections</option>
          <option v-for="section in sections" :key="section.id" :value="section.id">
            {{ section.name }}
          </option>
        </select>
        <select v-model="filters.status" class="input" @change="fetchStudents">
          <option value="">All Status</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
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
              <th>Admission No.</th>
              <th>Name</th>
              <th>Class</th>
              <th>Section</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="student in students" :key="student.id" class="hover:bg-gray-50">
              <td class="font-medium">{{ student.admission_number }}</td>
              <td>
                <div class="flex items-center">
                  <div class="w-8 h-8 rounded-full bg-primary-600 text-white flex items-center justify-center text-xs font-bold mr-3">
                    {{ getInitials(student.user?.name) }}
                  </div>
                  {{ student.user?.name }}
                </div>
              </td>
              <td>{{ student.class?.name }}</td>
              <td>{{ student.section?.name }}</td>
              <td>{{ student.user?.email }}</td>
              <td>{{ student.user?.phone || '-' }}</td>
              <td>
                <span :class="['badge', student.is_active ? 'badge-success' : 'badge-danger']">
                  {{ student.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td>
                <div class="flex space-x-2">
                  <router-link
                    :to="`/students/${student.id}`"
                    class="text-primary-600 hover:text-primary-800"
                    title="View"
                  >
                    👁️
                  </router-link>
                  <button
                    @click="deleteStudent(student.id)"
                    class="text-red-600 hover:text-red-800"
                    title="Delete"
                  >
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
import { ref, reactive, onMounted } from 'vue'
import api from '@/services/api'

const students = ref([])
const classes = ref([])
const sections = ref([])
const loading = ref(false)

const filters = reactive({
  search: '',
  class_id: '',
  section_id: '',
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

const fetchStudents = async (page = 1) => {
  loading.value = true
  try {
    const params = {
      page,
      per_page: pagination.per_page,
      ...filters,
    }
    const response = await api.get('/students', { params })
    students.value = response.data.data
    Object.assign(pagination, {
      current_page: response.data.current_page,
      last_page: response.data.last_page,
      total: response.data.total,
      from: response.data.from,
      to: response.data.to,
    })
  } catch (error) {
    console.error('Failed to fetch students:', error)
  } finally {
    loading.value = false
  }
}

const fetchClasses = async () => {
  try {
    const response = await api.get('/classes')
    classes.value = response.data.data || response.data
  } catch (error) {
    console.error('Failed to fetch classes:', error)
  }
}

const deleteStudent = async (id) => {
  if (!confirm('Are you sure you want to delete this student?')) return

  try {
    await api.delete(`/students/${id}`)
    await fetchStudents(pagination.current_page)
  } catch (error) {
    console.error('Failed to delete student:', error)
    alert('Failed to delete student')
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
    .map(n => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2) || '??'
}

onMounted(() => {
  fetchStudents()
  fetchClasses()
})
</script>

