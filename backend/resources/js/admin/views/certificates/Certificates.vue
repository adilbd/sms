<template>
  <div class="space-y-6">
    <div class="flex flex-wrap justify-between items-center gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Certificates</h1>
        <p class="text-sm text-gray-500">The register of testimonials, transfer, study and character certificates. Each has a serial number and keeps what was printed on it.</p>
      </div>
      <div class="flex gap-2">
        <router-link to="/id-cards" class="btn btn-secondary">🪪 ID cards</router-link>
        <button v-if="canIssue" type="button" class="btn btn-primary" @click="openIssue()">➕ Issue certificate</button>
      </div>
    </div>

    <div v-if="notice" :class="['rounded-lg border px-4 py-3 text-sm', notice.ok ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700']" role="status">{{ notice.text }}</div>

    <!-- Issue form -->
    <form v-if="issuing" class="card space-y-4" @submit.prevent="submit()">
      <h2 class="text-lg font-semibold text-gray-900">Issue a certificate</h2>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="cert-student">Student</label>
        <div v-if="student" class="flex items-center gap-3">
          <span class="font-medium">{{ student.name_en || student.name_bn }} <span class="text-gray-500">{{ student.student_id }}</span></span>
          <button type="button" class="text-sm text-primary-600 hover:text-primary-800" @click="clearStudent">Change</button>
        </div>
        <template v-else>
          <input id="cert-student" v-model="search" type="text" class="input max-w-md" placeholder="Search by student ID or name..." autocomplete="off" @input="onSearch" />
          <ul v-if="results.length" class="mt-2 max-w-md divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white">
            <li v-for="item in results" :key="item.id">
              <button type="button" class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-gray-50" @click="chooseStudent(item)">
                <span>{{ item.name_en || item.name_bn }} <span class="text-gray-500">{{ item.student_id }}</span></span>
                <span class="text-xs text-gray-500">{{ item.current_enrolment?.class?.name }} {{ item.current_enrolment?.section?.name }}</span>
              </button>
            </li>
          </ul>
          <p v-else-if="searched && search.trim().length >= 2" class="mt-2 text-sm text-gray-500">No student found</p>
        </template>
        <p v-if="errors.student_id" class="mt-1 text-sm text-red-600">{{ errors.student_id[0] }}</p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="cert-type">Type</label>
          <select id="cert-type" v-model="form.type" class="input" @change="resetOverride">
            <option v-for="type in CERTIFICATE_TYPES" :key="type.value" :value="type.value">{{ type.label }}</option>
          </select>
          <p v-if="errors.type" class="mt-1 text-sm text-red-600">{{ errors.type[0] }}</p>
        </div>

        <div v-if="form.type !== 'study'">
          <label class="block text-sm font-medium text-gray-700 mb-1" for="cert-conduct">Conduct <span class="text-gray-400">(default ভালো)</span></label>
          <input id="cert-conduct" v-model="form.conduct" type="text" maxlength="500" class="input" />
          <p v-if="errors.conduct" class="mt-1 text-sm text-red-600">{{ errors.conduct[0] }}</p>
        </div>

        <div v-if="form.type === 'testimonial' || form.type === 'character'" class="md:col-span-3">
          <label class="block text-sm font-medium text-gray-700 mb-1" for="cert-remarks">Remarks</label>
          <input id="cert-remarks" v-model="form.remarks" type="text" maxlength="500" class="input" />
          <p v-if="errors.remarks" class="mt-1 text-sm text-red-600">{{ errors.remarks[0] }}</p>
        </div>
      </div>

      <!-- Testimonial: optional board exam details -->
      <div v-if="form.type === 'testimonial'" class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="cert-exam">Exam</label>
          <select id="cert-exam" v-model="form.exam" class="input">
            <option value="">-</option>
            <option value="ssc">SSC</option>
            <option value="hsc">HSC</option>
          </select>
          <p v-if="errors.exam" class="mt-1 text-sm text-red-600">{{ errors.exam[0] }}</p>
        </div>
        <div v-for="field in TESTIMONIAL_FIELDS" :key="field.key">
          <label class="block text-sm font-medium text-gray-700 mb-1" :for="`cert-${field.key}`">{{ field.label }}</label>
          <input :id="`cert-${field.key}`" v-model="form[field.key]" :type="field.type || 'text'" :step="field.step" class="input" />
          <p v-if="errors[field.key]" class="mt-1 text-sm text-red-600">{{ errors[field.key][0] }}</p>
        </div>
      </div>

      <!-- Transfer certificate -->
      <div v-if="form.type === 'transfer'" class="space-y-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="cert-reason">Reason for leaving *</label>
          <input id="cert-reason" v-model="form.reason" type="text" maxlength="500" class="input" required />
          <p v-if="errors.reason" class="mt-1 text-sm text-red-600">{{ errors.reason[0] }}</p>
        </div>
        <p class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          Issuing a transfer certificate marks the student as <strong>left</strong> today and switches off their login and, if it was their only active child, the guardian's. Cancelling the certificate later does <strong>not</strong> make the student active again.
        </p>
        <div v-if="outstanding" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 space-y-2">
          <p>{{ outstanding }}</p>
          <label class="flex items-center gap-2"><input v-model="form.allow_outstanding" type="checkbox" /> Issue anyway, with a note</label>
          <div v-if="form.allow_outstanding">
            <input v-model="form.outstanding_note" type="text" maxlength="500" class="input" placeholder="Why is it issued with dues outstanding?" />
            <p v-if="errors.outstanding_note" class="mt-1 text-sm text-red-600">{{ errors.outstanding_note[0] }}</p>
          </div>
        </div>
      </div>

      <div class="flex gap-2">
        <button type="submit" class="btn btn-primary" :disabled="saving || !student">{{ saving ? 'Issuing...' : 'Issue' }}</button>
        <button type="button" class="btn btn-secondary" @click="closeIssue">Cancel</button>
      </div>
    </form>

    <!-- Filters -->
    <div class="card">
      <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <input v-model="filters.search" type="text" class="input" placeholder="Serial no., student name or ID" @input="onFilterSearch" />
        <select v-model="filters.type" class="input" @change="fetchCertificates()">
          <option value="">All types</option>
          <option v-for="type in CERTIFICATE_TYPES" :key="type.value" :value="type.value">{{ type.label }}</option>
        </select>
        <select v-model="filters.status" class="input" @change="fetchCertificates()">
          <option value="">Issued and cancelled</option>
          <option value="issued">Issued</option>
          <option value="cancelled">Cancelled</option>
        </select>
        <input v-model="filters.from" type="date" class="input" title="Issued from" @change="fetchCertificates()" />
        <input v-model="filters.to" type="date" class="input" title="Issued to" @change="fetchCertificates()" />
      </div>
    </div>

    <div class="card">
      <div v-if="loading" class="text-center py-8"><p class="text-gray-500">Loading certificates...</p></div>
      <div v-else-if="error" class="text-center py-8"><p class="text-red-600">{{ error }}</p></div>
      <div v-else-if="items.length === 0" class="text-center py-8"><p class="text-gray-500">No certificates found</p></div>
      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr><th>Serial no.</th><th>Type</th><th>Student</th><th>Issued</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <tr v-for="item in items" :key="item.id" class="hover:bg-gray-50">
              <td class="font-medium">{{ item.serial_no }}</td>
              <td>{{ TYPE_LABELS[item.type] || item.type }}</td>
              <td>
                <router-link :to="`/students/${item.student_id}`" class="text-primary-600 hover:text-primary-800">{{ item.student?.name_en || item.student?.name_bn }}</router-link>
                <span class="text-gray-500"> {{ item.student?.student_id }}</span>
              </td>
              <td>{{ item.issued_on }}<span v-if="item.issuer" class="text-gray-500"> · {{ item.issuer.name }}</span></td>
              <td>
                <span v-if="item.status === 'cancelled'" class="badge badge-danger" :title="item.cancel_reason">Cancelled</span>
                <span v-else class="badge badge-success">Issued</span>
              </td>
              <td>
                <div class="flex space-x-3">
                  <router-link :to="`/certificates/${item.id}/print`" class="text-primary-600 hover:text-primary-800" title="Print">🖨️ Print</router-link>
                  <button v-if="canCancel && item.status !== 'cancelled'" type="button" class="text-red-600 hover:text-red-800" @click="cancel(item)">Cancel</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="pagination.total > 0" class="mt-4 flex justify-between items-center">
        <p class="text-sm text-gray-700">Showing {{ pagination.from }} to {{ pagination.to }} of {{ pagination.total }} results</p>
        <div class="flex space-x-2">
          <button :disabled="pagination.current_page === 1" class="btn btn-secondary disabled:opacity-50" @click="fetchCertificates(pagination.current_page - 1)">Previous</button>
          <button :disabled="pagination.current_page === pagination.last_page" class="btn btn-secondary disabled:opacity-50" @click="fetchCertificates(pagination.current_page + 1)">Next</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { CERTIFICATE_TYPES, TYPE_LABELS } from '@/utils/certificates'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const canIssue = computed(() => auth.hasPermission('edit-students'))
// Cancelling needs delete-students, which only the admin role has.
const canCancel = computed(() => auth.hasPermission('delete-students'))

const TESTIMONIAL_FIELDS = [
  { key: 'exam_roll', label: 'Exam roll' },
  { key: 'registration_no', label: 'Registration no.' },
  { key: 'board', label: 'Board' },
  { key: 'passing_year', label: 'Passing year', type: 'number' },
  { key: 'gpa', label: 'GPA (0-5)', type: 'number', step: '0.01' },
  { key: 'session', label: 'Session (e.g. 2023-24)' },
]

const items = ref([])
const loading = ref(false)
const error = ref('')
const notice = ref(null)
const filters = reactive({ search: '', type: '', status: '', from: '', to: '' })
const pagination = reactive({ current_page: 1, last_page: 1, per_page: 15, total: 0, from: 0, to: 0 })

const fetchCertificates = async (page = 1) => {
  loading.value = true
  error.value = ''
  try {
    const params = { page, per_page: pagination.per_page }
    Object.entries(filters).forEach(([key, value]) => {
      if (value) params[key] = value
    })
    const { data } = await api.get('/certificates', { params })
    items.value = data.data
    Object.assign(pagination, {
      current_page: data.meta.current_page,
      last_page: data.meta.last_page,
      total: data.meta.total,
      from: data.meta.from,
      to: data.meta.to,
    })
  } catch (e) {
    error.value = e.response?.data?.errors ? Object.values(e.response.data.errors)[0][0] : e.response?.data?.message || 'Failed to load certificates'
  } finally {
    loading.value = false
  }
}

let filterTimer = null
const onFilterSearch = () => {
  clearTimeout(filterTimer)
  filterTimer = setTimeout(() => fetchCertificates(), 300)
}

const cancel = async (item) => {
  const extra = item.type === 'transfer' ? '\n\nThe student stays marked as left: cancelling does not make them active again.' : ''
  const reason = window.prompt(`Cancel ${item.serial_no}? Its number stays in the register and is never reused.${extra}\n\nReason:`)
  if (!reason || !reason.trim()) return

  try {
    await api.post(`/certificates/${item.id}/cancel`, { reason: reason.trim() })
    notice.value = { ok: true, text: `${item.serial_no} was cancelled.` }
    await fetchCertificates(pagination.current_page)
  } catch (e) {
    notice.value = { ok: false, text: e.response?.data?.errors?.reason?.[0] || e.response?.data?.message || 'Failed to cancel the certificate' }
  }
}

// Issue form
const issuing = ref(false)
const student = ref(null)
const search = ref('')
const results = ref([])
const searched = ref(false)
const saving = ref(false)
const errors = ref({})
const outstanding = ref('')

const blankForm = () => ({
  type: 'testimonial',
  conduct: '',
  remarks: '',
  exam: '',
  exam_roll: '',
  registration_no: '',
  board: '',
  passing_year: '',
  gpa: '',
  session: '',
  reason: '',
  allow_outstanding: false,
  outstanding_note: '',
})
const form = reactive(blankForm())

const resetOverride = () => {
  outstanding.value = ''
  form.allow_outstanding = false
  form.outstanding_note = ''
}

const openIssue = async (studentId = null) => {
  Object.assign(form, blankForm())
  errors.value = {}
  outstanding.value = ''
  student.value = null
  search.value = ''
  results.value = []
  issuing.value = true

  if (studentId) {
    try {
      const { data } = await api.get(`/students/${studentId}`)
      student.value = data.data
    } catch (e) {
      notice.value = { ok: false, text: 'That student could not be loaded.' }
    }
  }
}

const closeIssue = () => {
  issuing.value = false
}

const clearStudent = () => {
  student.value = null
  resetOverride()
}

const chooseStudent = (item) => {
  student.value = item
  results.value = []
  search.value = ''
  resetOverride()
}

let searchTimer = null
const onSearch = () => {
  clearTimeout(searchTimer)
  searched.value = false

  if (search.value.trim().length < 2) {
    results.value = []
    return
  }

  searchTimer = setTimeout(async () => {
    try {
      const { data } = await api.get('/students', { params: { search: search.value.trim(), per_page: 8 } })
      results.value = data.data
    } catch (e) {
      results.value = []
    } finally {
      searched.value = true
    }
  }, 250)
}

// Only the fields that belong to the chosen type are sent.
const payload = () => {
  const body = { type: form.type, student_id: student.value.id }
  const fieldsByType = {
    testimonial: ['conduct', 'remarks', 'exam', 'exam_roll', 'registration_no', 'board', 'passing_year', 'gpa', 'session'],
    transfer: ['conduct', 'reason'],
    study: [],
    character: ['conduct', 'remarks'],
  }

  fieldsByType[form.type].forEach((key) => {
    if (form[key] !== '' && form[key] !== null) body[key] = form[key]
  })

  if (form.type === 'transfer' && form.allow_outstanding) {
    body.allow_outstanding = true
    body.outstanding_note = form.outstanding_note
  }

  return body
}

const submit = async () => {
  errors.value = {}
  saving.value = true
  try {
    const { data } = await api.post('/certificates', payload())
    notice.value = { ok: true, text: `${data.data.serial_no} was issued.` }
    issuing.value = false
    await fetchCertificates()
    router.push(`/certificates/${data.data.id}/print`)
  } catch (e) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors || {}
    } else if (e.response?.status === 409 && form.type === 'transfer' && e.response.data?.outstanding !== undefined) {
      // The 409 names the amount; offer the override with a note.
      outstanding.value = e.response.data.message
    } else {
      notice.value = { ok: false, text: e.response?.data?.message || 'Failed to issue the certificate' }
    }
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await fetchCertificates()

  // Opened from a student's page: the form is open with the student chosen.
  if (route.query.student_id && canIssue.value) await openIssue(route.query.student_id)
})
</script>
