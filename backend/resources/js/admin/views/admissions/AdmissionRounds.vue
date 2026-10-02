<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">Admission rounds</h1>
      <div class="flex gap-2">
        <router-link to="/admissions" class="btn btn-secondary">Applications</router-link>
        <router-link v-if="authStore.hasPermission('create-students')" to="/admissions/rounds/create" class="btn btn-primary">➕ Add round</router-link>
      </div>
    </div>

    <div class="card">
      <div v-if="loading" class="text-center py-8 text-gray-500">Loading rounds...</div>
      <div v-else-if="rounds.length === 0" class="text-center py-8 text-gray-500">No admission rounds yet</div>
      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Round</th>
              <th>Year</th>
              <th>Window</th>
              <th>Classes (seats)</th>
              <th>Applications</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="round in rounds" :key="round.id" class="hover:bg-gray-50">
              <td class="font-medium">{{ round.name_bn || round.name_en }}<span v-if="round.name_bn && round.name_en" class="text-gray-500"> ({{ round.name_en }})</span></td>
              <td>{{ round.academic_year?.year }}</td>
              <td class="text-gray-600">{{ round.opens_at }} – {{ round.closes_at }}</td>
              <td class="text-gray-600">
                <span v-for="row in round.classes" :key="row.class_id" class="mr-2 inline-block">{{ row.class?.name }} ({{ row.seats ?? '∞' }})</span>
              </td>
              <td>
                <router-link :to="{ path: '/admissions', query: { round_id: round.id } }" class="text-primary-600 hover:underline">{{ round.applications_count }}</router-link>
              </td>
              <td>
                <span :class="['badge', round.is_open ? 'badge-success' : round.is_published ? 'badge-warning' : 'badge-info']">
                  {{ round.is_open ? 'Open' : round.is_published ? 'Published (not in window)' : 'Draft' }}
                </span>
              </td>
              <td>
                <div class="flex space-x-2">
                  <router-link v-if="authStore.hasPermission('edit-students')" :to="`/admissions/rounds/${round.id}/edit`" title="Edit">✏️</router-link>
                  <button v-if="authStore.hasPermission('delete-students')" class="text-red-600 hover:text-red-800" title="Delete" @click="remove(round)">🗑️</button>
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
import { onMounted, ref } from 'vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const rounds = ref([])
const loading = ref(false)

const fetchRounds = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/admission-rounds', { params: { per_page: 100 } })
    rounds.value = data.data
  } catch (error) {
    console.error('Failed to fetch rounds:', error)
  } finally {
    loading.value = false
  }
}

const remove = async (round) => {
  if (!confirm('Delete this admission round?')) return

  try {
    await api.delete(`/admission-rounds/${round.id}`)
    await fetchRounds()
  } catch (error) {
    alert(error.response?.data?.message || 'Failed to delete the round')
  }
}

onMounted(fetchRounds)
</script>
