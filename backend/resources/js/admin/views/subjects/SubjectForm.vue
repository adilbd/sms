<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">{{ isEdit ? 'Edit Subject' : 'Add Subject' }}</h1>

    <form class="card space-y-4" @submit.prevent="save">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Name (English)</label>
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
          <input v-model="form.code" type="text" class="input" placeholder="MATH" required />
          <p v-if="errors.code" class="text-sm text-red-600 mt-1">{{ errors.code[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
          <select v-model="form.type" class="input">
            <option value="theory">Theory</option>
            <option value="practical">Practical</option>
            <option value="both">Theory and practical</option>
          </select>
          <p v-if="errors.type" class="text-sm text-red-600 mt-1">{{ errors.type[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Total marks</label>
          <input v-model.number="form.total_marks" type="number" min="1" max="1000" class="input" required />
          <p v-if="errors.total_marks" class="text-sm text-red-600 mt-1">{{ errors.total_marks[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Pass marks</label>
          <input v-model.number="form.pass_marks" type="number" min="0" max="1000" class="input" required />
          <p v-if="errors.pass_marks" class="text-sm text-red-600 mt-1">{{ errors.pass_marks[0] }}</p>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
        <textarea v-model="form.description" rows="3" class="input"></textarea>
        <p v-if="errors.description" class="text-sm text-red-600 mt-1">{{ errors.description[0] }}</p>
      </div>

      <label class="flex items-center space-x-2">
        <input v-model="form.is_active" type="checkbox" />
        <span class="text-sm text-gray-700">Active (only active subjects can be added to a curriculum)</span>
      </label>

      <div class="flex justify-end space-x-2">
        <router-link to="/subjects" class="btn btn-secondary">Cancel</router-link>
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
  name: '',
  name_bn: '',
  code: '',
  type: 'theory',
  total_marks: 100,
  pass_marks: 33,
  description: '',
  is_active: true,
})

const fetchSubject = async () => {
  try {
    const { data } = await api.get(`/subjects/${route.params.id}`)
    Object.assign(form, {
      name: data.data.name,
      name_bn: data.data.name_bn || '',
      code: data.data.code,
      type: data.data.type,
      total_marks: data.data.total_marks,
      pass_marks: data.data.pass_marks,
      description: data.data.description || '',
      is_active: data.data.is_active,
    })
  } catch (error) {
    console.error('Failed to fetch subject:', error)
  }
}

const save = async () => {
  errors.value = {}
  saving.value = true

  const payload = {
    name: form.name,
    name_bn: form.name_bn || null,
    code: form.code,
    type: form.type,
    total_marks: form.total_marks,
    pass_marks: form.pass_marks,
    description: form.description || null,
    is_active: form.is_active,
  }

  try {
    if (isEdit.value) {
      await api.put(`/subjects/${route.params.id}`, payload)
    } else {
      await api.post('/subjects', payload)
    }
    router.push('/subjects')
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors
    } else {
      console.error('Failed to save subject:', error)
      alert(error.response?.data?.message || 'Failed to save subject')
    }
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  if (isEdit.value) fetchSubject()
})
</script>
