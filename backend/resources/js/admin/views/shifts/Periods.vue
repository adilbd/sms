<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Periods<span v-if="shift"> – {{ shift.name_en }}</span></h1>
        <p class="text-sm text-gray-500">
          The bell schedule of this shift (Asia/Dhaka local time). Periods in one shift can't overlap; mark tiffin and other breaks as breaks so no class is placed in them.
        </p>
      </div>
      <router-link to="/shifts" class="btn btn-secondary">Back to shifts</router-link>
    </div>

    <div class="card">
      <p v-if="loading" class="text-center text-gray-500 py-8">Loading periods...</p>
      <p v-else-if="periods.length === 0" class="text-center text-gray-500 py-8">No periods yet. Add the first one below.</p>
      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>#</th>
              <th>Name</th>
              <th>Time</th>
              <th>Type</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="period in periods" :key="period.id" class="hover:bg-gray-50">
              <td class="font-medium">{{ period.number }}</td>
              <td>{{ period.name_bn }} <span class="text-gray-500">({{ period.name_en }})</span></td>
              <td>{{ period.start_time }} – {{ period.end_time }}</td>
              <td><span :class="['badge', period.is_break ? 'badge-warning' : 'badge-success']">{{ period.is_break ? 'Break' : 'Class' }}</span></td>
              <td>
                <div class="flex space-x-2">
                  <button type="button" class="text-primary-600 hover:text-primary-800" title="Edit" @click="edit(period)">✏️</button>
                  <button type="button" class="text-red-600 hover:text-red-800" title="Delete" @click="remove(period)">🗑️</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <form class="card space-y-4" @submit.prevent="save">
      <h2 class="text-lg font-semibold text-gray-900">{{ form.id ? `Edit period ${form.number}` : 'Add a period' }}</h2>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Number</label>
          <input v-model.number="form.number" type="number" min="1" max="99" class="input" required />
          <p v-for="m in errors.number" :key="m" class="text-sm text-red-600 mt-1">{{ m }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Name (Bangla)</label>
          <input v-model="form.name_bn" type="text" class="input" placeholder="১ম পিরিয়ড" required />
          <p v-for="m in errors.name_bn" :key="m" class="text-sm text-red-600 mt-1">{{ m }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Name (English)</label>
          <input v-model="form.name_en" type="text" class="input" placeholder="1st period" required />
          <p v-for="m in errors.name_en" :key="m" class="text-sm text-red-600 mt-1">{{ m }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Starts</label>
          <input v-model="form.start_time" type="time" class="input" required />
          <p v-for="m in errors.start_time" :key="m" class="text-sm text-red-600 mt-1">{{ m }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Ends</label>
          <input v-model="form.end_time" type="time" class="input" required />
          <p v-for="m in errors.end_time" :key="m" class="text-sm text-red-600 mt-1">{{ m }}</p>
        </div>
        <div class="flex items-end">
          <label class="inline-flex items-center gap-2 text-sm">
            <input v-model="form.is_break" type="checkbox" />
            This is a break (no class)
          </label>
          <p v-for="m in errors.is_break" :key="m" class="text-sm text-red-600 mt-1 ml-2">{{ m }}</p>
        </div>
      </div>

      <div class="flex justify-end gap-2">
        <button v-if="form.id" type="button" class="btn btn-secondary" @click="reset">Cancel</button>
        <button type="submit" class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving...' : (form.id ? 'Update period' : 'Add period') }}</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'

const route = useRoute()

const shift = ref(null)
const periods = ref([])
const loading = ref(false)
const saving = ref(false)
const errors = ref({})

const blank = () => ({
  id: null,
  number: Math.max(0, ...periods.value.map((p) => p.number)) + 1,
  name_bn: '',
  name_en: '',
  start_time: periods.value.length ? periods.value[periods.value.length - 1].end_time : '',
  end_time: '',
  is_break: false,
})

const form = reactive(blank())

const reset = () => {
  Object.assign(form, blank())
  errors.value = {}
}

const edit = (period) => {
  errors.value = {}
  Object.assign(form, { id: period.id, number: period.number, name_bn: period.name_bn, name_en: period.name_en, start_time: period.start_time, end_time: period.end_time, is_break: period.is_break })
}

const load = async () => {
  loading.value = true
  try {
    const [shiftRes, periodsRes] = await Promise.all([
      api.get(`/shifts/${route.params.id}`),
      api.get('/periods', { params: { shift_id: route.params.id, per_page: 100 } }),
    ])
    shift.value = shiftRes.data.data
    periods.value = periodsRes.data.data
    reset()
  } catch (error) {
    console.error('Failed to load periods:', error)
    alert(error.response?.data?.message || 'Failed to load periods')
  } finally {
    loading.value = false
  }
}

const save = async () => {
  errors.value = {}
  saving.value = true
  try {
    const payload = { number: form.number, name_bn: form.name_bn, name_en: form.name_en, start_time: form.start_time, end_time: form.end_time, is_break: form.is_break }
    if (form.id) {
      await api.put(`/periods/${form.id}`, payload)
    } else {
      await api.post('/periods', { ...payload, shift_id: Number(route.params.id) })
    }
    await load()
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors ?? {}
    } else {
      alert(error.response?.data?.message || 'Failed to save the period')
    }
  } finally {
    saving.value = false
  }
}

const remove = async (period) => {
  if (!confirm(`Delete period ${period.number}?`)) return

  try {
    await api.delete(`/periods/${period.id}`)
    await load()
  } catch (error) {
    alert(error.response?.data?.message || 'Failed to delete the period')
  }
}

onMounted(load)
</script>
