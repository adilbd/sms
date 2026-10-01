<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Holidays</h1>
      <p class="text-sm text-gray-500">
        No attendance is taken on these dates. Weekly holidays ({{ weeklyText }}) are set under Institute settings.
      </p>
    </div>

    <div v-if="notice" :class="['rounded-lg border px-4 py-3 text-sm', notice.ok ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700']" role="status">
      {{ notice.text }}
    </div>

    <div class="card">
      <h2 class="text-lg font-semibold text-gray-900 mb-4">{{ editingId ? 'Edit holiday' : 'Add a holiday' }}</h2>
      <form class="grid grid-cols-1 gap-4 md:grid-cols-4" @submit.prevent="save">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="holiday-date">Date</label>
          <input id="holiday-date" v-model="form.date" type="date" class="input" required />
          <p v-if="errors.date" class="text-sm text-red-600 mt-1">{{ errors.date[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="holiday-name-en">Name (English)</label>
          <input id="holiday-name-en" v-model="form.name_en" type="text" maxlength="255" class="input" />
          <p v-if="errors.name_en" class="text-sm text-red-600 mt-1">{{ errors.name_en[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="holiday-name-bn">Name (Bangla)</label>
          <input id="holiday-name-bn" v-model="form.name_bn" type="text" maxlength="255" class="input" />
          <p v-if="errors.name_bn" class="text-sm text-red-600 mt-1">{{ errors.name_bn[0] }}</p>
        </div>
        <div class="flex items-end gap-2">
          <button type="submit" class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving...' : editingId ? 'Update' : 'Add' }}</button>
          <button v-if="editingId" type="button" class="btn btn-secondary" @click="reset">Cancel</button>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="mb-4 max-w-xs">
        <label class="block text-sm font-medium text-gray-700 mb-1" for="holiday-year">Academic year</label>
        <select id="holiday-year" v-model="yearId" class="input" @change="fetchHolidays">
          <option value="">All years</option>
          <option v-for="year in years" :key="year.id" :value="year.id">{{ year.name }}</option>
        </select>
      </div>

      <div v-if="loading" class="text-center py-8"><p class="text-gray-500">Loading holidays...</p></div>
      <div v-else-if="holidays.length === 0" class="text-center py-8"><p class="text-gray-500">No holidays listed</p></div>
      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Day</th>
              <th>Name</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="holiday in holidays" :key="holiday.id" class="hover:bg-gray-50">
              <td class="font-medium">{{ holiday.date }}</td>
              <td class="text-gray-500">{{ weekdayShort(holiday.date) }}</td>
              <td>
                {{ holiday.name_en || holiday.name_bn }}
                <span v-if="holiday.name_en && holiday.name_bn" class="text-gray-500">({{ holiday.name_bn }})</span>
              </td>
              <td>
                <div class="flex space-x-2">
                  <button type="button" class="text-primary-600 hover:text-primary-800" title="Edit" @click="edit(holiday)">✏️</button>
                  <button type="button" class="text-red-600 hover:text-red-800" title="Delete" @click="remove(holiday)">🗑️</button>
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
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { weekdayShort } from '@/utils/dhakaDate'

const holidays = ref([])
const years = ref([])
const yearId = ref('')
const weekly = ref(['friday'])
const loading = ref(false)
const saving = ref(false)
const notice = ref(null)
const errors = ref({})
const editingId = ref(null)
const form = reactive({ date: '', name_en: '', name_bn: '' })

const weeklyText = computed(() => weekly.value.map((day) => day.charAt(0).toUpperCase() + day.slice(1)).join(', '))

const reset = () => {
  editingId.value = null
  Object.assign(form, { date: '', name_en: '', name_bn: '' })
  errors.value = {}
}

const fetchHolidays = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/holidays', { params: { per_page: 100, academic_year_id: yearId.value || undefined } })
    holidays.value = data.data
  } catch (error) {
    notice.value = { ok: false, text: error.response?.data?.message || 'Failed to load the holidays' }
  } finally {
    loading.value = false
  }
}

const edit = (holiday) => {
  editingId.value = holiday.id
  Object.assign(form, { date: holiday.date, name_en: holiday.name_en ?? '', name_bn: holiday.name_bn ?? '' })
  errors.value = {}
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

const save = async () => {
  errors.value = {}
  notice.value = null
  saving.value = true

  try {
    const { data } = editingId.value
      ? await api.put(`/holidays/${editingId.value}`, { ...form })
      : await api.post('/holidays', { ...form })
    notice.value = { ok: true, text: data.message }
    reset()
    await fetchHolidays()
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors ?? {}
    } else {
      notice.value = { ok: false, text: error.response?.data?.message || 'Failed to save the holiday' }
    }
  } finally {
    saving.value = false
  }
}

const remove = async (holiday) => {
  if (!confirm(`Delete the holiday on ${holiday.date}?`)) return

  try {
    await api.delete(`/holidays/${holiday.id}`)
    notice.value = { ok: true, text: 'Holiday deleted' }
    if (editingId.value === holiday.id) reset()
    await fetchHolidays()
  } catch (error) {
    notice.value = { ok: false, text: error.response?.data?.message || 'Failed to delete the holiday' }
  }
}

onMounted(async () => {
  try {
    const [yearsRes, settingsRes] = await Promise.all([
      api.get('/academic-years', { params: { per_page: 100 } }),
      api.get('/settings/institute'),
    ])
    years.value = yearsRes.data.data
    weekly.value = settingsRes.data.data.weekly_holidays ?? ['friday']
    yearId.value = years.value.find((year) => year.is_active)?.id ?? ''
  } catch (error) {
    notice.value = { ok: false, text: error.response?.data?.message || 'Failed to load the page' }
  }

  await fetchHolidays()
})
</script>
