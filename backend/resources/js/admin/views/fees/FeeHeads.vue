<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Fee heads</h1>
      <p class="text-sm text-gray-500">
        The kinds of fee the school charges. A monthly head is charged every month, a one-time head once a year, a per-exam head once per exam.
        The amounts are set per class under Rates.
      </p>
    </div>

    <div v-if="notice" :class="['rounded-lg border px-4 py-3 text-sm', notice.ok ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700']" role="status">
      {{ notice.text }}
    </div>

    <div v-if="canWrite" class="card">
      <h2 class="text-lg font-semibold text-gray-900 mb-4">{{ editingId ? 'Edit fee head' : 'Add a fee head' }}</h2>
      <form class="grid grid-cols-1 gap-4 md:grid-cols-5" @submit.prevent="save">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="head-name-en">Name (English)</label>
          <input id="head-name-en" v-model="form.name_en" type="text" maxlength="255" class="input" />
          <p v-if="errors.name_en" class="text-sm text-red-600 mt-1">{{ errors.name_en[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="head-name-bn">Name (Bangla)</label>
          <input id="head-name-bn" v-model="form.name_bn" type="text" maxlength="255" class="input" />
          <p v-if="errors.name_bn" class="text-sm text-red-600 mt-1">{{ errors.name_bn[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="head-code">Code</label>
          <input id="head-code" v-model="form.code" type="text" maxlength="30" class="input uppercase" required />
          <p v-if="errors.code" class="text-sm text-red-600 mt-1">{{ errors.code[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1" for="head-kind">Kind</label>
          <select id="head-kind" v-model="form.kind" class="input" required>
            <option v-for="kind in FEE_KINDS" :key="kind.value" :value="kind.value">{{ kind.label }}</option>
          </select>
          <p v-if="errors.kind" class="text-sm text-red-600 mt-1">{{ errors.kind[0] }}</p>
        </div>
        <div class="flex items-end gap-3">
          <label class="flex items-center gap-2 text-sm text-gray-700 pb-2">
            <input v-model="form.is_active" type="checkbox" /> Active
          </label>
          <button type="submit" class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving...' : editingId ? 'Update' : 'Add' }}</button>
          <button v-if="editingId" type="button" class="btn btn-secondary" @click="reset">Cancel</button>
        </div>
      </form>
    </div>

    <div class="card">
      <div v-if="loading" class="text-center py-8"><p class="text-gray-500">Loading fee heads...</p></div>
      <div v-else-if="heads.length === 0" class="text-center py-8"><p class="text-gray-500">No fee heads yet</p></div>
      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Code</th>
              <th>Name</th>
              <th>Kind</th>
              <th>Status</th>
              <th v-if="canWrite">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="head in heads" :key="head.id" class="hover:bg-gray-50">
              <td class="font-medium">{{ head.code }}</td>
              <td>
                {{ head.name_en || head.name_bn }}
                <span v-if="head.name_en && head.name_bn" class="text-gray-500">({{ head.name_bn }})</span>
              </td>
              <td>{{ KIND_LABELS[head.kind] }}</td>
              <td><span :class="['badge', head.is_active ? 'badge-success' : 'badge-danger']">{{ head.is_active ? 'Active' : 'Inactive' }}</span></td>
              <td v-if="canWrite">
                <div class="flex space-x-2">
                  <button v-if="authStore.hasPermission('edit-fees')" type="button" class="text-primary-600 hover:text-primary-800" title="Edit" @click="edit(head)">✏️</button>
                  <button v-if="authStore.hasPermission('delete-fees')" type="button" class="text-red-600 hover:text-red-800" title="Delete" @click="remove(head)">🗑️</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { FEE_KINDS, KIND_LABELS } from '@/constants/fees'

const authStore = useAuthStore()
const canWrite = computed(() => authStore.hasPermission('create-fees'))

const heads = ref([])
const loading = ref(false)
const saving = ref(false)
const notice = ref(null)
const errors = ref({})
const editingId = ref(null)
const blank = () => ({ name_en: '', name_bn: '', code: '', kind: 'monthly', is_active: true })
const form = reactive(blank())

const reset = () => {
  editingId.value = null
  Object.assign(form, blank())
  errors.value = {}
}

const fetchHeads = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/fee-heads', { params: { per_page: 100 } })
    heads.value = data.data
  } catch (error) {
    notice.value = { ok: false, text: error.response?.data?.message || 'Failed to load the fee heads' }
  } finally {
    loading.value = false
  }
}

const edit = (head) => {
  editingId.value = head.id
  Object.assign(form, { name_en: head.name_en ?? '', name_bn: head.name_bn ?? '', code: head.code, kind: head.kind, is_active: head.is_active })
  errors.value = {}
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

const save = async () => {
  errors.value = {}
  notice.value = null
  saving.value = true

  try {
    const { data } = editingId.value
      ? await api.put(`/fee-heads/${editingId.value}`, { ...form })
      : await api.post('/fee-heads', { ...form })
    notice.value = { ok: true, text: data.message }
    reset()
    await fetchHeads()
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors ?? {}
    } else {
      notice.value = { ok: false, text: error.response?.data?.message || 'Failed to save the fee head' }
    }
  } finally {
    saving.value = false
  }
}

const remove = async (head) => {
  if (!confirm(`Delete the fee head ${head.code}?`)) return

  try {
    await api.delete(`/fee-heads/${head.id}`)
    notice.value = { ok: true, text: 'Fee head deleted' }
    if (editingId.value === head.id) reset()
    await fetchHeads()
  } catch (error) {
    notice.value = { ok: false, text: error.response?.data?.message || 'Failed to delete the fee head' }
  }
}

onMounted(fetchHeads)
</script>
