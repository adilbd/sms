<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">Student</h1>
      <div class="flex space-x-2">
        <router-link to="/students" class="btn btn-secondary">Back</router-link>
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
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'

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

onMounted(async () => {
  try {
    const { data } = await api.get(`/students/${route.params.id}`)
    student.value = data.data
  } catch (error) {
    if (error.response?.status === 404) notFound.value = true
    else console.error('Failed to fetch student:', error)
  } finally {
    loading.value = false
  }
})
</script>
