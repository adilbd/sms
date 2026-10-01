<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Collect fee</h1>
      <p class="text-sm text-gray-500">Find the student, choose what they are paying, and record the payment. A receipt opens when it is saved.</p>
    </div>

    <div v-if="notice" :class="['rounded-lg border px-4 py-3 text-sm', notice.ok ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700']" role="status">
      {{ notice.text }}
    </div>

    <!-- Step 1: find the student -->
    <div class="card">
      <label class="block text-sm font-medium text-gray-700 mb-1" for="student-search">Student</label>
      <input
        id="student-search"
        v-model="search"
        type="text"
        class="input max-w-md"
        placeholder="Search by student ID or name..."
        autocomplete="off"
        @input="onSearch"
      />
      <ul v-if="results.length" class="mt-2 max-w-md divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white">
        <li v-for="student in results" :key="student.id">
          <button type="button" class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-gray-50" @click="choose(student)">
            <span>{{ student.name_en || student.name_bn }} <span class="text-gray-500">{{ student.student_id }}</span></span>
            <span class="text-xs text-gray-500">{{ student.current_enrolment?.class?.name }} {{ student.current_enrolment?.section?.name }}</span>
          </button>
        </li>
      </ul>
      <p v-else-if="searched && search.trim().length >= 2" class="mt-2 text-sm text-gray-500">No student found</p>
    </div>

    <template v-if="student">
      <div class="card">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 class="text-lg font-semibold text-gray-900">{{ student.name_en || student.name_bn }}</h2>
            <p class="text-sm text-gray-500">
              ID {{ student.student_id }}
              <template v-if="student.current_enrolment"> · {{ student.current_enrolment.class?.name }} {{ student.current_enrolment.section?.name }} · Roll {{ student.current_enrolment.roll_number ?? '-' }}</template>
            </p>
          </div>
          <div class="text-right">
            <p class="text-xs uppercase text-gray-500">Outstanding</p>
            <p class="text-2xl font-bold text-gray-900">{{ taka(fromPaisa(outstandingPaisa)) }}</p>
          </div>
        </div>
      </div>

      <div class="card">
        <h2 class="text-lg font-semibold text-gray-900 mb-1">Outstanding dues</h2>
        <p class="text-sm text-gray-500 mb-4">Leave all unticked to pay the oldest dues first, or tick the dues to pay.</p>
        <p v-if="loadingDues" class="text-gray-500">Loading dues...</p>
        <p v-else-if="dues.length === 0" class="text-gray-500">This student owes nothing.</p>
        <div v-else class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th></th>
                <th>Fee</th>
                <th>Period</th>
                <th>Due date</th>
                <th class="text-right">Net</th>
                <th class="text-right">Paid</th>
                <th class="text-right">Outstanding</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="due in dues" :key="due.id">
                <td><input v-model="picked" type="checkbox" :value="due.id" :aria-label="`Pay ${due.head?.code} ${due.period}`" /></td>
                <td>{{ due.head?.name_en || due.head?.name_bn }}</td>
                <td>{{ periodLabel(due.period) }}</td>
                <td>{{ due.due_date }}</td>
                <td class="text-right">{{ taka(due.net_amount) }}</td>
                <td class="text-right">{{ taka(due.paid_amount) }}</td>
                <td class="text-right font-medium">{{ taka(due.outstanding_amount) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <form v-if="dues.length" class="card space-y-4" @submit.prevent="submit">
        <h2 class="text-lg font-semibold text-gray-900">Payment</h2>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="pay-amount">Amount (৳)</label>
            <input id="pay-amount" v-model="amount" type="number" min="0.01" step="0.01" class="input" required />
            <p class="text-xs text-gray-500 mt-1">Up to {{ taka(fromPaisa(payablePaisa)) }}</p>
            <p v-if="errors.amount" class="text-sm text-red-600 mt-1">{{ errors.amount[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="pay-method">Method</label>
            <select id="pay-method" v-model="method" class="input" required>
              <option v-for="m in PAYMENT_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
            <p v-if="errors.method" class="text-sm text-red-600 mt-1">{{ errors.method[0] }}</p>
          </div>
          <div v-if="isMobile">
            <label class="block text-sm font-medium text-gray-700 mb-1" for="pay-txn">Transaction ID</label>
            <input id="pay-txn" v-model="transactionId" type="text" maxlength="64" class="input uppercase" required />
            <p v-if="errors.transaction_id" class="text-sm text-red-600 mt-1">{{ errors.transaction_id[0] }}</p>
          </div>
          <div v-if="isAdmin">
            <label class="block text-sm font-medium text-gray-700 mb-1" for="pay-date">Paid at (optional, Dhaka time)</label>
            <input id="pay-date" v-model="paidAt" type="datetime-local" class="input" />
            <p v-if="errors.paid_at" class="text-sm text-red-600 mt-1">{{ errors.paid_at[0] }}</p>
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1" for="pay-note">Note (optional)</label>
            <input id="pay-note" v-model="note" type="text" maxlength="500" class="input" />
            <p v-if="errors.note" class="text-sm text-red-600 mt-1">{{ errors.note[0] }}</p>
          </div>
        </div>
        <p v-if="errors.due_ids" class="text-sm text-red-600">{{ errors.due_ids[0] }}</p>
        <button type="submit" class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving...' : 'Record payment' }}</button>
      </form>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { MOBILE_METHODS, PAYMENT_METHODS } from '@/constants/fees'
import { fromPaisa, taka, toPaisa } from '@/utils/money'
import { periodLabel } from '@/utils/fees'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const isAdmin = computed(() => authStore.hasRole('admin'))

const search = ref('')
const results = ref([])
const searched = ref(false)
const student = ref(null)
const dues = ref([])
const picked = ref([])
const loadingDues = ref(false)
const amount = ref('')
const method = ref('cash')
const transactionId = ref('')
const paidAt = ref('')
const note = ref('')
const saving = ref(false)
const notice = ref(null)
const errors = ref({})

const isMobile = computed(() => MOBILE_METHODS.includes(method.value))
const outstandingPaisa = computed(() => dues.value.reduce((sum, due) => sum + toPaisa(due.outstanding_amount), 0))
// What the payment may be: the ticked dues, or everything when none is ticked.
const payablePaisa = computed(() =>
  picked.value.length
    ? dues.value.filter((due) => picked.value.includes(due.id)).reduce((sum, due) => sum + toPaisa(due.outstanding_amount), 0)
    : outstandingPaisa.value
)

// The amount follows the selection until the clerk types their own.
watch(payablePaisa, (paisa) => {
  amount.value = paisa > 0 ? fromPaisa(paisa) : ''
})

let timer = null
const onSearch = () => {
  clearTimeout(timer)
  searched.value = false

  if (search.value.trim().length < 2) {
    results.value = []
    return
  }

  timer = setTimeout(async () => {
    try {
      const { data } = await api.get('/students', { params: { search: search.value.trim(), per_page: 8 } })
      results.value = data.data
    } catch (error) {
      results.value = []
    } finally {
      searched.value = true
    }
  }, 250)
}

const loadDues = async () => {
  loadingDues.value = true
  picked.value = []
  try {
    const { data } = await api.get('/fee-dues', { params: { student_id: student.value.id, per_page: 100 } })
    dues.value = data.data.filter((due) => due.status === 'unpaid' || due.status === 'partial')
  } catch (error) {
    dues.value = []
    notice.value = { ok: false, text: error.response?.data?.message || 'Failed to load the dues' }
  } finally {
    loadingDues.value = false
  }
}

const choose = async (picked_student) => {
  student.value = picked_student
  results.value = []
  search.value = ''
  notice.value = null
  errors.value = {}
  await loadDues()
}

// Ticked dues are paid in the order they appear (oldest first).
const submit = async () => {
  errors.value = {}
  notice.value = null
  saving.value = true

  const body = { student_id: student.value.id, amount: amount.value, method: method.value }
  if (isMobile.value) body.transaction_id = transactionId.value
  if (note.value) body.note = note.value
  if (picked.value.length) body.due_ids = dues.value.filter((due) => picked.value.includes(due.id)).map((due) => due.id)
  // The input is Dhaka wall-clock time; send it with its offset.
  if (isAdmin.value && paidAt.value) body.paid_at = `${paidAt.value}:00+06:00`

  try {
    const { data } = await api.post('/fee-payments', body)
    router.push(`/fees/receipts/${data.data.id}`)
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors ?? {}
    } else {
      notice.value = { ok: false, text: error.response?.data?.message || 'Failed to record the payment' }
    }
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  // Linked from a student's page.
  if (route.query.student_id) {
    try {
      const { data } = await api.get(`/students/${route.query.student_id}`)
      await choose(data.data)
    } catch (error) {
      notice.value = { ok: false, text: error.response?.data?.message || 'Student not found' }
    }
  }
})
</script>
