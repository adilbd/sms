<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">Subjects</h1>
      <router-link to="/subjects/create" class="btn btn-primary">➕ Add Subject</router-link>
    </div>

    <div class="card">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <input v-model="filters.search" type="text" placeholder="Search by name or code..." class="input" @input="onSearch" />
        <select v-model="filters.is_active" class="input" @change="fetchSubjects()">
          <option value="">All statuses</option>
          <option value="1">Active</option>
          <option value="0">Inactive</option>
        </select>
      </div>
    </div>

    <div class="card">
      <div v-if="loading" class="text-center py-8">
        <p class="text-gray-500">Loading subjects...</p>
      </div>
      <div v-else-if="subjects.length === 0" class="text-center py-8">
        <p class="text-gray-500">No subjects found</p>
      </div>
      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Code</th>
              <th>Type</th>
              <th>Marks (pass / total)</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="subject in subjects" :key="subject.id" class="hover:bg-gray-50">
              <td class="font-medium">
                {{ subject.name }}
                <span v-if="subject.name_bn" class="text-gray-500">({{ subject.name_bn }})</span>
              </td>
              <td>{{ subject.code }}</td>
              <td class="capitalize">{{ subject.type }}</td>
              <td>{{ subject.pass_marks }} / {{ subject.total_marks }}</td>
              <td>
                <span :class="['badge', subject.is_active ? 'badge-success' : 'badge-warning']">
                  {{ subject.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td>
                <div class="flex space-x-2">
                  <router-link :to="`/subjects/${subject.id}/edit`" class="text-primary-600 hover:text-primary-800" title="Edit">✏️</router-link>
                  <button @click="deleteSubject(subject)" class="text-red-600 hover:text-red-800" title="Delete">🗑️</button>
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

const subjects = ref([])
const loading = ref(false)

const filters = reactive({ search: '', is_active: '' })

let searchTimeout = null
const onSearch = () => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => fetchSubjects(), 300)
}

const fetchSubjects = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/subjects', { params: { per_page: 100, ...filters } })
    subjects.value = data.data
  } catch (error) {
    console.error('Failed to fetch subjects:', error)
  } finally {
    loading.value = false
  }
}

const deleteSubject = async (subject) => {
  if (!confirm(`Delete ${subject.name}?`)) return

  try {
    await api.delete(`/subjects/${subject.id}`)
    await fetchSubjects()
  } catch (error) {
    // 409: the subject is used in exam schedules, teacher assignments or a curriculum.
    alert(error.response?.data?.message || 'Failed to delete subject')
  }
}

onMounted(() => {
  fetchSubjects()
})
</script>
