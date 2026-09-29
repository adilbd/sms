<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">{{ isEdit ? 'Edit Shift' : 'Add Shift' }}</h1>

    <form class="card space-y-4" @submit.prevent="save">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Name (English)</label>
          <input v-model="form.name_en" type="text" class="input" required />
          <p v-if="errors.name_en" class="text-sm text-red-600 mt-1">{{ errors.name_en[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Name (Bangla)</label>
          <input v-model="form.name_bn" type="text" class="input" required />
          <p v-if="errors.name_bn" class="text-sm text-red-600 mt-1">{{ errors.name_bn[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Slug</label>
          <input v-model="form.slug" type="text" class="input" placeholder="morning" required />
          <p v-if="errors.slug" class="text-sm text-red-600 mt-1">{{ errors.slug[0] }}</p>
        </div>
        <div></div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Start time</label>
          <input v-model="form.start_time" type="time" class="input" />
          <p v-if="errors.start_time" class="text-sm text-red-600 mt-1">{{ errors.start_time[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">End time</label>
          <input v-model="form.end_time" type="time" class="input" />
          <p v-if="errors.end_time" class="text-sm text-red-600 mt-1">{{ errors.end_time[0] }}</p>
        </div>
      </div>

      <label class="flex items-center space-x-2">
        <input v-model="form.is_active" type="checkbox" />
        <span class="text-sm text-gray-700">Active</span>
      </label>

      <div class="flex justify-end space-x-2">
        <router-link to="/shifts" class="btn btn-secondary">Cancel</router-link>
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
  name_en: '',
  name_bn: '',
  slug: '',
  start_time: '',
  end_time: '',
  is_active: true,
})

const fetchShift = async () => {
  try {
    const { data } = await api.get(`/shifts/${route.params.id}`)
    Object.assign(form, {
      name_en: data.data.name_en,
      name_bn: data.data.name_bn,
      slug: data.data.slug,
      start_time: data.data.start_time || '',
      end_time: data.data.end_time || '',
      is_active: data.data.is_active,
    })
  } catch (error) {
    console.error('Failed to fetch shift:', error)
  }
}

const save = async () => {
  errors.value = {}
  saving.value = true

  const payload = {
    name_en: form.name_en,
    name_bn: form.name_bn,
    slug: form.slug,
    start_time: form.start_time || null,
    end_time: form.end_time || null,
    is_active: form.is_active,
  }

  try {
    if (isEdit.value) {
      await api.put(`/shifts/${route.params.id}`, payload)
    } else {
      await api.post('/shifts', payload)
    }
    router.push('/shifts')
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors
    } else {
      console.error('Failed to save shift:', error)
      alert(error.response?.data?.message || 'Failed to save shift')
    }
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  if (isEdit.value) fetchShift()
})
</script>
