<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">Student</h1>
      <div class="flex space-x-2">
        <router-link to="/students" class="btn btn-secondary">Back</router-link>
        <router-link v-if="student && authStore.hasPermission('edit-students')" :to="`/certificates?student_id=${student.id}`" class="btn btn-secondary">Issue certificate</router-link>
        <router-link v-if="student" :to="`/id-cards?student_id=${student.id}`" class="btn btn-secondary">Print ID card</router-link>
        <router-link v-if="student && authStore.hasPermission('edit-students')" :to="`/students/${student.id}/edit`" class="btn btn-primary">Edit</router-link>
      </div>
    </div>

    <div v-if="loading" class="card text-center py-8 text-gray-500">Loading...</div>
    <div v-else-if="notFound" class="card text-center py-8 text-gray-500">Student not found.</div>

    <template v-else-if="student">
      <section class="card">
        <div class="flex items-start gap-4">
          <img v-if="student.photo_url" :src="student.photo_url" alt="" class="h-24 w-24 rounded-lg object-cover" />
          <div v-else class="flex h-24 w-24 items-center justify-center rounded-lg bg-gray-100 text-4xl">🧑‍🎓</div>
          <div>
            <h2 class="text-xl font-semibold text-gray-900">{{ student.name_en || student.name_bn }}</h2>
            <p v-if="student.name_en && student.name_bn" class="text-gray-600">{{ student.name_bn }}</p>
            <p class="text-sm text-gray-500 mt-1">Student ID {{ student.student_id }}</p>
            <span :class="['badge mt-2 capitalize', student.status === 'active' ? 'badge-success' : 'badge-danger']">{{ student.status }}</span>
          </div>
        </div>
        <dl class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-3 text-sm">
          <div v-for="item in profileItems" :key="item.label">
            <dt class="text-gray-500">{{ item.label }}</dt>
            <dd class="font-medium text-gray-900 capitalize">{{ item.value || '-' }}</dd>
          </div>
        </dl>
      </section>

      <section class="card">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Family and guardian</h2>
        <dl class="grid grid-cols-1 gap-4 md:grid-cols-3 text-sm">
          <div>
            <dt class="text-gray-500">Father</dt>
            <dd class="font-medium">{{ student.father.name_en || student.father.name_bn || '-' }}</dd>
            <dd class="text-gray-500">{{ student.father.mobile }} {{ student.father.occupation }}</dd>
          </div>
          <div>
            <dt class="text-gray-500">Mother</dt>
            <dd class="font-medium">{{ student.mother.name_en || student.mother.name_bn || '-' }}</dd>
            <dd class="text-gray-500">{{ student.mother.mobile }} {{ student.mother.occupation }}</dd>
          </div>
          <div>
            <dt class="text-gray-500">Guardian ({{ student.guardian.relation }})</dt>
            <dd class="font-medium">{{ student.guardian.name }}</dd>
            <dd class="text-gray-500">{{ student.guardian.mobile }}</dd>
          </div>
        </dl>
      </section>

      <section class="card">
        <h2 class="text-lg font-semibold text-gray-900 mb-2">Logins</h2>
        <p class="text-sm text-gray-600">
          Student signs in with username <strong>{{ student.username }}</strong>.
          <template v-if="student.guardian.mobile">The guardian signs in with their mobile number <strong>{{ student.guardian.mobile }}</strong>.</template>
        </p>
      </section>

      <section class="card">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Enrolment history</h2>
        <p v-if="!student.enrolments?.length" class="text-gray-500">Not enrolled in any academic year yet.</p>
        <div v-else class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Year</th>
                <th>Class</th>
                <th>Section</th>
                <th>Roll</th>
                <th>Group</th>
                <th>4th subject</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in student.enrolments" :key="row.id">
                <td>{{ row.academic_year?.name }}</td>
                <td>{{ row.class?.name }}</td>
                <td>{{ row.section?.name }}</td>
                <td>{{ row.roll_number ?? '-' }}</td>
                <td>{{ GROUP_LABELS[row.group] || '-' }}</td>
                <td>{{ row.optional_subject?.name || '-' }}</td>
                <td class="capitalize">{{ row.status }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="authStore.hasPermission('view-fees')" class="card">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-lg font-semibold text-gray-900">Fee waivers</h2>
          <div class="flex gap-2">
            <router-link v-if="authStore.hasPermission('collect-fees')" :to="`/fees/collect?student_id=${student.id}`" class="btn btn-secondary">Collect fee</router-link>
            <router-link :to="`/fees/reports?student_id=${student.id}`" class="btn btn-secondary">Fee ledger</router-link>
          </div>
        </div>
        <p class="text-sm text-gray-500 mb-4">A waiver applies to the dues generated after it is saved; dues that already exist keep their amount.</p>
        <div v-if="waiverNotice" :class="['rounded-lg border px-4 py-3 text-sm mb-4', waiverNotice.ok ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700']" role="status">{{ waiverNotice.text }}</div>

        <p v-if="waivers.length === 0" class="text-gray-500 mb-4">No waivers.</p>
        <div v-else class="overflow-x-auto mb-4">
          <table class="table">
            <thead>
              <tr><th>Year</th><th>Fee head</th><th>Waiver</th><th>Reason</th><th>Approved by</th><th v-if="authStore.hasPermission('create-fees')"></th></tr>
            </thead>
            <tbody>
              <tr v-for="waiver in waivers" :key="waiver.id">
                <td>{{ yearName(waiver.academic_year_id) }}</td>
                <td>{{ waiver.head?.name_en || waiver.head?.name_bn }}</td>
                <td>{{ waiver.percent !== null ? `${waiver.percent}%` : taka(waiver.fixed_amount) }}</td>
                <td>{{ waiver.reason || '-' }}</td>
                <td>{{ waiver.approved_by_name }}</td>
                <td v-if="authStore.hasPermission('create-fees')">
                  <button type="button" class="text-red-600 hover:text-red-800" title="Remove the waiver" @click="removeWaiver(waiver)">🗑️</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <form v-if="authStore.hasPermission('create-fees')" class="grid grid-cols-1 gap-4 md:grid-cols-5" @submit.prevent="addWaiver">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="waiver-year">Year</label>
            <select id="waiver-year" v-model="waiverForm.academic_year_id" class="input" required>
              <option v-for="year in years" :key="year.id" :value="year.id">{{ year.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="waiver-head">Fee head</label>
            <select id="waiver-head" v-model="waiverForm.fee_head_id" class="input" required>
              <option value="" disabled>Choose</option>
              <option v-for="head in heads" :key="head.id" :value="head.id">{{ head.name_en || head.name_bn }}</option>
            </select>
            <p v-if="waiverErrors.fee_head_id" class="text-sm text-red-600 mt-1">{{ waiverErrors.fee_head_id[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="waiver-kind">Type</label>
            <select id="waiver-kind" v-model="waiverForm.kind" class="input">
              <option value="percent">Percent</option>
              <option value="fixed">Fixed amount (৳)</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="waiver-value">{{ waiverForm.kind === 'percent' ? 'Percent' : 'Amount (৳)' }}</label>
            <input id="waiver-value" v-model="waiverForm.value" type="number" min="0" :max="waiverForm.kind === 'percent' ? 100 : undefined" step="0.01" class="input" required />
            <p v-if="waiverErrors.percent || waiverErrors.fixed_amount" class="text-sm text-red-600 mt-1">{{ (waiverErrors.percent || waiverErrors.fixed_amount)[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="waiver-reason">Reason</label>
            <input id="waiver-reason" v-model="waiverForm.reason" type="text" maxlength="255" class="input" />
          </div>
          <div class="md:col-span-5">
            <button type="submit" class="btn btn-primary" :disabled="savingWaiver">{{ savingWaiver ? 'Saving...' : 'Add waiver' }}</button>
          </div>
        </form>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { taka } from '@/utils/money'

const authStore = useAuthStore()

const GROUP_LABELS = {
  science: 'Science',
  business_studies: 'Business Studies',
  humanities: 'Humanities',
}

const route = useRoute()
const student = ref(null)
const loading = ref(true)
const notFound = ref(false)

const profileItems = computed(() => {
  const s = student.value
  if (!s) return []
  return [
    { label: 'Date of birth', value: s.date_of_birth },
    { label: 'Gender', value: s.gender },
    { label: 'Religion', value: s.religion },
    { label: 'Blood group', value: s.blood_group },
    { label: 'Birth registration no.', value: s.birth_registration_number },
    { label: 'Nationality', value: s.nationality },
    { label: 'Mobile', value: s.mobile },
    { label: 'Email', value: s.email },
    { label: 'District', value: s.district },
    { label: 'Admission date', value: s.admission_date },
    { label: 'Leaving date', value: s.leaving_date },
    { label: 'Present address', value: s.present_address },
    { label: 'Permanent address', value: s.permanent_address },
  ]
})

// Fee waivers: shown to anyone who can view fees, changed by anyone who can create them.
const waivers = ref([])
const years = ref([])
const heads = ref([])
const waiverNotice = ref(null)
const waiverErrors = ref({})
const savingWaiver = ref(false)
const waiverForm = reactive({ academic_year_id: '', fee_head_id: '', kind: 'percent', value: '', reason: '' })

const yearName = (id) => years.value.find((year) => year.id === id)?.name ?? id

const loadWaivers = async () => {
  const { data } = await api.get('/fee-waivers', { params: { student_id: student.value.id, per_page: 100 } })
  waivers.value = data.data
}

const loadFeeData = async () => {
  if (!authStore.hasPermission('view-fees')) return

  try {
    const [yearsRes, headsRes] = await Promise.all([
      api.get('/academic-years', { params: { per_page: 100 } }),
      api.get('/fee-heads', { params: { per_page: 100, is_active: true } }),
    ])
    years.value = yearsRes.data.data
    heads.value = headsRes.data.data
    waiverForm.academic_year_id = years.value.find((year) => year.is_active)?.id ?? ''
    await loadWaivers()
  } catch (error) {
    waiverNotice.value = { ok: false, text: error.response?.data?.message || 'Failed to load the fee waivers' }
  }
}

const addWaiver = async () => {
  waiverErrors.value = {}
  waiverNotice.value = null
  savingWaiver.value = true

  const body = {
    student_id: student.value.id,
    academic_year_id: waiverForm.academic_year_id,
    fee_head_id: waiverForm.fee_head_id,
    percent: waiverForm.kind === 'percent' ? waiverForm.value : null,
    fixed_amount: waiverForm.kind === 'fixed' ? waiverForm.value : null,
    reason: waiverForm.reason || null,
  }

  try {
    const { data } = await api.post('/fee-waivers', body)
    waiverNotice.value = { ok: true, text: data.message }
    Object.assign(waiverForm, { fee_head_id: '', value: '', reason: '' })
    await loadWaivers()
  } catch (error) {
    if (error.response?.status === 422) {
      waiverErrors.value = error.response.data.errors ?? {}
    } else {
      waiverNotice.value = { ok: false, text: error.response?.data?.message || 'Failed to save the waiver' }
    }
  } finally {
    savingWaiver.value = false
  }
}

const removeWaiver = async (waiver) => {
  if (!confirm('Remove this waiver? Dues that already exist keep their amount.')) return

  try {
    await api.delete(`/fee-waivers/${waiver.id}`)
    waiverNotice.value = { ok: true, text: 'Waiver removed' }
    await loadWaivers()
  } catch (error) {
    waiverNotice.value = { ok: false, text: error.response?.data?.message || 'Failed to remove the waiver' }
  }
}

onMounted(async () => {
  try {
    const { data } = await api.get(`/students/${route.params.id}`)
    student.value = data.data
    await loadFeeData()
  } catch (error) {
    if (error.response?.status === 404) notFound.value = true
    else console.error('Failed to fetch student:', error)
  } finally {
    loading.value = false
  }
})
</script>
