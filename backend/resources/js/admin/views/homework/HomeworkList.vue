<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">Homework</h1>
      <router-link v-if="canCreate" to="/homework/create" class="btn btn-primary">➕ Assign Homework</router-link>
    </div>

    <div class="card">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <select v-model="filters.section_id" class="input" @change="fetchHomework()">
          <option value="">All sections</option>
          <option v-for="section in sectionOptions" :key="section.id" :value="section.id">{{ section.label }}</option>
        </select>
        <select v-model="filters.due" class="input" @change="fetchHomework()">
          <option value="">Upcoming and past</option>
          <option value="upcoming">Upcoming (due today or later)</option>
          <option value="past">Past (due date passed)</option>
        </select>
        <input v-model="filters.from" type="date" class="input" title="Due from" @change="fetchHomework()" />
        <input v-model="filters.to" type="date" class="input" title="Due to" @change="fetchHomework()" />
      </div>
    </div>

    <div class="card">
      <div v-if="loading" class="text-center py-8"><p class="text-gray-500">Loading homework...</p></div>
      <div v-else-if="error" class="text-center py-8"><p class="text-red-600">{{ error }}</p></div>
      <div v-else-if="items.length === 0" class="text-center py-8"><p class="text-gray-500">No homework found</p></div>
      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Class / Section</th>
              <th>Subject</th>
              <th>Assigned by</th>
              <th>Due</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in items" :key="item.id" class="hover:bg-gray-50">
              <td class="font-medium">
                {{ item.title }}
                <span v-if="item.has_attachment" title="Has an attachment">📎</span>
              </td>
              <td>
                {{ item.section?.class?.name }} – {{ item.section?.name }}
                <span v-if="item.section?.group" class="text-gray-500">· {{ GROUP_LABELS[item.section.group] || item.section.group }}</span>
              </td>
              <td>{{ item.subject?.name }}</td>
              <td>{{ item.staff?.name_en || item.staff?.name_bn || 'Admin' }}</td>
              <td>
                {{ item.due_on }}
                <span v-if="item.is_overdue" class="badge badge-warning ml-1">Past due</span>
              </td>
              <td>
                <div class="flex space-x-2">
                  <button v-if="item.has_attachment" class="text-primary-600 hover:text-primary-800" title="Open attachment" @click="openAttachment(item)">📎</button>
                  <router-link v-if="canEdit" :to="`/homework/${item.id}/edit`" class="text-primary-600 hover:text-primary-800" title="Edit">✏️</router-link>
                  <button v-if="canDelete" class="text-red-600 hover:text-red-800" title="Delete" @click="remove(item)">🗑️</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="pagination.total > 0" class="mt-4 flex justify-between items-center">
        <p class="text-sm text-gray-700">Showing {{ pagination.from }} to {{ pagination.to }} of {{ pagination.total }} results</p>
        <div class="flex space-x-2">
          <button :disabled="pagination.current_page === 1" class="btn btn-secondary disabled:opacity-50" @click="fetchHomework(pagination.current_page - 1)">Previous</button>
          <button :disabled="pagination.current_page === pagination.last_page" class="btn btn-secondary disabled:opacity-50" @click="fetchHomework(pagination.current_page + 1)">Next</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { isTeacherOnly } from '@/utils/access'
import { GROUP_LABELS } from '@/constants/academic'

const auth = useAuthStore()
const canCreate = computed(() => auth.hasPermission('create-homework'))
const canEdit = computed(() => auth.hasPermission('edit-homework'))
const canDelete = computed(() => auth.hasPermission('delete-homework'))

const items = ref([])
const loading = ref(false)
const error = ref('')
const sectionOptions = ref([])
const filters = reactive({ section_id: '', due: '', from: '', to: '' })
const pagination = reactive({ current_page: 1, last_page: 1, per_page: 15, total: 0, from: 0, to: 0 })

const fetchHomework = async (page = 1) => {
  loading.value = true
  error.value = ''
  try {
    const params = { page, per_page: pagination.per_page }
    Object.entries(filters).forEach(([key, value]) => {
      if (value) params[key] = value
    })
    const { data } = await api.get('/homework', { params })
    items.value = data.data
    Object.assign(pagination, {
      current_page: data.meta.current_page,
      last_page: data.meta.last_page,
      total: data.meta.total,
      from: data.meta.from,
      to: data.meta.to,
    })
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to load homework'
  } finally {
    loading.value = false
  }
}

// The attachment endpoint needs the bearer token, which a plain link can't send, so the
// file is fetched as a blob and opened from an object URL.
const openAttachment = async (item) => {
  const tab = window.open('', '_blank')
  try {
    const response = await api.get(`/homework/${item.id}/attachment`, { responseType: 'blob' })
    const url = URL.createObjectURL(response.data)
    if (tab) tab.location.href = url
    else window.open(url, '_blank')
    setTimeout(() => URL.revokeObjectURL(url), 60000)
  } catch (e) {
    tab?.close()
    alert(e.response?.status === 404 ? 'That file is no longer available.' : 'Failed to open the attachment')
  }
}

const remove = async (item) => {
  if (!confirm(`Delete "${item.title}"?`)) return

  try {
    await api.delete(`/homework/${item.id}`)
    await fetchHomework(pagination.current_page)
    if (items.value.length === 0 && pagination.current_page > 1) {
      await fetchHomework(pagination.current_page - 1)
    }
  } catch (e) {
    // 403: a teacher can only delete their own homework until its due date.
    alert(e.response?.data?.message || 'Failed to delete homework')
  }
}

// The section filter: a teacher's own sections, or every section for an admin.
const loadSections = async () => {
  try {
    if (isTeacherOnly(auth)) {
      const { data } = await api.get('/my/assignments')
      const seen = new Map()
      data.data.subjects.forEach((row) => {
        seen.set(row.section.id, { id: row.section.id, label: `${row.class?.name} – ${row.section.name}` })
      })
      sectionOptions.value = [...seen.values()]
    } else {
      const { data } = await api.get('/sections', { params: { per_page: 100 } })
      sectionOptions.value = data.data.map((s) => ({ id: s.id, label: `${s.class?.name} – ${s.name}` }))
    }
  } catch {
    sectionOptions.value = []
  }
}

onMounted(() => {
  loadSections()
  fetchHomework()
})
</script>
