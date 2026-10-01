<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">Exams</h1>
      <router-link to="/exams/create" class="btn btn-primary">➕ Add Exam</router-link>
    </div>

    <div class="card">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <input v-model="filters.search" type="text" placeholder="Search by name or code..." class="input" @input="onSearch" />
        <select v-model="filters.academic_year_id" class="input" @change="fetchExams(1)">
          <option v-for="year in years" :key="year.id" :value="year.id">
            {{ year.name }}<template v-if="year.is_active"> (active)</template>
          </option>
        </select>
        <select v-model="filters.type" class="input" @change="fetchExams(1)">
          <option value="">All types</option>
          <option v-for="type in EXAM_TYPES" :key="type.value" :value="type.value">{{ type.label }}</option>
        </select>
        <select v-model="filters.status" class="input" @change="fetchExams(1)">
          <option value="">All statuses</option>
          <option v-for="(label, value) in EXAM_STATUS_LABELS" :key="value" :value="value">{{ label }}</option>
        </select>
      </div>
    </div>

    <div class="card">
      <div v-if="loading" class="text-center py-8"><p class="text-gray-500">Loading exams...</p></div>
      <div v-else-if="exams.length === 0" class="text-center py-8"><p class="text-gray-500">No exams found</p></div>
      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Exam</th>
              <th>Type</th>
              <th>Dates</th>
              <th>Classes</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="exam in exams" :key="exam.id" class="hover:bg-gray-50">
              <td class="font-medium">
                {{ exam.name_en || exam.name_bn }}
                <span v-if="exam.name_en && exam.name_bn" class="text-gray-500">({{ exam.name_bn }})</span>
                <span class="badge ml-1">{{ exam.code }}</span>
              </td>
              <td>{{ EXAM_TYPE_LABELS[exam.type] || exam.type }}</td>
              <td class="text-gray-500">{{ exam.start_date }} – {{ exam.end_date }}</td>
              <td>{{ (exam.classes || []).map((c) => c.number).join(', ') || '-' }}</td>
              <td>
                <span :class="['badge', EXAM_STATUS_BADGES[exam.status]]">{{ EXAM_STATUS_LABELS[exam.status] || exam.status }}</span>
              </td>
              <td>
                <div class="flex flex-wrap gap-x-3 gap-y-1 text-sm">
                  <router-link :to="`/exams/${exam.id}/schedule`" class="text-primary-600 hover:text-primary-800">Schedule</router-link>
                  <router-link :to="`/exams/${exam.id}/marks`" class="text-primary-600 hover:text-primary-800">Marks</router-link>
                  <button v-if="exam.status === 'draft'" class="text-green-700 hover:text-green-900" @click="openMarksEntry(exam)">Open mark entry</button>
                  <router-link :to="`/exams/${exam.id}/edit`" class="text-primary-600 hover:text-primary-800" title="Edit">✏️</router-link>
                  <button class="text-red-600 hover:text-red-800" title="Delete" @click="deleteExam(exam)">🗑️</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="pagination.total > 0" class="mt-4 flex justify-between items-center">
        <p class="text-sm text-gray-700">Showing {{ pagination.from }} to {{ pagination.to }} of {{ pagination.total }} results</p>
        <div class="flex space-x-2">
          <button :disabled="pagination.current_page === 1" class="btn btn-secondary disabled:opacity-50" @click="fetchExams(pagination.current_page - 1)">Previous</button>
          <button :disabled="pagination.current_page === pagination.last_page" class="btn btn-secondary disabled:opacity-50" @click="fetchExams(pagination.current_page + 1)">Next</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { EXAM_STATUS_BADGES, EXAM_STATUS_LABELS, EXAM_TYPE_LABELS, EXAM_TYPES } from '@/constants/exams'

const exams = ref([])
const years = ref([])
const loading = ref(false)
const pagination = reactive({ current_page: 1, last_page: 1, per_page: 15, total: 0, from: 0, to: 0 })
const filters = reactive({ search: '', academic_year_id: '', type: '', status: '' })

let searchTimeout = null
const onSearch = () => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => fetchExams(1), 300)
}

const fetchExams = async (page = 1) => {
  loading.value = true
  try {
    const { data } = await api.get('/exams', { params: { page, per_page: pagination.per_page, ...filters } })
    exams.value = data.data
    Object.assign(pagination, {
      current_page: data.meta.current_page,
      last_page: data.meta.last_page,
      total: data.meta.total,
      from: data.meta.from,
      to: data.meta.to,
    })
  } catch (error) {
    console.error('Failed to fetch exams:', error)
    alert(error.response?.data?.message || 'Failed to fetch exams')
  } finally {
    loading.value = false
  }
}

const openMarksEntry = async (exam) => {
  if (!confirm(`Open mark entry for "${exam.name_en || exam.name_bn}"? Teachers can then enter marks.`)) return

  try {
    await api.post(`/exams/${exam.id}/open-marks-entry`)
    await fetchExams(pagination.current_page)
  } catch (error) {
    alert(error.response?.data?.message || 'Failed to open mark entry')
  }
}

const deleteExam = async (exam) => {
  if (!confirm('Are you sure you want to delete this exam?')) return

  try {
    await api.delete(`/exams/${exam.id}`)
    await fetchExams(pagination.current_page)
  } catch (error) {
    console.error('Failed to delete exam:', error)
    alert(error.response?.data?.message || 'Failed to delete exam')
  }
}

onMounted(async () => {
  try {
    const { data } = await api.get('/academic-years', { params: { per_page: 100 } })
    years.value = data.data
    // The API defaults to the active year; show the same choice in the select.
    filters.academic_year_id = (years.value.find((y) => y.is_active) || years.value[0])?.id ?? ''
  } catch (error) {
    console.error('Failed to fetch academic years:', error)
  }
  await fetchExams(1)
})
</script>
