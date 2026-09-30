<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">{{ isEdit ? 'Edit Class' : 'Add Class' }}</h1>

    <form class="card space-y-4" @submit.prevent="save">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Number</label>
          <select v-model.number="form.number" class="input" required>
            <option v-for="number in availableNumbers" :key="number" :value="number">Class {{ number }}</option>
          </select>
          <p v-if="errors.number" class="text-sm text-red-600 mt-1">{{ errors.number[0] }}</p>
        </div>
        <div></div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
          <input v-model="form.name" type="text" class="input" required />
          <p v-if="errors.name" class="text-sm text-red-600 mt-1">{{ errors.name[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Name (Bangla)</label>
          <input v-model="form.name_bn" type="text" class="input" />
          <p v-if="errors.name_bn" class="text-sm text-red-600 mt-1">{{ errors.name_bn[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Code</label>
          <input v-model="form.code" type="text" class="input" required />
          <p v-if="errors.code" class="text-sm text-red-600 mt-1">{{ errors.code[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Display order</label>
          <input v-model.number="form.display_order" type="number" class="input" />
          <p v-if="errors.display_order" class="text-sm text-red-600 mt-1">{{ errors.display_order[0] }}</p>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
        <textarea v-model="form.description" class="input" rows="3"></textarea>
      </div>

      <label class="flex items-center space-x-2">
        <input v-model="form.is_active" type="checkbox" />
        <span class="text-sm text-gray-700">Active</span>
      </label>

      <div class="flex justify-end space-x-2">
        <router-link to="/classes" class="btn btn-secondary">Cancel</router-link>
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
const usedNumbers = ref([])

const form = reactive({
  number: null,
  name: '',
  name_bn: '',
  code: '',
  description: '',
  display_order: 0,
  is_active: true,
})

// Creating a class is only possible when a number from 1 to 12 is free.
const availableNumbers = computed(() => {
  const all = Array.from({ length: 12 }, (_, i) => i + 1)
  return all.filter((number) => number === form.number || !usedNumbers.value.includes(number))
})

const fetchExistingNumbers = async () => {
  try {
    const { data } = await api.get('/classes', { params: { per_page: 100 } })
    usedNumbers.value = data.data.map((cls) => cls.number).filter((n) => n !== form.number)
  } catch (error) {
    console.error('Failed to fetch classes:', error)
  }
}

const fetchClass = async () => {
  try {
    const { data } = await api.get(`/classes/${route.params.id}`)
    Object.assign(form, {
      number: data.data.number,
      name: data.data.name,
      name_bn: data.data.name_bn || '',
      code: data.data.code,
      description: data.data.description || '',
      display_order: data.data.display_order,
      is_active: data.data.is_active,
    })
  } catch (error) {
    console.error('Failed to fetch class:', error)
  }
}

const save = async () => {
  errors.value = {}
  saving.value = true

  const payload = { ...form }

  try {
    if (isEdit.value) {
      await api.put(`/classes/${route.params.id}`, payload)
    } else {
      await api.post('/classes', payload)
    }
    router.push('/classes')
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors
    } else {
      alert(error.response?.data?.message || 'Failed to save class')
    }
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await fetchExistingNumbers()
  if (isEdit.value) await fetchClass()
})
</script>
