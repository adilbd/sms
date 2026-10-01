<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Fee rates</h1>
      <p class="text-sm text-gray-500">
        What each fee costs for a class in an academic year, in taka. A group rate (Class 9 and above) wins over the whole-class rate;
        leave a cell empty for no rate. Dues already generated keep the amount they were generated with.
      </p>
    </div>

    <div v-if="notice" :class="['rounded-lg border px-4 py-3 text-sm', notice.ok ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700']" role="status">
      {{ notice.text }}
    </div>

    <div class="card">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="rate-year">Academic year</label>
          <select id="rate-year" v-model="yearId" class="input" @change="load">
            <option v-for="year in years" :key="year.id" :value="year.id">{{ year.name }}{{ year.is_active ? ' (current)' : '' }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="rate-class">Class</label>
          <select id="rate-class" v-model="classId" class="input" @change="load">
            <option v-for="cls in classes" :key="cls.id" :value="cls.id">{{ cls.name }}</option>
          </select>
        </div>
      </div>
    </div>

    <div class="card">
      <div v-if="loading" class="text-center py-8"><p class="text-gray-500">Loading rates...</p></div>
      <div v-else-if="rows.length === 0" class="text-center py-8"><p class="text-gray-500">Add fee heads first</p></div>
      <template v-else>
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
              <tr>
                <th>Fee head</th>
                <th>Whole class (৳)</th>
                <th v-for="group in groupColumns" :key="group.value">{{ group.label }} (৳)</th>
                <th>Due day</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.head.id">
                <td class="font-medium">
                  {{ row.head.name_en || row.head.name_bn }}
                  <span class="text-xs text-gray-500">{{ KIND_LABELS[row.head.kind] }}</span>
                  <span v-if="!row.head.is_active" class="badge badge-danger ml-1">Inactive</span>
                </td>
                <td v-for="key in cellKeys" :key="key">
                  <input
                    v-model="row.cells[key].amount"
                    type="number"
                    min="0"
                    step="0.01"
                    class="input w-32"
                    :disabled="!canEdit"
                    :aria-label="`${row.head.code} ${key || 'whole class'} rate`"
                  />
                  <p v-if="row.cells[key].error" class="text-xs text-red-600 mt-1">{{ row.cells[key].error }}</p>
                </td>
                <td>
                  <input v-model="row.due_day" type="number" min="1" max="28" class="input w-20" :disabled="!canEdit" placeholder="10" :aria-label="`${row.head.code} due day`" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="canEdit" class="mt-4 flex justify-end">
          <button type="button" class="btn btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving...' : 'Save rates' }}</button>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { KIND_LABELS } from '@/constants/fees'
import { GROUPS } from '@/constants/academic'

const authStore = useAuthStore()
const canEdit = computed(() => authStore.hasPermission('edit-fees') && authStore.hasPermission('create-fees') && authStore.hasPermission('delete-fees'))

const years = ref([])
const classes = ref([])
const heads = ref([])
const yearId = ref('')
const classId = ref('')
const rows = ref([])
const loading = ref(false)
const saving = ref(false)
const notice = ref(null)

const selectedClass = computed(() => classes.value.find((cls) => cls.id === classId.value))
const groupColumns = computed(() => (selectedClass.value?.has_groups ? GROUPS : []))
// '' is the whole-class rate; the others are group rates.
const cellKeys = computed(() => ['', ...groupColumns.value.map((group) => group.value)])

const keyOf = (rate) => rate.group ?? ''

const load = async () => {
  if (!yearId.value || !classId.value) return

  loading.value = true
  notice.value = null
  try {
    const { data } = await api.get('/fee-rates', { params: { per_page: 100, academic_year_id: yearId.value, class_id: classId.value } })
    const rates = data.data

    rows.value = heads.value.map((head) => {
      const own = rates.filter((rate) => rate.fee_head_id === head.id)
      const cells = {}
      for (const key of cellKeys.value) {
        const rate = own.find((r) => keyOf(r) === key)
        cells[key] = { id: rate?.id ?? null, amount: rate ? rate.amount : '', original: rate ? rate.amount : '', error: '' }
      }
      const dueDay = own.find((r) => r.due_day)?.due_day ?? ''

      return { head, cells, due_day: dueDay, original_due_day: dueDay }
    })
  } catch (error) {
    notice.value = { ok: false, text: error.response?.data?.message || 'Failed to load the rates' }
  } finally {
    loading.value = false
  }
}

// One request per changed cell: create, update or delete (cleared) a rate.
const save = async () => {
  saving.value = true
  notice.value = null
  let failed = 0

  for (const row of rows.value) {
    const dueDay = row.due_day === '' || row.due_day === null ? null : Number(row.due_day)

    for (const key of cellKeys.value) {
      const cell = row.cells[key]
      cell.error = ''
      const amount = cell.amount === '' || cell.amount === null ? '' : String(cell.amount)
      const dueDayChanged = String(row.due_day ?? '') !== String(row.original_due_day ?? '')

      try {
        if (cell.id && amount === '') {
          await api.delete(`/fee-rates/${cell.id}`)
        } else if (cell.id && (amount !== String(cell.original) || dueDayChanged)) {
          await api.put(`/fee-rates/${cell.id}`, { amount, due_day: dueDay })
        } else if (!cell.id && amount !== '') {
          await api.post('/fee-rates', {
            fee_head_id: row.head.id,
            class_id: classId.value,
            academic_year_id: yearId.value,
            group: key || null,
            amount,
            due_day: dueDay,
          })
        }
      } catch (error) {
        failed += 1
        const errors = error.response?.data?.errors
        cell.error = errors ? Object.values(errors)[0][0] : error.response?.data?.message || 'Failed to save'
      }
    }
  }

  saving.value = false
  notice.value = failed ? { ok: false, text: `${failed} rate${failed === 1 ? '' : 's'} could not be saved.` } : { ok: true, text: 'Rates saved' }
  if (!failed) await load()
}

onMounted(async () => {
  try {
    const [yearsRes, classesRes, headsRes] = await Promise.all([
      api.get('/academic-years', { params: { per_page: 100 } }),
      api.get('/classes', { params: { per_page: 100 } }),
      api.get('/fee-heads', { params: { per_page: 100 } }),
    ])
    years.value = yearsRes.data.data
    classes.value = classesRes.data.data
    heads.value = headsRes.data.data
    yearId.value = years.value.find((year) => year.is_active)?.id ?? years.value[0]?.id ?? ''
    classId.value = classes.value[0]?.id ?? ''
  } catch (error) {
    notice.value = { ok: false, text: error.response?.data?.message || 'Failed to load the page' }
  }

  await load()
})
</script>
