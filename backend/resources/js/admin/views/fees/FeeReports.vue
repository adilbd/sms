<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">Fee reports</h1>

    <div class="flex gap-2 border-b border-gray-200" role="tablist">
      <button
        v-for="item in TABS"
        :key="item.key"
        type="button"
        role="tab"
        :aria-selected="tab === item.key"
        :class="['px-4 py-2 text-sm font-medium border-b-2 -mb-px', tab === item.key ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700']"
        @click="tab = item.key"
      >
        {{ item.label }}
      </button>
    </div>

    <div v-if="notice" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="status">{{ notice }}</div>

    <!-- Dues per section and month -->
    <section v-show="tab === 'dues'" class="space-y-4">
      <form class="card grid grid-cols-1 gap-4 md:grid-cols-5" @submit.prevent="loadDues">
        <select v-model="dues.academic_year_id" class="input" aria-label="Academic year">
          <option v-for="year in years" :key="year.id" :value="year.id">{{ year.name }}</option>
        </select>
        <select v-model="dues.section_id" class="input" aria-label="Section" required>
          <option value="">Choose a section</option>
          <option v-for="section in sections" :key="section.id" :value="section.id">{{ section.class?.name }} - {{ section.name }}</option>
        </select>
        <input v-model="dues.month" type="month" class="input" aria-label="Month" />
        <button type="submit" class="btn btn-primary" :disabled="busy">Show</button>
      </form>
      <div v-if="duesReport" class="card overflow-x-auto">
        <table class="table">
          <thead>
            <tr><th>Roll</th><th>Student</th><th class="text-right">Dues</th><th class="text-right">Net</th><th class="text-right">Paid</th><th class="text-right">Outstanding</th></tr>
          </thead>
          <tbody>
            <tr v-if="duesReport.rows.length === 0"><td colspan="6" class="text-center text-gray-500">No dues</td></tr>
            <tr v-for="row in duesReport.rows" :key="row.student.id">
              <td>{{ row.roll_number ?? '-' }}</td>
              <td>{{ row.student.name_en || row.student.name_bn }} <span class="text-xs text-gray-500">{{ row.student.student_id }}</span></td>
              <td class="text-right">{{ row.due_count }}</td>
              <td class="text-right">{{ taka(row.net_amount) }}</td>
              <td class="text-right">{{ taka(row.paid_amount) }}</td>
              <td class="text-right font-medium">{{ taka(row.outstanding_amount) }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="font-semibold">
              <td colspan="3">Total</td>
              <td class="text-right">{{ taka(duesReport.totals.net_amount) }}</td>
              <td class="text-right">{{ taka(duesReport.totals.paid_amount) }}</td>
              <td class="text-right">{{ taka(duesReport.totals.outstanding_amount) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>

    <!-- Collection by date -->
    <section v-show="tab === 'collection'" class="space-y-4">
      <form class="card grid grid-cols-1 gap-4 md:grid-cols-5" @submit.prevent="loadCollection">
        <input v-model="collection.from" type="date" class="input" aria-label="From" required />
        <input v-model="collection.to" type="date" class="input" aria-label="To" required />
        <select v-model="collection.method" class="input" aria-label="Method">
          <option value="">All methods</option>
          <option v-for="m in PAYMENT_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option>
        </select>
        <button type="submit" class="btn btn-primary" :disabled="busy">Show</button>
      </form>
      <div v-if="collectionReport" class="space-y-4">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
          <div class="card">
            <p class="text-xs uppercase text-gray-500">Collected</p>
            <p class="text-2xl font-bold text-gray-900">{{ taka(collectionReport.totals.amount) }}</p>
            <p class="text-sm text-gray-500">{{ collectionReport.totals.count }} receipts</p>
          </div>
          <div class="card">
            <p class="text-xs uppercase text-gray-500 mb-1">By method</p>
            <p v-for="row in collectionReport.by_method" :key="row.method" class="flex justify-between text-sm">
              <span>{{ METHOD_LABELS[row.method] }} ({{ row.count }})</span><span>{{ taka(row.amount) }}</span>
            </p>
          </div>
          <div class="card">
            <p class="text-xs uppercase text-gray-500 mb-1">By collector</p>
            <p v-if="collectionReport.by_collector.length === 0" class="text-sm text-gray-500">None</p>
            <p v-for="row in collectionReport.by_collector" :key="row.user_id" class="flex justify-between text-sm">
              <span>{{ row.name }} ({{ row.count }})</span><span>{{ taka(row.amount) }}</span>
            </p>
          </div>
        </div>
        <div class="card overflow-x-auto">
          <table class="table">
            <thead>
              <tr><th>Receipt</th><th>Paid at</th><th>Student</th><th>Method</th><th>Transaction</th><th class="text-right">Amount</th><th>Collector</th></tr>
            </thead>
            <tbody>
              <tr v-if="collectionReport.receipts.length === 0"><td colspan="7" class="text-center text-gray-500">No receipts</td></tr>
              <tr v-for="receipt in collectionReport.receipts" :key="receipt.id" :class="{ 'text-gray-400 line-through': receipt.is_cancelled }">
                <td><router-link :to="`/fees/receipts/${receipt.id}`" class="text-primary-600 hover:underline">{{ receipt.receipt_no }}</router-link></td>
                <td>{{ dhakaDateTime(receipt.paid_at) }}</td>
                <td>{{ receipt.student?.name_en || receipt.student?.name_bn }}</td>
                <td>{{ METHOD_LABELS[receipt.method] }}</td>
                <td>{{ receipt.transaction_id || '-' }}</td>
                <td class="text-right">{{ taka(receipt.amount) }}</td>
                <td>{{ receipt.collector_name }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- A student's ledger -->
    <section v-show="tab === 'ledger'" class="space-y-4">
      <div class="card space-y-3">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
          <div>
            <input v-model="studentSearch" type="text" class="input" placeholder="Search a student by ID or name..." autocomplete="off" aria-label="Student" @input="onStudentSearch" />
            <ul v-if="studentResults.length" class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white">
              <li v-for="s in studentResults" :key="s.id">
                <button type="button" class="w-full px-3 py-2 text-left text-sm hover:bg-gray-50" @click="chooseStudent(s)">
                  {{ s.name_en || s.name_bn }} <span class="text-gray-500">{{ s.student_id }}</span>
                </button>
              </li>
            </ul>
          </div>
          <select v-model="ledgerYearId" class="input" aria-label="Academic year" @change="loadLedger">
            <option v-for="year in years" :key="year.id" :value="year.id">{{ year.name }}</option>
          </select>
        </div>
        <p v-if="ledgerStudent" class="text-sm text-gray-600">Ledger of <strong>{{ ledgerStudent.name_en || ledgerStudent.name_bn }}</strong> ({{ ledgerStudent.student_id }})</p>
      </div>
      <div v-if="ledger" class="card overflow-x-auto">
        <table class="table">
          <thead>
            <tr><th>Date</th><th>Entry</th><th>Reference</th><th class="text-right">Billed</th><th class="text-right">Paid</th><th class="text-right">Balance</th></tr>
          </thead>
          <tbody>
            <tr v-if="ledger.entries.length === 0"><td colspan="6" class="text-center text-gray-500">No entries this year</td></tr>
            <tr v-for="entry in ledger.entries" :key="`${entry.type}-${entry.id}`" :class="{ 'text-gray-400 line-through': entry.is_cancelled }">
              <td>{{ entry.date }}</td>
              <td>{{ entry.type === 'due' ? (entry.name_en || entry.name_bn) : `Payment (${METHOD_LABELS[entry.name_en] || entry.name_en})` }}</td>
              <td>
                <router-link v-if="entry.type === 'payment'" :to="`/fees/receipts/${entry.id}`" class="text-primary-600 hover:underline">{{ entry.reference }}</router-link>
                <template v-else>{{ periodLabel(entry.reference) }}</template>
              </td>
              <td class="text-right">{{ entry.type === 'due' ? taka(entry.debit) : '' }}</td>
              <td class="text-right">{{ entry.type === 'payment' ? taka(entry.credit) : '' }}</td>
              <td class="text-right font-medium">{{ taka(entry.balance) }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="font-semibold">
              <td colspan="3">Total</td>
              <td class="text-right">{{ taka(ledger.totals.billed) }}</td>
              <td class="text-right">{{ taka(ledger.totals.paid) }}</td>
              <td class="text-right">{{ taka(ledger.totals.balance) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { METHOD_LABELS, PAYMENT_METHODS } from '@/constants/fees'
import { taka } from '@/utils/money'
import { dhakaDateTime, periodLabel } from '@/utils/fees'
import { todayInDhaka } from '@/utils/dhakaDate'

const TABS = [
  { key: 'dues', label: 'Dues by section' },
  { key: 'collection', label: 'Collection' },
  { key: 'ledger', label: 'Student ledger' },
]

const route = useRoute()
const tab = ref('dues')
const notice = ref('')
const busy = ref(false)
const years = ref([])
const sections = ref([])

const dues = reactive({ academic_year_id: '', section_id: '', month: '' })
const duesReport = ref(null)
const collection = reactive({ from: todayInDhaka(), to: todayInDhaka(), method: '' })
const collectionReport = ref(null)
const studentSearch = ref('')
const studentResults = ref([])
const ledgerStudent = ref(null)
const ledgerYearId = ref('')
const ledger = ref(null)

const run = async (call) => {
  busy.value = true
  notice.value = ''
  try {
    return await call()
  } catch (error) {
    const errors = error.response?.data?.errors
    notice.value = errors ? Object.values(errors)[0][0] : error.response?.data?.message || 'Failed to load the report'
    return null
  } finally {
    busy.value = false
  }
}

const loadDues = async () => {
  const params = { section_id: dues.section_id, academic_year_id: dues.academic_year_id }
  if (dues.month) params.month = dues.month
  const data = await run(async () => (await api.get('/fee-reports/dues', { params })).data.data)
  if (data) duesReport.value = data
}

const loadCollection = async () => {
  const params = { from: collection.from, to: collection.to }
  if (collection.method) params.method = collection.method
  const data = await run(async () => (await api.get('/fee-reports/collection', { params })).data.data)
  if (data) collectionReport.value = data
}

let timer = null
const onStudentSearch = () => {
  clearTimeout(timer)
  if (studentSearch.value.trim().length < 2) {
    studentResults.value = []
    return
  }
  timer = setTimeout(async () => {
    try {
      const { data } = await api.get('/students', { params: { search: studentSearch.value.trim(), per_page: 8 } })
      studentResults.value = data.data
    } catch (error) {
      studentResults.value = []
    }
  }, 250)
}

const loadLedger = async () => {
  if (!ledgerStudent.value) return
  const data = await run(async () => (await api.get(`/fee-reports/students/${ledgerStudent.value.id}/ledger`, { params: { academic_year_id: ledgerYearId.value } })).data.data)
  if (data) ledger.value = data
}

const chooseStudent = async (student) => {
  ledgerStudent.value = student
  studentResults.value = []
  studentSearch.value = ''
  await loadLedger()
}

onMounted(async () => {
  try {
    const [yearsRes, sectionsRes] = await Promise.all([
      api.get('/academic-years', { params: { per_page: 100 } }),
      api.get('/sections', { params: { per_page: 100 } }),
    ])
    years.value = yearsRes.data.data
    sections.value = sectionsRes.data.data
    const active = years.value.find((year) => year.is_active)?.id ?? years.value[0]?.id ?? ''
    dues.academic_year_id = active
    ledgerYearId.value = active
  } catch (error) {
    notice.value = error.response?.data?.message || 'Failed to load the page'
  }

  // Linked from a student's page.
  if (route.query.student_id) {
    tab.value = 'ledger'
    try {
      const { data } = await api.get(`/students/${route.query.student_id}`)
      await chooseStudent(data.data)
    } catch (error) {
      notice.value = error.response?.data?.message || 'Student not found'
    }
  }
})
</script>
