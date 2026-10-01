<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">{{ isEdit ? 'Edit Exam' : 'Add Exam' }}</h1>

    <form class="card space-y-4" @submit.prevent="save">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Academic year</label>
          <select v-model="form.academic_year_id" class="input" :disabled="isEdit" required>
            <option value="" disabled>Select a year</option>
            <option v-for="year in years" :key="year.id" :value="year.id">
              {{ year.name }}<template v-if="year.is_active"> (active)</template>
            </option>
          </select>
          <p v-if="errors.academic_year_id" class="text-sm text-red-600 mt-1">{{ errors.academic_year_id[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Code (unique in the year)</label>
          <input v-model="form.code" type="text" class="input" placeholder="HY-2026" maxlength="50" required />
          <p v-if="errors.code" class="text-sm text-red-600 mt-1">{{ errors.code[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Name (English)</label>
          <input v-model="form.name_en" type="text" class="input" maxlength="255" />
          <p v-if="errors.name_en" class="text-sm text-red-600 mt-1">{{ errors.name_en[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Name (Bangla)</label>
          <input v-model="form.name_bn" type="text" class="input" maxlength="255" />
          <p v-if="errors.name_bn" class="text-sm text-red-600 mt-1">{{ errors.name_bn[0] }}</p>
        </div>
        <p class="md:col-span-2 -mt-2 text-xs text-gray-500">At least one of the two names is required.</p>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
          <select v-model="form.type" class="input" required>
            <option v-for="type in EXAM_TYPES" :key="type.value" :value="type.value">{{ type.label }}</option>
          </select>
          <p v-if="errors.type" class="text-sm text-red-600 mt-1">{{ errors.type[0] }}</p>
        </div>
        <div></div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Start date</label>
          <input v-model="form.start_date" type="date" class="input" required />
          <p v-if="errors.start_date" class="text-sm text-red-600 mt-1">{{ errors.start_date[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">End date</label>
          <input v-model="form.end_date" type="date" class="input" required />
          <p v-if="errors.end_date" class="text-sm text-red-600 mt-1">{{ errors.end_date[0] }}</p>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Classes</label>
        <p class="text-xs text-gray-500 mb-2">
          Each class's subjects are copied from its curriculum when it is added. Removing a class is refused once marks exist for it.
        </p>
        <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
          <label v-for="klass in classes" :key="klass.id" class="flex items-center space-x-2">
            <input v-model="form.class_ids" type="checkbox" :value="klass.id" />
            <span class="text-sm text-gray-700">{{ klass.name }}</span>
          </label>
        </div>
        <p v-for="message in classErrors" :key="message" class="text-sm text-red-600 mt-1">{{ message }}</p>
      </div>

      <div class="flex justify-end space-x-2">
        <router-link to="/exams" class="btn btn-secondary">Cancel</router-link>
        <button type="submit" class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving...' : 'Save' }}</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { EXAM_TYPES } from '@/constants/exams'

const route = useRoute()
const router = useRouter()

const isEdit = computed(() => !!route.params.id)
const saving = ref(false)
const errors = ref({})
const years = ref([])
const classes = ref([])

const form = reactive({
  academic_year_id: '',
  name_en: '',
  name_bn: '',
  code: '',
  type: 'half_yearly',
  start_date: '',
  end_date: '',
  class_ids: [],
})

// `class_ids` and `class_ids.N` (one class without a curriculum, say) both land here.
const classErrors = computed(() =>
  Object.entries(errors.value)
    .filter(([key]) => key === 'class_ids' || key.startsWith('class_ids.'))
    .flatMap(([, messages]) => messages)
)

const load = async () => {
  try {
    const [yearsRes, classesRes] = await Promise.all([
      api.get('/academic-years', { params: { per_page: 100 } }),
      api.get('/classes', { params: { per_page: 100 } }),
    ])
    years.value = yearsRes.data.data
    classes.value = classesRes.data.data

    if (isEdit.value) {
      const { data } = await api.get(`/exams/${route.params.id}`)
      Object.assign(form, {
        academic_year_id: data.data.academic_year_id,
        name_en: data.data.name_en || '',
        name_bn: data.data.name_bn || '',
        code: data.data.code,
        type: data.data.type,
        start_date: data.data.start_date,
        end_date: data.data.end_date,
        class_ids: data.data.class_ids,
      })
    } else {
      form.academic_year_id = years.value.find((y) => y.is_active)?.id ?? ''
    }
  } catch (error) {
    console.error('Failed to load the exam form:', error)
    alert(error.response?.data?.message || 'Failed to load the exam form')
  }
}

const save = async () => {
  errors.value = {}
  saving.value = true

  const payload = {
    name_en: form.name_en || null,
    name_bn: form.name_bn || null,
    code: form.code,
    type: form.type,
    start_date: form.start_date,
    end_date: form.end_date,
    class_ids: form.class_ids,
  }

  try {
    if (isEdit.value) {
      await api.put(`/exams/${route.params.id}`, payload)
    } else {
      await api.post('/exams', { ...payload, academic_year_id: form.academic_year_id })
    }
    router.push('/exams')
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors
    } else {
      console.error('Failed to save exam:', error)
      alert(error.response?.data?.message || 'Failed to save exam')
    }
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>
