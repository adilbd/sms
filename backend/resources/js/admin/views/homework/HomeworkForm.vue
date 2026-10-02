<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">{{ isEdit ? 'Edit Homework' : 'Assign Homework' }}</h1>

    <div v-if="loading" class="card"><p class="text-gray-500 text-center py-8">Loading...</p></div>

    <form v-else class="card space-y-4" @submit.prevent="save">
      <p v-if="loadError" class="text-red-600">{{ loadError }}</p>

      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <template v-if="!isEdit">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
            <select v-model="form.section_id" class="input" required @change="form.subject_id = ''">
              <option value="" disabled>Select a section</option>
              <option v-for="section in sectionOptions" :key="section.id" :value="section.id">{{ section.label }}</option>
            </select>
            <p v-if="errors.section_id" class="text-sm text-red-600 mt-1">{{ errors.section_id[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
            <select v-model="form.subject_id" class="input" required :disabled="!form.section_id">
              <option value="" disabled>Select a subject</option>
              <option v-for="subject in subjectOptions" :key="subject.id" :value="subject.id">{{ subject.name }}</option>
            </select>
            <p v-if="teacherOnly && sectionOptions.length === 0" class="text-sm text-gray-500 mt-1">
              You have no subjects assigned for this year yet. Ask an admin to assign you in Sections.
            </p>
            <p v-if="errors.subject_id" class="text-sm text-red-600 mt-1">{{ errors.subject_id[0] }}</p>
          </div>
        </template>
        <div v-else class="md:col-span-2 text-sm text-gray-600">
          {{ homework?.section?.class?.name }} – {{ homework?.section?.name }} · {{ homework?.subject?.name }}
          <span class="text-gray-400">(the section and subject can't be changed)</span>
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
          <input v-model="form.title" type="text" class="input" maxlength="255" required />
          <p v-if="errors.title" class="text-sm text-red-600 mt-1">{{ errors.title[0] }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Assigned on</label>
          <input v-model="form.assigned_on" type="date" class="input" />
          <p v-if="errors.assigned_on" class="text-sm text-red-600 mt-1">{{ errors.assigned_on[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Due on</label>
          <input v-model="form.due_on" type="date" class="input" required />
          <p v-if="errors.due_on" class="text-sm text-red-600 mt-1">{{ errors.due_on[0] }}</p>
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Details</label>
          <RichTextEditor v-model="form.details" />
          <p v-if="errors.details" class="text-sm text-red-600 mt-1">{{ errors.details[0] }}</p>
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Attachment (PDF, JPG or PNG, up to 5 MB)</label>
          <p v-if="isEdit && homework?.has_attachment && !removeAttachment && !file" class="text-sm text-gray-600 mb-1">
            Current file: {{ homework.attachment_name }}
            <button type="button" class="text-red-600 hover:text-red-800 ml-2" @click="removeAttachment = true">Remove</button>
          </p>
          <p v-if="removeAttachment" class="text-sm text-gray-600 mb-1">
            The current file will be removed when you save.
            <button type="button" class="text-primary-600 ml-2" @click="removeAttachment = false">Undo</button>
          </p>
          <input type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" @change="onFile" />
          <p v-if="errors.attachment" class="text-sm text-red-600 mt-1">{{ errors.attachment[0] }}</p>
        </div>
      </div>

      <div class="flex justify-end space-x-2">
        <router-link to="/homework" class="btn btn-secondary">Cancel</router-link>
        <button type="submit" class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving...' : 'Save' }}</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import RichTextEditor from '@/components/RichTextEditor.vue'
import { useAuthStore } from '@/stores/auth'
import { isTeacherOnly } from '@/utils/access'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const isEdit = computed(() => !!route.params.id)
const teacherOnly = computed(() => isTeacherOnly(auth))
const loading = ref(true)
const saving = ref(false)
const loadError = ref('')
const errors = ref({})
const homework = ref(null)
const file = ref(null)
const removeAttachment = ref(false)

const form = reactive({ section_id: '', subject_id: '', title: '', assigned_on: '', due_on: '', details: '' })

// A teacher picks from the sections and subjects assigned to them; an admin from every
// section and the subjects in its class's curriculum (for the section's group).
const assignments = ref([])
const academicYearId = ref(null)
const allSections = ref([])
const curriculum = ref([])

const sectionOptions = computed(() => {
  if (teacherOnly.value) {
    const seen = new Map()
    assignments.value.forEach((row) => seen.set(row.section.id, { id: row.section.id, label: `${row.class?.name} – ${row.section.name}` }))
    return [...seen.values()]
  }
  return allSections.value.map((s) => ({ id: s.id, label: `${s.class?.name} – ${s.name}` }))
})

const subjectOptions = computed(() => {
  if (teacherOnly.value) {
    const seen = new Map()
    assignments.value
      .filter((row) => row.section.id === form.section_id)
      .forEach((row) => seen.set(row.subject.id, { id: row.subject.id, name: row.subject.name }))
    return [...seen.values()]
  }
  // A subject can appear once per group, so list each once.
  const seen = new Map()
  curriculum.value.forEach((row) => seen.set(row.subject_id, { id: row.subject_id, name: row.subject?.name }))
  return [...seen.values()]
})

// Admin: load the curriculum of the chosen section's class.
watch(() => form.section_id, async (id) => {
  if (teacherOnly.value || isEdit.value || !id) return
  const section = allSections.value.find((s) => s.id === id)
  if (!section) return
  try {
    const { data } = await api.get(`/classes/${section.class_id}/subjects`, { params: section.group ? { group: section.group } : {} })
    curriculum.value = data.data
  } catch {
    curriculum.value = []
  }
})

const onFile = (event) => {
  file.value = event.target.files[0] || null
  if (file.value) removeAttachment.value = false
}

const load = async () => {
  try {
    if (isEdit.value) {
      const { data } = await api.get(`/homework/${route.params.id}`)
      homework.value = data.data
      Object.assign(form, {
        title: data.data.title,
        assigned_on: data.data.assigned_on,
        due_on: data.data.due_on,
        details: data.data.details || '',
      })
    } else if (teacherOnly.value) {
      const { data } = await api.get('/my/assignments')
      assignments.value = data.data.subjects
      academicYearId.value = data.data.academic_year?.id ?? null
    } else {
      const { data } = await api.get('/sections', { params: { per_page: 100 } })
      allSections.value = data.data
    }
  } catch (e) {
    loadError.value = e.response?.data?.message || 'Failed to load the form'
  } finally {
    loading.value = false
  }
}

const save = async () => {
  errors.value = {}
  saving.value = true

  const payload = new FormData()
  if (isEdit.value) payload.append('_method', 'PUT')
  else {
    payload.append('section_id', form.section_id)
    payload.append('subject_id', form.subject_id)
    if (academicYearId.value) payload.append('academic_year_id', academicYearId.value)
  }
  payload.append('title', form.title)
  if (form.assigned_on) payload.append('assigned_on', form.assigned_on)
  payload.append('due_on', form.due_on)
  payload.append('details', form.details || '')
  if (file.value) payload.append('attachment', file.value)
  if (removeAttachment.value) payload.append('remove_attachment', '1')

  try {
    if (isEdit.value) await api.post(`/homework/${route.params.id}`, payload)
    else await api.post('/homework', payload)
    router.push('/homework')
  } catch (e) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors
    } else {
      // 403: not assigned to this subject, or the due date has passed.
      alert(e.response?.data?.message || 'Failed to save homework')
    }
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>
