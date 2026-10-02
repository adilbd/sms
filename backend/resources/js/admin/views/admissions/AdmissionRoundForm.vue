<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">{{ isEdit ? 'Edit admission round' : 'Add admission round' }}</h1>

    <form class="card space-y-4" @submit.prevent="save">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Name (English)</label>
          <input v-model="form.name_en" type="text" class="input" />
          <p v-if="errors.name_en" class="text-sm text-red-600 mt-1">{{ errors.name_en[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Name (Bangla)</label>
          <input v-model="form.name_bn" type="text" class="input" />
          <p v-if="errors.name_bn" class="text-sm text-red-600 mt-1">{{ errors.name_bn[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Academic year</label>
          <select v-model.number="form.academic_year_id" class="input" required>
            <option value="" disabled>Select year</option>
            <option v-for="year in years" :key="year.id" :value="year.id">{{ year.year }}</option>
          </select>
          <p v-if="errors.academic_year_id" class="text-sm text-red-600 mt-1">{{ errors.academic_year_id[0] }}</p>
        </div>
        <div></div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Opens on</label>
          <input v-model="form.opens_at" type="date" class="input" required />
          <p v-if="errors.opens_at" class="text-sm text-red-600 mt-1">{{ errors.opens_at[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Closes on (inclusive)</label>
          <input v-model="form.closes_at" type="date" class="input" required />
          <p v-if="errors.closes_at" class="text-sm text-red-600 mt-1">{{ errors.closes_at[0] }}</p>
        </div>
      </div>

      <div>
        <div class="flex items-center justify-between mb-2">
          <label class="block text-sm font-medium text-gray-700">Classes and seats (leave seats empty for no limit)</label>
          <button type="button" class="btn btn-secondary" @click="addClass">➕ Add class</button>
        </div>
        <p v-if="errors.classes" class="text-sm text-red-600 mb-2">{{ errors.classes[0] }}</p>
        <div v-for="(row, index) in form.classes" :key="index" class="flex gap-3 mb-2 items-start">
          <div class="flex-1">
            <select v-model.number="row.class_id" class="input" required>
              <option value="" disabled>Select class</option>
              <option v-for="cls in classes" :key="cls.id" :value="cls.id">{{ cls.name }}</option>
            </select>
            <p v-if="errors[`classes.${index}.class_id`]" class="text-sm text-red-600 mt-1">{{ errors[`classes.${index}.class_id`][0] }}</p>
          </div>
          <div class="w-40">
            <input v-model="row.seats" type="number" min="1" class="input" placeholder="Seats" />
            <p v-if="errors[`classes.${index}.seats`]" class="text-sm text-red-600 mt-1">{{ errors[`classes.${index}.seats`][0] }}</p>
          </div>
          <button type="button" class="text-red-600 mt-2" title="Remove" @click="form.classes.splice(index, 1)">🗑️</button>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Instructions (Bangla)</label>
        <RichTextEditor v-model="form.instructions_bn" />
        <p v-if="errors.instructions_bn" class="text-sm text-red-600 mt-1">{{ errors.instructions_bn[0] }}</p>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Instructions (English)</label>
        <RichTextEditor v-model="form.instructions_en" />
        <p v-if="errors.instructions_en" class="text-sm text-red-600 mt-1">{{ errors.instructions_en[0] }}</p>
      </div>

      <label class="flex items-center space-x-2">
        <input v-model="form.is_published" type="checkbox" />
        <span class="text-sm text-gray-700">Published (families can apply between the opening and closing dates)</span>
      </label>

      <div class="flex justify-end space-x-2">
        <router-link to="/admissions/rounds" class="btn btn-secondary">Cancel</router-link>
        <button type="submit" class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving...' : 'Save' }}</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import RichTextEditor from '@/components/RichTextEditor.vue'

const route = useRoute()
const router = useRouter()

const isEdit = computed(() => !!route.params.id)
const saving = ref(false)
const errors = ref({})
const years = ref([])
const classes = ref([])

const form = reactive({
  name_en: '',
  name_bn: '',
  academic_year_id: '',
  opens_at: '',
  closes_at: '',
  is_published: false,
  instructions_bn: '',
  instructions_en: '',
  classes: [],
})

const addClass = () => form.classes.push({ class_id: '', seats: '' })

const load = async () => {
  const [yearsRes, classesRes] = await Promise.all([
    api.get('/academic-years', { params: { per_page: 100 } }),
    api.get('/classes', { params: { per_page: 100 } }),
  ])
  years.value = yearsRes.data.data
  classes.value = classesRes.data.data

  if (isEdit.value) {
    const { data } = await api.get(`/admission-rounds/${route.params.id}`)
    const round = data.data
    Object.assign(form, {
      name_en: round.name_en || '',
      name_bn: round.name_bn || '',
      academic_year_id: round.academic_year_id,
      opens_at: round.opens_at,
      closes_at: round.closes_at,
      is_published: round.is_published,
      instructions_bn: round.instructions_bn || '',
      instructions_en: round.instructions_en || '',
      classes: round.classes.map((row) => ({ class_id: row.class_id, seats: row.seats ?? '' })),
    })
  } else {
    addClass()
  }
}

const save = async () => {
  errors.value = {}
  saving.value = true

  const payload = {
    name_en: form.name_en || null,
    name_bn: form.name_bn || null,
    academic_year_id: form.academic_year_id,
    opens_at: form.opens_at,
    closes_at: form.closes_at,
    is_published: form.is_published,
    instructions_bn: form.instructions_bn || null,
    instructions_en: form.instructions_en || null,
    classes: form.classes.map((row) => ({ class_id: row.class_id, seats: row.seats === '' ? null : Number(row.seats) })),
  }

  try {
    if (isEdit.value) {
      await api.put(`/admission-rounds/${route.params.id}`, payload)
    } else {
      await api.post('/admission-rounds', payload)
    }
    router.push('/admissions/rounds')
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors
    } else {
      alert(error.response?.data?.message || 'Failed to save the round')
    }
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>
