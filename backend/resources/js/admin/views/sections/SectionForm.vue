<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">{{ isEdit ? 'Edit Section' : 'Add Section' }}</h1>

    <form class="card space-y-4" @submit.prevent="save">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Class</label>
          <select v-model.number="form.class_id" class="input" required :disabled="isEdit">
            <option value="" disabled>Select a class</option>
            <option v-for="cls in classes" :key="cls.id" :value="cls.id">{{ cls.name }}</option>
          </select>
          <p v-if="errors.class_id" class="text-sm text-red-600 mt-1">{{ errors.class_id[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Shift</label>
          <select v-model.number="form.shift_id" class="input" required>
            <option value="" disabled>Select a shift</option>
            <option v-for="shift in shifts" :key="shift.id" :value="shift.id">{{ shift.name_en }}</option>
          </select>
          <p v-if="errors.shift_id" class="text-sm text-red-600 mt-1">{{ errors.shift_id[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
          <input v-model="form.name" type="text" class="input" required />
          <p v-if="errors.name" class="text-sm text-red-600 mt-1">{{ errors.name[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Code</label>
          <input v-model="form.code" type="text" class="input" required />
          <p v-if="errors.code" class="text-sm text-red-600 mt-1">{{ errors.code[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Capacity</label>
          <input v-model.number="form.capacity" type="number" min="1" max="200" class="input" />
          <p v-if="errors.capacity" class="text-sm text-red-600 mt-1">{{ errors.capacity[0] }}</p>
        </div>
        <div v-if="showGroup">
          <label class="block text-sm font-medium text-gray-700 mb-1">Group</label>
          <select v-model="form.group" class="input">
            <option value="">No group</option>
            <option v-for="group in GROUPS" :key="group.value" :value="group.value">{{ group.label }}</option>
          </select>
          <p v-if="errors.group" class="text-sm text-red-600 mt-1">{{ errors.group[0] }}</p>
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
        <router-link to="/sections" class="btn btn-secondary">Cancel</router-link>
        <button type="submit" class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving...' : 'Save' }}</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { GROUPS, GROUPS_FROM_NUMBER } from '@/constants/academic'

const route = useRoute()
const router = useRouter()

const isEdit = computed(() => !!route.params.id)
const saving = ref(false)
const errors = ref({})
const classes = ref([])
const shifts = ref([])

const form = reactive({
  class_id: '',
  shift_id: '',
  name: '',
  code: '',
  capacity: 40,
  group: '',
  description: '',
  is_active: true,
})

const showGroup = computed(() => {
  const cls = classes.value.find((c) => c.id === form.class_id)
  return (cls?.number || 0) >= GROUPS_FROM_NUMBER
})

const fetchLookups = async () => {
  const [classesRes, shiftsRes] = await Promise.all([
    api.get('/classes', { params: { per_page: 100 } }),
    api.get('/shifts', { params: { per_page: 100, is_active: true } }),
  ])
  classes.value = classesRes.data.data
  shifts.value = shiftsRes.data.data
}

const fetchSection = async () => {
  try {
    const { data } = await api.get(`/sections/${route.params.id}`)
    Object.assign(form, {
      class_id: data.data.class_id,
      shift_id: data.data.shift_id,
      name: data.data.name,
      code: data.data.code,
      capacity: data.data.capacity,
      group: data.data.group || '',
      description: data.data.description || '',
      is_active: data.data.is_active,
    })
  } catch (error) {
    console.error('Failed to fetch section:', error)
  }
}

const save = async () => {
  errors.value = {}
  saving.value = true

  const payload = { ...form, group: form.group || null }
  if (isEdit.value) delete payload.class_id

  try {
    if (isEdit.value) {
      await api.put(`/sections/${route.params.id}`, payload)
    } else {
      await api.post('/sections', payload)
    }
    router.push('/sections')
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors
    } else {
      alert(error.response?.data?.message || 'Failed to save section')
    }
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await fetchLookups()
  if (isEdit.value) await fetchSection()
})
</script>
