<template>
  <div class="space-y-6">
    <div v-if="loading" class="text-center py-8 text-gray-500">Loading application...</div>
    <div v-else-if="loadError" class="card text-red-600">{{ loadError }}</div>
    <template v-else-if="app">
      <div class="flex flex-wrap justify-between items-center gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">{{ app.name_bn || app.name_en }}</h1>
          <p class="text-gray-600 font-mono text-sm">{{ app.application_no }}</p>
        </div>
        <div class="flex items-center gap-3">
          <span :class="['badge', STATUS_BADGES[app.status]]">{{ STATUS_LABELS[app.status] }}</span>
          <router-link to="/admissions" class="btn btn-secondary">Back</router-link>
        </div>
      </div>

      <p v-if="actionMessage" class="card text-green-700">{{ actionMessage }}</p>
      <p v-if="actionError" class="card text-red-600">{{ actionError }}</p>

      <section class="card space-y-3">
        <h2 class="text-lg font-semibold">Application</h2>
        <dl class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
          <div v-for="row in applicationRows" :key="row.label">
            <dt class="text-gray-500">{{ row.label }}</dt>
            <dd class="font-medium text-gray-900">{{ row.value || '—' }}</dd>
          </div>
        </dl>
      </section>

      <section class="card space-y-3">
        <h2 class="text-lg font-semibold">Parents and guardian</h2>
        <dl class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
          <div v-for="row in familyRows" :key="row.label">
            <dt class="text-gray-500">{{ row.label }}</dt>
            <dd class="font-medium text-gray-900">{{ row.value || '—' }}</dd>
          </div>
        </dl>
      </section>

      <section class="card space-y-3">
        <h2 class="text-lg font-semibold">Documents</h2>
        <div class="flex flex-wrap gap-3">
          <template v-for="(label, kind) in FILE_LABELS" :key="kind">
            <button v-if="app.files[kind]" type="button" class="btn btn-secondary" :disabled="openingFile === kind" @click="openFile(kind)">
              {{ openingFile === kind ? 'Opening...' : label }}
            </button>
            <span v-else class="text-sm text-gray-400">{{ label }}: not provided</span>
          </template>
        </div>
      </section>

      <section class="card space-y-4">
        <h2 class="text-lg font-semibold">Review</h2>
        <dl class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
          <div><dt class="text-gray-500">Test / interview</dt><dd class="font-medium">{{ formatDhaka(app.test_at) || '—' }}</dd></div>
          <div><dt class="text-gray-500">Venue</dt><dd class="font-medium">{{ app.test_venue || '—' }}</dd></div>
          <div><dt class="text-gray-500">Score</dt><dd class="font-medium">{{ app.test_score ?? '—' }}</dd></div>
          <div class="md:col-span-3"><dt class="text-gray-500">Admin note</dt><dd class="font-medium whitespace-pre-line">{{ app.admin_note || '—' }}</dd></div>
        </dl>

        <template v-if="canEdit && allowed.length">
          <div class="border-t pt-4 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div v-if="allowed.includes('test_scheduled')">
                <label class="block text-sm font-medium text-gray-700 mb-1">Test date and time (Asia/Dhaka)</label>
                <input v-model="statusForm.test_at" type="datetime-local" class="input" />
                <p v-if="statusErr('test_at')" class="text-sm text-red-600 mt-1">{{ statusErr('test_at') }}</p>
              </div>
              <div v-if="allowed.includes('test_scheduled')">
                <label class="block text-sm font-medium text-gray-700 mb-1">Venue</label>
                <input v-model="statusForm.test_venue" type="text" class="input" maxlength="255" />
                <p v-if="statusErr('test_venue')" class="text-sm text-red-600 mt-1">{{ statusErr('test_venue') }}</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Test score</label>
                <input v-model="statusForm.test_score" type="number" step="0.01" min="0" class="input" />
                <p v-if="statusErr('test_score')" class="text-sm text-red-600 mt-1">{{ statusErr('test_score') }}</p>
              </div>
              <div class="md:col-span-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">Admin note (never shown to the family)</label>
                <textarea v-model="statusForm.admin_note" rows="2" class="input" maxlength="2000"></textarea>
                <p v-if="statusErr('admin_note')" class="text-sm text-red-600 mt-1">{{ statusErr('admin_note') }}</p>
              </div>
            </div>
            <p v-if="statusErr('status')" class="text-sm text-red-600">{{ statusErr('status') }}</p>
            <div class="flex flex-wrap gap-2">
              <button v-for="status in allowed" :key="status" type="button" :class="['btn', status === 'rejected' ? 'btn-danger' : 'btn-primary']" :disabled="saving" @click="changeStatus(status)">
                {{ ACTION_LABELS[status] }}
              </button>
            </div>
          </div>
        </template>

        <div v-if="app.status === 'approved' && authStore.hasPermission('create-students')" class="border-t pt-4">
          <button type="button" class="btn btn-primary" @click="openConvert">Convert to student</button>
        </div>
        <p v-if="app.status === 'admitted'" class="border-t pt-4 text-sm text-gray-700">
          Admitted as student
          <router-link v-if="app.student_id" :to="`/students/${app.student_id}`" class="text-primary-600 hover:underline">{{ app.student?.student_id || `#${app.student_id}` }}</router-link>.
        </p>
      </section>
    </template>

    <div v-if="showConvert" class="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50">
      <form class="bg-white rounded-lg shadow-xl w-full max-w-lg p-6 space-y-4 max-h-[90vh] overflow-y-auto" @submit.prevent="convert">
        <h2 class="text-lg font-semibold">Convert to student</h2>
        <p v-if="convertError" class="text-sm text-red-600">{{ convertError }}</p>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
          <select v-model="convertForm.section_id" class="input" @change="onSectionChange">
            <option value="">Select section</option>
            <option v-for="section in classSections" :key="section.id" :value="section.id">
              {{ section.class?.name }} - {{ section.name }} ({{ section.shift?.name_en || 'no shift' }}){{ section.group ? ` - ${GROUP_LABELS[section.group]}` : '' }}
            </option>
          </select>
          <p v-if="convertErr('section_id')" class="text-sm text-red-600 mt-1">{{ convertErr('section_id') }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Roll number (optional)</label>
          <input v-model="convertForm.roll_number" type="number" min="1" class="input" />
          <p v-if="convertErr('roll_number') || convertErr('enrolment.roll_number')" class="text-sm text-red-600 mt-1">{{ convertErr('roll_number') || convertErr('enrolment.roll_number') }}</p>
        </div>

        <template v-if="hasGroups">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Group</label>
            <select v-model="convertForm.group" class="input" :disabled="!!selectedSection?.group" @change="loadOptionalSubjects">
              <option value="">Select group</option>
              <option v-for="(label, value) in GROUP_LABELS" :key="value" :value="value">{{ label }}</option>
            </select>
            <p v-if="convertErr('group') || convertErr('enrolment.group')" class="text-sm text-red-600 mt-1">{{ convertErr('group') || convertErr('enrolment.group') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">4th subject</label>
            <select v-model="convertForm.optional_subject_id" class="input" :disabled="!convertForm.group">
              <option value="">None</option>
              <option v-for="row in optionalSubjects" :key="row.subject_id" :value="row.subject_id">{{ row.subject?.name || row.subject_name || row.subject_id }}</option>
            </select>
            <p v-if="convertErr('optional_subject_id') || convertErr('enrolment.optional_subject_id')" class="text-sm text-red-600 mt-1">{{ convertErr('optional_subject_id') || convertErr('enrolment.optional_subject_id') }}</p>
          </div>
        </template>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Student password</label>
          <input v-model="convertForm.password" type="password" class="input" autocomplete="new-password" />
          <p v-if="convertErr('password')" class="text-sm text-red-600 mt-1">{{ convertErr('password') }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Guardian password (only for a new guardian login)</label>
          <input v-model="convertForm.guardian_password" type="password" class="input" autocomplete="new-password" />
          <p v-if="convertErr('guardian_password')" class="text-sm text-red-600 mt-1">{{ convertErr('guardian_password') }}</p>
        </div>

        <div class="flex justify-end gap-2">
          <button type="button" class="btn btn-secondary" @click="showConvert = false">Cancel</button>
          <button type="submit" class="btn btn-primary" :disabled="converting">{{ converting ? 'Converting...' : 'Convert' }}</button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { GROUP_LABELS } from '@/constants/academic'
import { ACTION_LABELS, STATUS_BADGES, STATUS_LABELS, TRANSITIONS } from '@/constants/admissions'

const FILE_LABELS = { photo: 'Photo', birth_certificate: 'Birth certificate', previous_school_doc: 'Previous school document' }

const route = useRoute()
const authStore = useAuthStore()
const app = ref(null)
const loading = ref(true)
const loadError = ref('')
const actionMessage = ref('')
const actionError = ref('')
const saving = ref(false)
const openingFile = ref('')

const statusForm = reactive({ test_at: '', test_venue: '', test_score: '', admin_note: '' })
const statusErrors = ref({})

const showConvert = ref(false)
const converting = ref(false)
const convertError = ref('')
const convertErrors = ref({})
const classSections = ref([])
const optionalSubjects = ref([])
const convertForm = reactive({ section_id: '', roll_number: '', group: '', optional_subject_id: '', password: '', guardian_password: '' })

const canEdit = computed(() => authStore.hasPermission('edit-students'))
const allowed = computed(() => TRANSITIONS[app.value?.status] || [])
const selectedSection = computed(() => classSections.value.find((s) => s.id === convertForm.section_id) || null)
const hasGroups = computed(() => !!app.value?.class?.has_groups)

const first = (errors, key) => errors[key]?.[0] || ''
const statusErr = (key) => first(statusErrors.value, key)
const convertErr = (key) => first(convertErrors.value, key)

const dhakaFormat = (iso, options) => new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Dhaka', ...options }).format(new Date(iso))
const formatDhaka = (iso) => (iso ? dhakaFormat(iso, { dateStyle: 'medium', timeStyle: 'short' }) : '')

// Value for <input type="datetime-local"> in Dhaka time.
const toDhakaInput = (iso) => {
  if (!iso) return ''
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka', hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' })
      .formatToParts(new Date(iso)).map((p) => [p.type, p.value])
  )
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`
}

const applicationRows = computed(() => {
  const a = app.value
  return [
    { label: 'Round', value: a.round?.name_bn || a.round?.name_en },
    { label: 'Class', value: a.class?.name },
    { label: 'Group', value: GROUP_LABELS[a.group] },
    { label: 'Preferred shift', value: a.shift?.name_en },
    { label: 'Name (English)', value: a.name_en },
    { label: 'Name (Bangla)', value: a.name_bn },
    { label: 'Date of birth', value: a.date_of_birth },
    { label: 'Gender', value: a.gender },
    { label: 'Religion', value: a.religion },
    { label: 'Birth registration no.', value: a.birth_registration_number },
    { label: 'Blood group', value: a.blood_group },
    { label: 'Nationality', value: a.nationality },
    { label: 'Previous school', value: a.previous_school },
    { label: 'Previous class', value: a.previous_class },
    { label: 'Present address', value: a.present_address },
    { label: 'Permanent address', value: a.permanent_address },
    { label: 'District', value: a.district },
    { label: 'Submitted', value: formatDhaka(a.created_at) },
    { label: 'Decided at', value: formatDhaka(a.decided_at) },
  ]
})

const familyRows = computed(() => {
  const a = app.value
  return [
    { label: 'Father', value: [a.father.name_en, a.father.name_bn].filter(Boolean).join(' / ') },
    { label: 'Father mobile', value: a.father.mobile },
    { label: 'Mother', value: [a.mother.name_en, a.mother.name_bn].filter(Boolean).join(' / ') },
    { label: 'Mother mobile', value: a.mother.mobile },
    { label: 'Guardian', value: [a.guardian.name, a.guardian.relation && `(${a.guardian.relation})`].filter(Boolean).join(' ') },
    { label: 'Guardian mobile', value: a.guardian.mobile },
    { label: 'Guardian email', value: a.guardian.email },
  ]
})

const setApplication = (data) => {
  app.value = data
  statusForm.test_at = toDhakaInput(data.test_at)
  statusForm.test_venue = data.test_venue || ''
  statusForm.test_score = data.test_score ?? ''
  statusForm.admin_note = data.admin_note || ''
}

const load = async () => {
  loading.value = true
  try {
    const { data } = await api.get(`/admission-applications/${route.params.id}`)
    setApplication(data.data)
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Failed to load the application'
  } finally {
    loading.value = false
  }
}

// The download endpoint needs the bearer token, which a plain link can't send, so the
// file is fetched as a blob through the API client and opened from an object URL.
const openFile = async (kind) => {
  openingFile.value = kind
  actionError.value = ''
  const tab = window.open('', '_blank')
  try {
    const response = await api.get(`/admission-applications/${app.value.id}/files/${kind}`, { responseType: 'blob' })
    const url = URL.createObjectURL(response.data)
    if (tab) tab.location.href = url
    else window.open(url, '_blank')
    setTimeout(() => URL.revokeObjectURL(url), 60000)
  } catch (error) {
    tab?.close()
    actionError.value = error.response?.status === 404 ? 'That file is no longer available.' : 'Failed to open the file'
  } finally {
    openingFile.value = ''
  }
}

const changeStatus = async (status) => {
  saving.value = true
  statusErrors.value = {}
  actionError.value = ''
  actionMessage.value = ''
  const payload = { status, admin_note: statusForm.admin_note || null }
  if (statusForm.test_score !== '' && statusForm.test_score !== null) payload.test_score = statusForm.test_score
  if (status === 'test_scheduled') {
    // The input is Dhaka wall-clock time; send it with its +06:00 offset.
    payload.test_at = statusForm.test_at ? `${statusForm.test_at}:00+06:00` : null
    payload.test_venue = statusForm.test_venue || null
  }
  try {
    const { data } = await api.patch(`/admission-applications/${app.value.id}/status`, payload)
    setApplication(data.data)
    actionMessage.value = data.message || 'Status updated'
  } catch (error) {
    if (error.response?.status === 422) {
      statusErrors.value = error.response.data.errors || {}
    } else {
      actionError.value = error.response?.data?.message || 'Failed to update the status'
    }
  } finally {
    saving.value = false
  }
}

const loadOptionalSubjects = async () => {
  optionalSubjects.value = []
  if (!hasGroups.value || !convertForm.group) return
  try {
    const { data } = await api.get(`/classes/${app.value.class_id}/subjects`, { params: { group: convertForm.group } })
    optionalSubjects.value = data.data.filter((row) => row.type === 'optional' || !!row.choice_group)
    if (convertForm.optional_subject_id && !optionalSubjects.value.some((row) => row.subject_id === convertForm.optional_subject_id)) {
      convertForm.optional_subject_id = ''
    }
  } catch (error) {
    console.error('Failed to fetch the 4th-subject choices:', error)
  }
}

const onSectionChange = () => {
  if (selectedSection.value?.group) convertForm.group = selectedSection.value.group
  loadOptionalSubjects()
}

const openConvert = async () => {
  convertError.value = ''
  convertErrors.value = {}
  convertForm.group = app.value.group || ''
  showConvert.value = true
  try {
    const { data } = await api.get('/sections', { params: { class_id: app.value.class_id, is_active: 1, per_page: 100 } })
    classSections.value = data.data
  } catch (error) {
    convertError.value = 'Failed to load the sections'
  }
  loadOptionalSubjects()
}

const convert = async () => {
  converting.value = true
  convertError.value = ''
  convertErrors.value = {}
  const payload = { section_id: convertForm.section_id || null, password: convertForm.password }
  if (convertForm.roll_number !== '') payload.roll_number = convertForm.roll_number
  if (hasGroups.value && convertForm.group) payload.group = convertForm.group
  if (hasGroups.value && convertForm.optional_subject_id) payload.optional_subject_id = convertForm.optional_subject_id
  if (convertForm.guardian_password) payload.guardian_password = convertForm.guardian_password
  try {
    const { data } = await api.post(`/admission-applications/${app.value.id}/convert`, payload)
    setApplication(data.data)
    showConvert.value = false
    actionMessage.value = data.message || 'Student created'
  } catch (error) {
    if (error.response?.status === 422) {
      convertErrors.value = error.response.data.errors || {}
      convertError.value = Object.keys(convertErrors.value).length ? '' : error.response.data.message
    } else {
      convertError.value = error.response?.data?.message || 'Failed to convert the application'
    }
  } finally {
    converting.value = false
  }
}

onMounted(load)
</script>
