<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">{{ isEdit ? 'Edit Menu Item' : 'Add Menu Item' }}</h1>

    <form class="card space-y-6" @submit.prevent="save">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Label</label>
          <input v-model="form.label" type="text" class="input" maxlength="255" required />
          <p v-if="errors.label" class="text-sm text-red-600 mt-1">{{ errors.label[0] }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Parent (optional)</label>
          <select v-model="parentIdModel" class="input">
            <option value="">No parent (top level)</option>
            <option v-for="option in parentOptions" :key="option.id" :value="option.id">
              {{ '—'.repeat(option.depth - 1) }} {{ option.label }}
            </option>
          </select>
          <p v-if="errors.parent_id" class="text-sm text-red-600 mt-1">{{ errors.parent_id[0] }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
          <select v-model="form.type" class="input" required>
            <option value="heading">Heading (dropdown label, no link)</option>
            <option value="page">Page</option>
            <option value="route">Site section</option>
            <option value="url">URL</option>
          </select>
          <p v-if="errors.type" class="text-sm text-red-600 mt-1">{{ errors.type[0] }}</p>
        </div>

        <div v-if="form.type === 'page'">
          <label class="block text-sm font-medium text-gray-700 mb-1">Page</label>
          <select v-model="pageIdModel" class="input">
            <option value="">Select a page…</option>
            <option v-for="page in pages" :key="page.id" :value="page.id">{{ page.title }}</option>
          </select>
          <p v-if="errors.page_id" class="text-sm text-red-600 mt-1">{{ errors.page_id[0] }}</p>
        </div>

        <div v-if="form.type === 'route'">
          <label class="block text-sm font-medium text-gray-700 mb-1">Site section</label>
          <select v-model="form.route_name" class="input">
            <option value="">Select a section…</option>
            <option v-for="option in routeOptions" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
          <p v-if="errors.route_name" class="text-sm text-red-600 mt-1">{{ errors.route_name[0] }}</p>
        </div>

        <div v-if="form.type === 'url'">
          <label class="block text-sm font-medium text-gray-700 mb-1">URL</label>
          <input v-model="form.url" type="text" class="input" maxlength="2048" placeholder="/path or https://…" />
          <p v-if="errors.url" class="text-sm text-red-600 mt-1">{{ errors.url[0] }}</p>
        </div>
      </div>

      <div class="flex items-center gap-6">
        <label class="flex items-center space-x-2">
          <input v-model="form.is_active" type="checkbox" />
          <span class="text-sm text-gray-700">Active</span>
        </label>
        <label class="flex items-center space-x-2">
          <input v-model="form.open_in_new_tab" type="checkbox" />
          <span class="text-sm text-gray-700">Open in a new tab</span>
        </label>
      </div>

      <div class="flex items-center justify-between">
        <router-link to="/menu" class="btn btn-secondary">Cancel</router-link>
        <button type="submit" class="btn btn-primary" :disabled="saving">
          {{ saving ? 'Saving...' : 'Save' }}
        </button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'

const route = useRoute()
const router = useRouter()

const isEdit = computed(() => !!route.params.id)
const saving = ref(false)
const errors = ref({})

const allItems = ref([])
const pages = ref([])

const form = reactive({
  label: '',
  parent_id: null,
  type: 'heading',
  page_id: null,
  route_name: '',
  url: '',
  is_active: true,
  open_in_new_tab: false,
})

// <select> can't bind null/number well through v-model on a plain field, so use a
// string-backed computed that translates '' <-> null for the payload.
const parentIdModel = computed({
  get: () => (form.parent_id === null ? '' : form.parent_id),
  set: (value) => { form.parent_id = value === '' ? null : Number(value) },
})
const pageIdModel = computed({
  get: () => (form.page_id === null ? '' : form.page_id),
  set: (value) => { form.page_id = value === '' ? null : Number(value) },
})

const routeOptions = [
  { value: 'home', label: 'হোম (Home)' },
  { value: 'about', label: 'সম্পর্কে (About)' },
  { value: 'admissions', label: 'ভর্তি (Admissions)' },
  { value: 'news.index', label: 'সংবাদ (News)' },
  { value: 'events.index', label: 'ইভেন্ট (Events)' },
  { value: 'gallery.index', label: 'গ্যালারি (Gallery)' },
  { value: 'results.index', label: 'ফলাফল (Results)' },
  { value: 'portal.login', label: 'লগইন / পোর্টাল (Portal)' },
  { value: 'contact', label: 'যোগাযোগ (Contact)' },
  { value: 'staff.head', label: 'প্রধান শিক্ষক (Head Teacher)' },
  { value: 'staff.assistant_head', label: 'সহকারী প্রধান শিক্ষক (Assistant Head)' },
  { value: 'staff.teachers', label: 'শিক্ষকমণ্ডলী (Teachers)' },
  { value: 'staff.employees', label: 'কর্মচারী (Staff)' },
  { value: 'staff.ex_heads', label: 'সাবেক প্রধান শিক্ষক (Ex-Heads)' },
  { value: 'staff.ex_teachers', label: 'সাবেক শিক্ষক (Ex-Teachers)' },
  { value: 'staff.ex_employees', label: 'সাবেক কর্মচারী (Ex-Staff)' },
]

// Depth of every item, computed from the flat list, so the parent select can hide
// items already at the maximum nesting depth (matches MenuService::MAX_DEPTH = 3).
const depthOf = (id, byId, guard = 0) => {
  const item = byId.get(id)
  if (!item || !item.parent_id || guard > 5) return 1
  return 1 + depthOf(item.parent_id, byId, guard + 1)
}

const isDescendant = (candidateId, ancestorId, byId, guard = 0) => {
  const candidate = byId.get(candidateId)
  if (!candidate || !candidate.parent_id || guard > 5) return false
  if (candidate.parent_id === ancestorId) return true
  return isDescendant(candidate.parent_id, ancestorId, byId, guard + 1)
}

const parentOptions = computed(() => {
  const byId = new Map(allItems.value.map((item) => [item.id, item]))
  const editingId = isEdit.value ? Number(route.params.id) : null

  return allItems.value
    .map((item) => ({ ...item, depth: depthOf(item.id, byId) }))
    .filter((item) => item.depth < 3)
    .filter((item) => item.id !== editingId)
    .filter((item) => !editingId || !isDescendant(item.id, editingId, byId))
    .sort((a, b) => a.label.localeCompare(b.label))
})

// Loops over every page of results so a menu or page list with more than 100 items
// (per_page's cap) isn't silently truncated.
const fetchAllPages = async (url, params = {}) => {
  const all = []
  let page = 1
  let lastPage = 1

  do {
    const { data } = await api.get(url, { params: { ...params, per_page: 100, page } })
    all.push(...data.data)
    lastPage = data.meta.last_page
    page += 1
  } while (page <= lastPage)

  return all
}

const fetchOptions = async () => {
  try {
    const [menuItems, pageList] = await Promise.all([
      fetchAllPages('/menu-items', { location: 'header' }),
      fetchAllPages('/pages'),
    ])
    allItems.value = menuItems
    pages.value = pageList
  } catch (error) {
    console.error('Failed to load menu options:', error)
  }
}

const fetchItem = async () => {
  try {
    const { data } = await api.get(`/menu-items/${route.params.id}`)
    const item = data.data
    form.label = item.label
    form.parent_id = item.parent_id
    form.type = item.type
    form.page_id = item.page_id
    form.route_name = item.route_name || ''
    form.url = item.url || ''
    form.is_active = item.is_active
    form.open_in_new_tab = item.open_in_new_tab
  } catch (error) {
    console.error('Failed to fetch menu item:', error)
  }
}

const save = async () => {
  errors.value = {}
  saving.value = true

  const payload = {
    label: form.label,
    parent_id: form.parent_id,
    type: form.type,
    page_id: form.type === 'page' ? form.page_id : null,
    route_name: form.type === 'route' ? (form.route_name || null) : null,
    url: form.type === 'url' ? (form.url || null) : null,
    is_active: form.is_active,
    open_in_new_tab: form.open_in_new_tab,
  }

  try {
    if (isEdit.value) {
      await api.put(`/menu-items/${route.params.id}`, payload)
    } else {
      await api.post('/menu-items', payload)
    }
    router.push('/menu')
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors
    } else {
      console.error('Failed to save menu item:', error)
      alert('Failed to save menu item')
    }
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await fetchOptions()
  if (isEdit.value) await fetchItem()
})
</script>
