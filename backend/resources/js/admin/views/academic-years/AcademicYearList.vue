<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">Academic Years</h1>
      <router-link to="/academic-years/create" class="btn btn-primary">➕ Add Academic Year</router-link>
    </div>

    <div class="card">
      <div v-if="loading" class="text-center py-8">
        <p class="text-gray-500">Loading academic years...</p>
      </div>
      <div v-else-if="years.length === 0" class="text-center py-8">
        <p class="text-gray-500">No academic years found</p>
      </div>
      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Year</th>
              <th>Name</th>
              <th>Code</th>
              <th>Start date</th>
              <th>End date</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="year in years" :key="year.id" class="hover:bg-gray-50">
              <td class="font-medium">{{ year.year }}</td>
              <td>{{ year.name }}</td>
              <td>{{ year.code }}</td>
              <td>{{ year.start_date }}</td>
              <td>{{ year.end_date }}</td>
              <td>
                <span :class="['badge', year.is_active ? 'badge-success' : 'badge-warning']">
                  {{ year.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td>
                <div class="flex space-x-2">
                  <button v-if="!year.is_active" @click="activate(year)" class="text-green-600 hover:text-green-800" title="Activate">✅</button>
                  <router-link :to="`/academic-years/${year.id}/edit`" class="text-primary-600 hover:text-primary-800" title="Edit">✏️</router-link>
                  <button @click="deleteYear(year)" class="text-red-600 hover:text-red-800" title="Delete">🗑️</button>
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
import { onMounted, ref } from 'vue'
import api from '@/services/api'

const years = ref([])
const loading = ref(false)

const fetchYears = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/academic-years', { params: { per_page: 100 } })
    years.value = data.data
  } catch (error) {
    console.error('Failed to fetch academic years:', error)
  } finally {
    loading.value = false
  }
}

const activate = async (year) => {
  try {
    await api.post(`/academic-years/${year.id}/activate`)
    await fetchYears()
  } catch (error) {
    alert(error.response?.data?.message || 'Failed to activate academic year')
  }
}

const deleteYear = async (year) => {
  if (!confirm(`Delete academic year ${year.year}?`)) return

  try {
    await api.delete(`/academic-years/${year.id}`)
    await fetchYears()
  } catch (error) {
    alert(error.response?.data?.message || 'Failed to delete academic year')
  }
}

onMounted(() => {
  fetchYears()
})
</script>
