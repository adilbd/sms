<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">{{ isEdit ? 'Edit Academic Year' : 'Add Academic Year' }}</h1>

    <form class="card space-y-4" @submit.prevent="save">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Year</label>
          <input v-model.number="form.year" type="number" min="2000" max="2100" class="input" required />
          <p v-if="errors.year" class="text-sm text-red-600 mt-1">{{ errors.year[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
          <input v-model="form.name" type="text" class="input" required />
          <p v-if="errors.name" class="text-sm text-red-600 mt-1">{{ errors.name[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Code</label>
          <input v-model="form.code" type="text" class="input" :placeholder="String(form.year || '')" />
          <p v-if="errors.code" class="text-sm text-red-600 mt-1">{{ errors.code[0] }}</p>
        </div>
        <div></div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Start date</label>
          <input v-model="form.start_date" type="date" class="input" />
          <p v-if="errors.start_date" class="text-sm text-red-600 mt-1">{{ errors.start_date[0] }}</p>
          <p class="text-xs text-gray-500 mt-1">Defaults to January 1st of the year.</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">End date</label>
          <input v-model="form.end_date" type="date" class="input" />
          <p v-if="errors.end_date" class="text-sm text-red-600 mt-1">{{ errors.end_date[0] }}</p>
          <p class="text-xs text-gray-500 mt-1">Defaults to December 31st of the year.</p>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
        <textarea v-model="form.description" class="input" rows="3"></textarea>
      </div>

      <div class="flex justify-end space-x-2">
        <router-link to="/academic-years" class="btn btn-secondary">Cancel</router-link>
        <button type="submit" class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving...' : 'Save' }}</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'

const route = useRoute()
const router = useRouter()

const isEdit = computed(() => !!route.params.id)
const saving = ref(false)
const errors = ref({})

const form = reactive({
  year: null,
  name: '',
  code: '',
  start_date: '',
  end_date: '',
  description: '',
})

const fetchYear = async () => {
  try {
    const { data } = await api.get(`/academic-years/${route.params.id}`)
    Object.assign(form, {
      year: data.data.year,
      name: data.data.name,
      code: data.data.code,
      start_date: data.data.start_date || '',
      end_date: data.data.end_date || '',
      description: data.data.description || '',
    })
  } catch (error) {
    console.error('Failed to fetch academic year:', error)
  }
}

const save = async () => {
  errors.value = {}
  saving.value = true

  const payload = {
    year: form.year,
    name: form.name,
    description: form.description || null,
  }
  // Omitted when blank: the server defaults them on create and keeps the saved
  // values on update (they're NOT NULL, so an explicit null is rejected).
  if (form.code) payload.code = form.code
  if (form.start_date) payload.start_date = form.start_date
  if (form.end_date) payload.end_date = form.end_date

  try {
    if (isEdit.value) {
      await api.put(`/academic-years/${route.params.id}`, payload)
    } else {
      await api.post('/academic-years', payload)
    }
    router.push('/academic-years')
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors
    } else {
      alert(error.response?.data?.message || 'Failed to save academic year')
    }
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  if (isEdit.value) fetchYear()
})
</script>
