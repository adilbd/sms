<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-gray-900">Fee dues</h1>
      <router-link v-if="authStore.hasPermission('collect-fees')" to="/fees/collect" class="btn btn-primary">Collect fee</router-link>
    </div>

    <div class="card">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
        <select v-model="filters.academic_year_id" class="input" aria-label="Academic year" @change="fetchDues(1)">
          <option v-for="year in years" :key="year.id" :value="year.id">{{ year.name }}{{ year.is_active ? ' (current)' : '' }}</option>
        </select>
        <select v-model="filters.class_id" class="input" aria-label="Class" @change="onClassChange">
          <option value="">All classes</option>
          <option v-for="cls in classes" :key="cls.id" :value="cls.id">{{ cls.name }}</option>
        </select>
        <select v-model="filters.section_id" class="input" aria-label="Section" @change="fetchDues(1)">
          <option value="">All sections</option>
          <option v-for="section in sectionOptions" :key="section.id" :value="section.id">{{ section.class?.name }} - {{ section.name }}</option>
        </select>
        <input v-model="filters.month" type="month" class="input" aria-label="Month" @change="fetchDues(1)" />
        <select v-model="filters.status" class="input" aria-label="Status" @change="fetchDues(1)">
          <option value="">All statuses</option>
          <option value="unpaid">Unpaid</option>
          <option value="partial">Partly paid</option>
          <option value="paid">Paid</option>
          <option value="waived">Waived</option>
        </select>
        <select v-model="filters.fee_head_id" class="input" aria-label="Fee head" @change="fetchDues(1)">
          <option value="">All fee heads</option>
          <option v-for="head in heads" :key="head.id" :value="head.id">{{ head.name_en || head.name_bn }}</option>
        </select>
      </div>
    </div>

    <div v-if="notice" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="status">{{ notice }}</div>

    <div class="card">
      <div v-if="loading" class="text-center py-8"><p class="text-gray-500">Loading dues...</p></div>
      <div v-else-if="dues.length === 0" class="text-center py-8"><p class="text-gray-500">No dues found</p></div>
      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Student</th>
              <th>Class</th>
              <th>Fee</th>
              <th>Period</th>
              <th>Due date</th>
              <th class="text-right">Net</th>
              <th class="text-right">Paid</th>
              <th class="text-right">Outstanding</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="due in dues" :key="due.id" class="hover:bg-gray-50">
              <td>
                <router-link v-if="authStore.hasPermission('collect-fees') && outstanding(due)" :to="`/fees/collect?student_id=${due.student_id}`" class="text-primary-600 hover:underline">
                  {{ due.student?.name_en || due.student?.name_bn }}
                </router-link>
                <span v-else>{{ due.student?.name_en || due.student?.name_bn }}</span>
                <span class="text-gray-500 text-xs block">{{ due.student?.student_id }}</span>
              </td>
              <td>{{ due.enrolment?.class?.name }} {{ due.enrolment?.section?.name }}</td>
              <td>{{ due.head?.name_en || due.head?.name_bn }}</td>
              <td>{{ periodLabel(due.period) }}</td>
              <td>{{ due.due_date }}</td>
              <td class="text-right">{{ taka(due.net_amount) }}</td>
              <td class="text-right">{{ taka(due.paid_amount) }}</td>
              <td class="text-right font-medium">{{ taka(due.outstanding_amount) }}</td>
              <td><span :class="['badge capitalize', DUE_STATUS_BADGES[due.status]]">{{ due.status }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="pagination.last_page > 1" class="mt-4 flex items-center justify-between text-sm text-gray-600">
        <span>Page {{ pagination.current_page }} of {{ pagination.last_page }} ({{ pagination.total }} dues)</span>
        <div class="flex gap-2">
          <button type="button" class="btn btn-secondary" :disabled="pagination.current_page <= 1" @click="fetchDues(pagination.current_page - 1)">Previous</button>
          <button type="button" class="btn btn-secondary" :disabled="pagination.current_page >= pagination.last_page" @click="fetchDues(pagination.current_page + 1)">Next</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { DUE_STATUS_BADGES } from '@/constants/fees'
import { taka, toPaisa } from '@/utils/money'
import { periodLabel } from '@/utils/fees'

const authStore = useAuthStore()

const dues = ref([])
const years = ref([])
const classes = ref([])
const sections = ref([])
const heads = ref([])
const loading = ref(false)
const notice = ref('')
const pagination = ref({ current_page: 1, last_page: 1, total: 0 })
const filters = reactive({ academic_year_id: '', class_id: '', section_id: '', month: '', status: '', fee_head_id: '' })

const sectionOptions = computed(() => sections.value.filter((section) => !filters.class_id || section.class_id === filters.class_id))
const outstanding = (due) => toPaisa(due.outstanding_amount) > 0

const onClassChange = () => {
  filters.section_id = ''
  fetchDues(1)
}

const fetchDues = async (page = 1) => {
  loading.value = true
  notice.value = ''
  try {
    const params = { page, per_page: 20 }
    for (const [key, value] of Object.entries(filters)) {
      if (value !== '' && value !== null) params[key] = value
    }
    const { data } = await api.get('/fee-dues', { params })
    dues.value = data.data
    pagination.value = data.meta
  } catch (error) {
    notice.value = error.response?.data?.message || 'Failed to load the dues'
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  try {
    const [yearsRes, classesRes, sectionsRes, headsRes] = await Promise.all([
      api.get('/academic-years', { params: { per_page: 100 } }),
      api.get('/classes', { params: { per_page: 100 } }),
      api.get('/sections', { params: { per_page: 100 } }),
      api.get('/fee-heads', { params: { per_page: 100 } }),
    ])
    years.value = yearsRes.data.data
    classes.value = classesRes.data.data
    sections.value = sectionsRes.data.data
    heads.value = headsRes.data.data
    filters.academic_year_id = years.value.find((year) => year.is_active)?.id ?? ''
  } catch (error) {
    notice.value = error.response?.data?.message || 'Failed to load the filters'
  }

  await fetchDues(1)
})
</script>
