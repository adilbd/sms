<template>
  <div class="space-y-6">
    <div class="flex flex-wrap justify-between items-center gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ own ? 'My routine' : 'Teacher routine' }}<span v-if="routine?.staff"> – {{ routine.staff.name_en || routine.staff.name_bn }}</span></h1>
        <p v-if="routine?.academic_year" class="text-sm text-gray-500">Academic year {{ routine.academic_year.name }}</p>
      </div>
      <router-link v-if="routine?.staff" :to="printTarget" class="btn btn-secondary">🖨️ Print</router-link>
    </div>

    <div v-if="!own" class="card grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Academic year</label>
        <select v-model="yearId" class="input" @change="load">
          <option v-for="year in years" :key="year.id" :value="year.id">{{ year.name }}<template v-if="year.is_active"> (active)</template></option>
        </select>
      </div>
      <div class="md:col-span-2">
        <label class="block text-sm font-medium text-gray-700 mb-1">Teacher</label>
        <select v-model="staffId" class="input" @change="load">
          <option value="">Choose a teacher...</option>
          <option v-for="teacher in teachers" :key="teacher.id" :value="teacher.id">{{ teacher.name_en || teacher.name_bn }}</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="card"><p class="text-gray-500 text-center py-8">Loading...</p></div>
    <div v-else-if="error" class="card"><p class="text-red-600 text-center py-8">{{ error }}</p></div>
    <div v-else-if="own && routine && !routine.staff" class="card">
      <p class="text-gray-500 py-6">Your login isn't linked to a staff record yet, so there is no routine to show. Ask an admin to link it.</p>
    </div>
    <div v-else-if="routine && (own || staffId)" class="card">
      <RoutineGrid :routine="routine" />
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import RoutineGrid from '@/components/RoutineGrid.vue'

const route = useRoute()

// `/my-routine` shows the signed-in teacher's own week; `/routines/teachers` lets an admin
// or office user pick any teacher.
const own = computed(() => route.meta.own === true)

const years = ref([])
const teachers = ref([])
const yearId = ref('')
const staffId = ref(route.query.staff_id ? Number(route.query.staff_id) : '')
const routine = ref(null)
const loading = ref(false)
const error = ref('')

const printTarget = computed(() => (own.value
  ? { path: '/my-routine/print' }
  : { path: `/routines/teachers/${staffId.value}/print`, query: { academic_year_id: yearId.value } }))

const load = async () => {
  error.value = ''
  routine.value = null

  if (!own.value && !staffId.value) return

  loading.value = true
  try {
    const url = own.value ? '/my/routine' : `/routines/teachers/${staffId.value}`
    const { data } = await api.get(url, { params: own.value || !yearId.value ? {} : { academic_year_id: yearId.value } })
    routine.value = data.data
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to load the routine'
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  if (!own.value) {
    try {
      const [yearsRes, staffRes] = await Promise.all([
        api.get('/academic-years', { params: { per_page: 100 } }),
        api.get('/staff', { params: { per_page: 100, category: 'teacher', is_active: true } }),
      ])
      years.value = yearsRes.data.data
      teachers.value = staffRes.data.data
      const active = years.value.find((y) => y.is_active)
      yearId.value = active ? active.id : (years.value[0]?.id || '')
    } catch (e) {
      error.value = e.response?.data?.message || 'Failed to load teachers'
      return
    }
  }

  await load()
})
</script>
