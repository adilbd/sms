<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">Staff</h1>
      <router-link to="/staff/create" class="btn btn-primary">➕ Add Staff</router-link>
    </div>

    <div class="card space-y-4">
      <div class="flex gap-2 border-b border-gray-200">
        <button
          type="button"
          class="px-4 py-2 text-sm font-medium border-b-2"
          :class="tab === 'current' ? 'border-primary-600 text-primary-700' : 'border-transparent text-gray-500'"
          @click="switchTab('current')"
        >
          Current
        </button>
        <button
          type="button"
          class="px-4 py-2 text-sm font-medium border-b-2"
          :class="tab === 'former' ? 'border-primary-600 text-primary-700' : 'border-transparent text-gray-500'"
          @click="switchTab('former')"
        >
          Former
        </button>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <input v-model="filters.search" type="text" placeholder="Search by name..." class="input" @input="onSearch" />
        <select v-model="filters.category" class="input" @change="fetchStaff()">
          <option value="">All categories</option>
          <option value="teacher">Teacher</option>
          <option value="staff">Staff</option>
        </select>
        <select v-model="filters.position" class="input" @change="fetchStaff()">
          <option value="">All positions</option>
          <option value="head">Head</option>
          <option value="assistant_head">Assistant Head</option>
          <option value="teacher">Teacher</option>
          <option value="staff">Staff</option>
        </select>
        <select v-model="filters.shift" class="input" @change="fetchStaff()">
          <option value="">All shifts</option>
          <option v-for="shift in shifts" :key="shift.id" :value="shift.id">{{ shift.name_bn }} ({{ shift.name_en }})</option>
        </select>
      </div>
    </div>

    <div class="card">
      <div v-if="loading" class="text-center py-8">
        <p class="text-gray-500">Loading staff...</p>
      </div>
      <div v-else-if="staff.length === 0" class="text-center py-8">
        <p class="text-gray-500">No staff found</p>
      </div>
      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Photo</th>
              <th>Name</th>
              <th>Position</th>
              <th>Shifts</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="member in staff" :key="member.id" class="hover:bg-gray-50">
              <td>
                <img v-if="member.photo_url" :src="member.photo_url" alt="" class="h-12 w-12 rounded-full object-cover" />
                <span v-else class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-xl">🧑</span>
              </td>
              <td>
                <p class="font-medium">{{ member.name_en || member.name_bn }}</p>
                <p v-if="member.name_en && member.name_bn" class="text-xs text-gray-500">{{ member.name_bn }}</p>
                <p class="text-xs text-gray-500">{{ member.designation }}</p>
              </td>
              <td class="text-gray-500 capitalize">{{ member.position.replace('_', ' ') }}</td>
              <td>
                <span v-for="shift in member.shifts" :key="shift.id" class="mr-1 rounded-full bg-primary-50 px-2 py-0.5 text-xs text-primary-700">
                  {{ shift.name_en }}
                </span>
              </td>
              <td>
                <span :class="['badge', member.is_former ? 'badge-warning' : 'badge-success']">
                  {{ member.status }}
                </span>
              </td>
              <td>
                <div class="flex space-x-2">
                  <a v-if="member.is_published" :href="member.web_url" target="_blank" rel="noopener" class="text-primary-600 hover:text-primary-800" title="View on website">👁️</a>
                  <router-link :to="`/staff/${member.id}/edit`" class="text-primary-600 hover:text-primary-800" title="Edit">✏️</router-link>
                  <button v-if="!member.is_former" @click="openArchive(member)" class="text-amber-600 hover:text-amber-800" title="Archive">🗂️</button>
                  <button v-else @click="restore(member)" class="text-green-600 hover:text-green-800" title="Restore">↩️</button>
                  <button @click="deleteStaff(member.id)" class="text-red-600 hover:text-red-800" title="Delete">🗑️</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="pagination.total > 0" class="mt-4 flex justify-between items-center">
        <p class="text-sm text-gray-700">Showing {{ pagination.from }} to {{ pagination.to }} of {{ pagination.total }} results</p>
        <div class="flex space-x-2">
          <button @click="fetchStaff(pagination.current_page - 1)" :disabled="pagination.current_page === 1" class="btn btn-secondary disabled:opacity-50">Previous</button>
          <button @click="fetchStaff(pagination.current_page + 1)" :disabled="pagination.current_page === pagination.last_page" class="btn btn-secondary disabled:opacity-50">Next</button>
        </div>
      </div>
    </div>

    <div v-if="archiveTarget" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 px-4">
      <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
        <h2 class="text-lg font-semibold text-gray-900">Archive {{ archiveTarget.name_en || archiveTarget.name_bn }}</h2>
        <div class="mt-4 space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select v-model="archiveForm.status" class="input">
              <option value="retired">Retired</option>
              <option value="transferred">Transferred</option>
              <option value="resigned">Resigned</option>
              <option value="deceased">Deceased</option>
            </select>
            <p v-if="archiveErrors.status" class="text-sm text-red-600 mt-1">{{ archiveErrors.status[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Leaving date</label>
            <input v-model="archiveForm.leaving_date" type="date" class="input" required />
            <p v-if="archiveErrors.leaving_date" class="text-sm text-red-600 mt-1">{{ archiveErrors.leaving_date[0] }}</p>
          </div>
        </div>
        <div class="mt-6 flex justify-end space-x-2">
          <button type="button" class="btn btn-secondary" @click="archiveTarget = null">Cancel</button>
          <button type="button" class="btn btn-primary" :disabled="archiving" @click="confirmArchive">
            {{ archiving ? 'Saving...' : 'Archive' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '@/services/api'

const staff = ref([])
const shifts = ref([])
const loading = ref(false)
const tab = ref('current')

const filters = reactive({ search: '', category: '', position: '', shift: '' })

const pagination = reactive({ current_page: 1, last_page: 1, per_page: 15, total: 0, from: 0, to: 0 })

let searchTimeout = null
const onSearch = () => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => fetchStaff(), 300)
}

const switchTab = (value) => {
  tab.value = value
  fetchStaff()
}

const fetchShifts = async () => {
  try {
    const { data } = await api.get('/shifts', { params: { per_page: 100, is_active: 1 } })
    shifts.value = data.data
  } catch (error) {
    console.error('Failed to fetch shifts:', error)
  }
}

const fetchStaff = async (page = 1) => {
  loading.value = true
  try {
    const { data } = await api.get('/staff', {
      params: {
        page,
        per_page: pagination.per_page,
        is_active: tab.value === 'current' ? 1 : 0,
        ...filters,
      },
    })
    staff.value = data.data
    Object.assign(pagination, {
      current_page: data.meta.current_page,
      last_page: data.meta.last_page,
      total: data.meta.total,
      from: data.meta.from,
      to: data.meta.to,
    })
  } catch (error) {
    console.error('Failed to fetch staff:', error)
  } finally {
    loading.value = false
  }
}

const deleteStaff = async (id) => {
  if (!confirm('Are you sure you want to delete this staff member?')) return
  try {
    await api.delete(`/staff/${id}`)
    await fetchStaff(pagination.current_page)
  } catch (error) {
    console.error('Failed to delete staff:', error)
    alert(error.response?.data?.message || 'Failed to delete staff member')
  }
}

const archiveTarget = ref(null)
const archiveForm = reactive({ status: 'retired', leaving_date: '' })
const archiveErrors = ref({})
const archiving = ref(false)

const openArchive = (member) => {
  archiveTarget.value = member
  archiveForm.status = 'retired'
  archiveForm.leaving_date = ''
  archiveErrors.value = {}
}

const confirmArchive = async () => {
  archiving.value = true
  archiveErrors.value = {}
  try {
    await api.put(`/staff/${archiveTarget.value.id}`, {
      status: archiveForm.status,
      leaving_date: archiveForm.leaving_date,
    })
    archiveTarget.value = null
    await fetchStaff(pagination.current_page)
  } catch (error) {
    if (error.response?.status === 422) {
      archiveErrors.value = error.response.data.errors
    } else {
      console.error('Failed to archive staff member:', error)
      alert(error.response?.data?.message || 'Failed to archive staff member')
    }
  } finally {
    archiving.value = false
  }
}

const restore = async (member) => {
  if (!confirm('Restore this staff member to active status?')) return
  try {
    await api.put(`/staff/${member.id}`, { status: 'active', leaving_date: null })
    await fetchStaff(pagination.current_page)
  } catch (error) {
    console.error('Failed to restore staff member:', error)
    alert(error.response?.data?.message || 'Failed to restore staff member')
  }
}

onMounted(() => {
  fetchShifts()
  fetchStaff()
})
</script>
