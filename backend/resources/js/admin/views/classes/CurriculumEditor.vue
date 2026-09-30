<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">
          Curriculum<span v-if="cls"> – {{ cls.name }}<span v-if="cls.name_bn" class="text-gray-500"> ({{ cls.name_bn }})</span></span>
        </h1>
        <p class="text-sm text-gray-500">
          <template v-if="hasGroups">Common subjects, subjects for each group and the optional 4th-subject choices.</template>
          <template v-else>The compulsory subjects of this class.</template>
        </p>
      </div>
      <router-link to="/classes" class="btn btn-secondary">Back to classes</router-link>
    </div>

    <div v-if="loading" class="card"><p class="text-gray-500 text-center py-8">Loading curriculum...</p></div>

    <template v-else>
      <div v-if="errorSummary" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <p>{{ errorSummary }}</p>
        <p v-for="message in topLevelErrors" :key="message" class="mt-1">{{ message }}</p>
      </div>

      <div v-if="hasGroups" class="flex flex-wrap gap-2">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          type="button"
          :class="['btn', activeTab === tab.key ? 'btn-primary' : 'btn-secondary']"
          @click="activeTab = tab.key"
        >
          {{ tab.label }}
          <span v-if="tabErrorCount(tab.key)" class="ml-1 inline-block rounded-full bg-red-600 px-1.5 text-xs text-white" :title="`${tabErrorCount(tab.key)} row(s) with errors`">{{ tabErrorCount(tab.key) }}</span>
        </button>
      </div>

      <div v-for="type in visibleTypes" :key="type.value" class="card space-y-3">
        <h2 class="text-lg font-semibold text-gray-800">{{ type.label }}</h2>

        <p v-if="bucket(type.value).length === 0" class="text-sm text-gray-400">None yet</p>

        <ul class="space-y-2">
          <li v-for="(row, pos) in bucket(type.value)" :key="row.uid" class="border rounded-lg px-3 py-2">
            <div class="flex items-center justify-between">
              <span class="font-medium text-gray-900">
                {{ row.subject?.name }}
                <span v-if="row.subject?.name_bn" class="text-gray-500">({{ row.subject.name_bn }})</span>
                <span class="badge ml-1">{{ row.subject?.code }}</span>
                <span v-if="row.subject && !row.subject.is_active" class="badge badge-warning ml-1">Inactive</span>
              </span>
              <span class="flex items-center space-x-2">
                <button type="button" class="text-gray-600 disabled:opacity-30" :disabled="pos === 0" title="Move up" @click="move(type.value, pos, -1)">▲</button>
                <button type="button" class="text-gray-600 disabled:opacity-30" :disabled="pos === bucket(type.value).length - 1" title="Move down" @click="move(type.value, pos, 1)">▼</button>
                <button type="button" class="text-red-600 hover:text-red-800" title="Remove" @click="remove(row)">🗑️</button>
              </span>
            </div>
            <p v-for="message in rowErrors(rows.indexOf(row))" :key="message" class="text-sm text-red-600 mt-1">{{ message }}</p>
          </li>
        </ul>

        <div class="flex gap-2">
          <select v-model="picks[type.value]" class="input">
            <option value="">Add a subject...</option>
            <option v-for="subject in addableSubjects" :key="subject.id" :value="subject.id">
              {{ subject.name }}<template v-if="subject.name_bn"> ({{ subject.name_bn }})</template> – {{ subject.code }}
            </option>
          </select>
          <button type="button" class="btn btn-secondary" :disabled="!picks[type.value]" @click="add(type.value)">Add</button>
        </div>
      </div>

      <div v-if="hasGroups && activeGroup" class="card">
        <h2 class="text-lg font-semibold text-gray-800 mb-2">Effective list: {{ GROUP_LABELS[activeGroup] }}</h2>
        <p class="text-sm text-gray-500 mb-3">Common subjects plus this group's subjects, as a student in this group sees them.</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div v-for="type in TYPES" :key="type.value">
            <h3 class="text-sm font-medium text-gray-700 mb-1">{{ type.label }}</h3>
            <ul class="text-sm text-gray-700 list-disc pl-5">
              <li v-for="row in effective(type.value)" :key="row.uid">
                {{ row.subject?.name }}<span v-if="row.group === null" class="text-gray-400"> · common</span>
              </li>
              <li v-if="effective(type.value).length === 0" class="list-none -ml-5 text-gray-400">None</li>
            </ul>
          </div>
        </div>
      </div>

      <div class="flex justify-end space-x-2">
        <button type="button" class="btn btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving...' : 'Save curriculum' }}</button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { GROUPS, GROUP_LABELS } from '@/constants/academic'

const route = useRoute()

const cls = ref(null)
const rows = ref([])
const allSubjects = ref([])
const loading = ref(false)
const saving = ref(false)
const errors = ref({})
const errorSummary = ref('')
const activeTab = ref('common')
const picks = reactive({ compulsory: '', optional: '' })

let uidCounter = 0
const makeRow = (data) => ({ uid: ++uidCounter, subject_id: data.subject_id, group: data.group ?? null, type: data.type, subject: data.subject ?? null })

const hasGroups = computed(() => !!cls.value?.has_groups)
const TYPES = [
  { value: 'compulsory', label: 'Compulsory' },
  { value: 'optional', label: 'Optional (4th subject)' },
]
// Below Class 9 there is one compulsory list: no groups and no 4th subjects.
const visibleTypes = computed(() => (hasGroups.value ? TYPES : [TYPES[0]]))
const tabs = [{ key: 'common', label: 'Common' }, ...GROUPS.map((g) => ({ key: g.value, label: g.label }))]

// The group being edited: null (common / whole class) or one of the three groups.
const currentGroup = computed(() => (hasGroups.value && activeTab.value !== 'common' ? activeTab.value : null))
const activeGroup = computed(() => currentGroup.value)

const bucket = (type) => rows.value.filter((r) => r.group === currentGroup.value && r.type === type)

const effective = (type) =>
  rows.value.filter((r) => r.type === type && (r.group === null || r.group === currentGroup.value))

// Active subjects not yet in this list (nor common to the class, which would clash).
const addableSubjects = computed(() => {
  const taken = new Set(
    rows.value.filter((r) => r.group === currentGroup.value || r.group === null).map((r) => r.subject_id),
  )
  if (currentGroup.value === null) {
    // On the common tab, a subject already listed for a group would clash too.
    rows.value.forEach((r) => taken.add(r.subject_id))
  }
  return allSubjects.value.filter((s) => s.is_active && !taken.has(s.id))
})

const add = (type) => {
  const subject = allSubjects.value.find((s) => s.id === Number(picks[type]))
  if (!subject) return
  clearErrors()
  rows.value.push(makeRow({ subject_id: subject.id, group: currentGroup.value, type, subject }))
  picks[type] = ''
}

const remove = (row) => {
  clearErrors()
  rows.value = rows.value.filter((r) => r !== row)
}

// Swap two neighbours within a bucket by swapping their positions in the full list, so
// the array order (which becomes sort_order) follows what the admin sees.
const move = (type, pos, delta) => {
  clearErrors()
  const items = bucket(type)
  const a = rows.value.indexOf(items[pos])
  const b = rows.value.indexOf(items[pos + delta])
  const copy = [...rows.value]
  ;[copy[a], copy[b]] = [copy[b], copy[a]]
  rows.value = copy
}

// 422 errors are keyed `subjects.{index}.{field}`, where index is the position in the
// array that was sent (the same order as `rows`).
// Error indexes refer to the array that was sent, so any edit to the list makes them stale.
const clearErrors = () => {
  errors.value = {}
  errorSummary.value = ''
}

const tabKeyOf = (row) => (row?.group ?? 'common')

const errorRowIndexes = () =>
  Object.keys(errors.value)
    .map((key) => /^subjects\.(\d+)\./.exec(key)?.[1])
    .filter((i) => i !== undefined)
    .map(Number)

const tabErrorCount = (key) =>
  new Set(errorRowIndexes().filter((i) => tabKeyOf(rows.value[i]) === key)).size

// Errors on the list as a whole, such as `subjects` (too many rows).
const topLevelErrors = computed(() => errors.value.subjects ?? [])

const rowErrors = (index) =>
  Object.entries(errors.value)
    .filter(([key]) => key.startsWith(`subjects.${index}.`))
    .flatMap(([, messages]) => messages)

const load = async () => {
  loading.value = true
  try {
    const id = route.params.id
    const [classRes, curriculumRes, subjectsRes] = await Promise.all([
      api.get(`/classes/${id}`),
      api.get(`/classes/${id}/subjects`),
      // The API caps per_page at 100 and only the first page is used here, so a school with
      // more than 100 active subjects would need this to page through `meta`.
      api.get('/subjects', { params: { per_page: 100, is_active: 1 } }),
    ])
    cls.value = classRes.data.data
    allSubjects.value = subjectsRes.data.data
    rows.value = curriculumRes.data.data.map(makeRow)
  } catch (error) {
    console.error('Failed to load curriculum:', error)
    alert(error.response?.data?.message || 'Failed to load curriculum')
  } finally {
    loading.value = false
  }
}

const save = async () => {
  clearErrors()
  saving.value = true
  try {
    const payload = rows.value.map((r) => ({ subject_id: r.subject_id, group: r.group, type: r.type }))
    const { data } = await api.put(`/classes/${route.params.id}/subjects`, { subjects: payload })
    rows.value = data.data.map(makeRow)
    alert(data.message || 'Curriculum updated successfully')
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors ?? {}
      errorSummary.value = error.response.data.message || 'The curriculum has errors.'
      // Errors can sit on a tab that isn't showing: jump to the first one that has any.
      const firstTab = tabs.find((t) => tabErrorCount(t.key) > 0)
      if (firstTab && hasGroups.value) activeTab.value = firstTab.key
    } else {
      console.error('Failed to save curriculum:', error)
      alert(error.response?.data?.message || 'Failed to save curriculum')
    }
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>
