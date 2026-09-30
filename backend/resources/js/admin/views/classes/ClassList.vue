<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">Classes</h1>
      <router-link to="/classes/create" class="btn btn-primary">➕ Add Class</router-link>
    </div>

    <div class="card" v-if="loading">
      <p class="text-gray-500 text-center py-8">Loading classes...</p>
    </div>

    <div v-else class="space-y-6">
      <div v-for="level in LEVELS" :key="level.value" class="card" v-show="grouped[level.value]?.length">
        <h2 class="text-lg font-semibold text-gray-800 mb-3">{{ level.label }}</h2>
        <div class="space-y-3">
          <div v-for="cls in grouped[level.value]" :key="cls.id" class="border rounded-lg p-4">
            <div class="flex justify-between items-start">
              <div>
                <p class="font-medium text-gray-900">
                  {{ cls.name }}
                  <span v-if="cls.name_bn" class="text-gray-500">({{ cls.name_bn }})</span>
                  <span class="badge ml-2">{{ cls.code }}</span>
                  <span v-if="cls.has_groups" class="badge badge-success ml-1">Groups</span>
                  <span v-if="!cls.is_active" class="badge badge-warning ml-1">Inactive</span>
                </p>
                <div class="mt-2 flex flex-wrap gap-2" v-if="cls.sections?.length">
                  <span
                    v-for="section in cls.sections"
                    :key="section.id"
                    class="badge"
                  >
                    {{ section.name }}
                    <template v-if="section.shift">· {{ section.shift.name_en }}</template>
                    <template v-if="section.group">· {{ GROUP_LABELS[section.group] || section.group }}</template>
                  </span>
                </div>
                <p v-else class="text-sm text-gray-400 mt-2">No sections yet</p>
              </div>
              <div class="flex space-x-2">
                <router-link :to="`/classes/${cls.id}/edit`" class="text-primary-600 hover:text-primary-800" title="Edit">✏️</router-link>
                <button @click="deleteClass(cls)" class="text-red-600 hover:text-red-800" title="Delete">🗑️</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import { LEVELS, GROUP_LABELS } from '@/constants/academic'

const classes = ref([])
const loading = ref(false)

const grouped = computed(() => {
  const byLevel = {}
  for (const cls of classes.value) {
    const level = cls.level || 'primary'
    if (!byLevel[level]) byLevel[level] = []
    byLevel[level].push(cls)
  }
  return byLevel
})

const fetchClasses = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/classes', { params: { per_page: 100 } })
    classes.value = data.data
  } catch (error) {
    console.error('Failed to fetch classes:', error)
  } finally {
    loading.value = false
  }
}

const deleteClass = async (cls) => {
  if (!confirm(`Delete ${cls.name}?`)) return

  try {
    await api.delete(`/classes/${cls.id}`)
    await fetchClasses()
  } catch (error) {
    alert(error.response?.data?.message || 'Failed to delete class')
  }
}

onMounted(() => {
  fetchClasses()
})
</script>
