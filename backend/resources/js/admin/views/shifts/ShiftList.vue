<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">Shifts</h1>
      <router-link to="/shifts/create" class="btn btn-primary">➕ Add Shift</router-link>
    </div>

    <div class="card">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <input v-model="filters.search" type="text" placeholder="Search by name..." class="input" @input="onSearch" />
        <select v-model="filters.is_active" class="input" @change="fetchShifts()">
          <option value="">All statuses</option>
          <option value="1">Active</option>
          <option value="0">Inactive</option>
        </select>
      </div>
    </div>

    <div class="card">
      <div v-if="loading" class="text-center py-8">
        <p class="text-gray-500">Loading shifts...</p>
      </div>
      <div v-else-if="shifts.length === 0" class="text-center py-8">
        <p class="text-gray-500">No shifts found</p>
      </div>
      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Time</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="shift in shifts" :key="shift.id" class="hover:bg-gray-50">
              <td class="font-medium">{{ shift.name_bn }} ({{ shift.name_en }})</td>
              <td class="text-gray-500">
                <span v-if="shift.start_time || shift.end_time">{{ shift.start_time || '?' }} – {{ shift.end_time || '?' }}</span>
                <span v-else>-</span>
              </td>
              <td>
                <span :class="['badge', shift.is_active ? 'badge-success' : 'badge-warning']">
                  {{ shift.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td>
                <div class="flex space-x-2">
                  <router-link :to="`/shifts/${shift.id}/periods`" class="text-primary-600 hover:text-primary-800" title="Periods">🕒</router-link>
                  <router-link :to="`/shifts/${shift.id}/edit`" class="text-primary-600 hover:text-primary-800" title="Edit">✏️</router-link>
                  <button @click="deleteShift(shift.id)" class="text-red-600 hover:text-red-800" title="Delete">🗑️</button>
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

const shifts = ref([])
const loading = ref(false)

const filters = reactive({ search: '', is_active: '' })

let searchTimeout = null
const onSearch = () => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => fetchShifts(), 300)
}

const fetchShifts = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/shifts', { params: { per_page: 100, ...filters } })
    shifts.value = data.data
  } catch (error) {
    console.error('Failed to fetch shifts:', error)
  } finally {
    loading.value = false
  }
}

const deleteShift = async (id) => {
  if (!confirm('Are you sure you want to delete this shift?')) return

  try {
    await api.delete(`/shifts/${id}`)
    await fetchShifts()
  } catch (error) {
    console.error('Failed to delete shift:', error)
    alert(error.response?.data?.message || 'Failed to delete shift')
  }
}

onMounted(() => {
  fetchShifts()
})
</script>
