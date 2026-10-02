<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">Admission applications</h1>
      <router-link to="/admissions/rounds" class="btn btn-secondary">Rounds</router-link>
    </div>

    <div class="flex flex-wrap gap-2">
      <button
        v-for="(label, status) in STATUS_LABELS"
        :key="status"
        type="button"
        :class="['badge cursor-pointer', STATUS_BADGES[status], filters.status === status ? 'ring-2 ring-primary-500' : '']"
        @click="toggleStatus(status)"
      >
        {{ label }}: {{ counts[status] ?? 0 }}
      </button>
    </div>

    <div class="card">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <input v-model="filters.search" type="text" placeholder="Name, application no., mobile, birth reg..." class="input" @input="onSearch" />
        <select v-model="filters.round_id" class="input" @change="reload">
          <option value="">All rounds</option>
          <option v-for="round in rounds" :key="round.id" :value="round.id">{{ round.name_bn || round.name_en }}</option>
        </select>
        <select v-model="filters.class_id" class="input" @change="reload">
          <option value="">All classes</option>
          <option v-for="cls in classes" :key="cls.id" :value="cls.id">{{ cls.name }}</option>
        </select>
        <select v-model="filters.status" class="input" @change="reload">
          <option value="">All statuses</option>
          <option v-for="(label, status) in STATUS_LABELS" :key="status" :value="status">{{ label }}</option>
        </select>
      </div>
    </div>

    <div class="card">
      <div v-if="loading" class="text-center py-8 text-gray-500">Loading applications...</div>
      <div v-else-if="applications.length === 0" class="text-center py-8 text-gray-500">No applications found</div>
      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr><th>No.</th><th>Student</th><th>Class</th><th>Round</th><th>Submitted</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="app in applications" :key="app.id" class="hover:bg-gray-50">
              <td class="font-mono text-sm">{{ app.application_no }}</td>
              <td class="font-medium">{{ app.name_bn || app.name_en }}</td>
              <td>{{ app.class?.name }}<span v-if="app.group" class="text-gray-500"> · {{ GROUP_LABELS[app.group] }}</span></td>
              <td class="text-gray-600">{{ app.round?.name_bn || app.round?.name_en }}</td>
              <td class="text-gray-600">{{ app.created_at?.slice(0, 10) }}</td>
              <td><span :class="['badge', STATUS_BADGES[app.status]]">{{ STATUS_LABELS[app.status] }}</span></td>
              <td><router-link :to="`/admissions/applications/${app.id}`" class="text-primary-600 hover:underline">Open</router-link></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="pagination && pagination.last_page > 1" class="flex justify-between items-center mt-4">
        <button class="btn btn-secondary" :disabled="pagination.current_page <= 1" @click="goTo(pagination.current_page - 1)">Previous</button>
        <span class="text-sm text-gray-600">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
        <button class="btn btn-secondary" :disabled="pagination.current_page >= pagination.last_page" @click="goTo(pagination.current_page + 1)">Next</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { GROUP_LABELS } from '@/constants/academic'
import { STATUS_BADGES, STATUS_LABELS } from '@/constants/admissions'

const route = useRoute()
const applications = ref([])
const pagination = ref(null)
const counts = ref({})
const rounds = ref([])
const classes = ref([])
const loading = ref(false)
const page = ref(1)

const filters = reactive({ search: '', round_id: route.query.round_id || '', class_id: '', status: '' })

const params = () => Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))

const fetchApplications = async () => {
  loading.value = true
  try {
    const [list, stats] = await Promise.all([
      api.get('/admission-applications', { params: { ...params(), page: page.value, per_page: 20 } }),
      api.get('/admission-applications/counts', { params: { ...params(), status: undefined } }),
    ])
    applications.value = list.data.data
    pagination.value = list.data.meta
    counts.value = stats.data.data
  } catch (error) {
    console.error('Failed to fetch applications:', error)
  } finally {
    loading.value = false
  }
}

const reload = () => {
  page.value = 1
  fetchApplications()
}

const goTo = (target) => {
  page.value = target
  fetchApplications()
}

const toggleStatus = (status) => {
  filters.status = filters.status === status ? '' : status
  reload()
}

let searchTimeout = null
const onSearch = () => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(reload, 300)
}

onMounted(async () => {
  const [roundsRes, classesRes] = await Promise.all([
    api.get('/admission-rounds', { params: { per_page: 100 } }),
    api.get('/classes', { params: { per_page: 100 } }),
  ])
  rounds.value = roundsRes.data.data
  classes.value = classesRes.data.data
  fetchApplications()
})
</script>
