<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">My subjects</h1>
      <p v-if="academicYear" class="text-sm text-gray-500">Academic year {{ academicYear.name }}</p>
    </div>

    <div v-if="loading" class="card"><p class="text-gray-500 text-center py-8">Loading...</p></div>
    <div v-else-if="error" class="card"><p class="text-red-600 text-center py-8">{{ error }}</p></div>

    <template v-else>
      <div class="card">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Subjects I teach</h2>
        <p v-if="subjects.length === 0" class="text-gray-500">
          You have no subjects assigned for this year yet. Ask an admin to assign you in Sections.
        </p>
        <table v-else class="table">
          <thead>
            <tr>
              <th>Class</th>
              <th>Section</th>
              <th>Subject</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in subjects" :key="`${row.section.id}-${row.subject.id}`">
              <td>{{ row.class?.name }}</td>
              <td>
                {{ row.section.name }}
                <span v-if="row.section.group" class="text-gray-500">· {{ GROUP_LABELS[row.section.group] || row.section.group }}</span>
                <span v-if="row.section.shift" class="text-gray-500">· {{ row.section.shift.name_en }}</span>
              </td>
              <td class="font-medium">{{ row.subject.name }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="card">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Sections I lead as class teacher</h2>
        <p v-if="classTeacherOf.length === 0" class="text-gray-500">You are not a class teacher this year.</p>
        <ul v-else class="space-y-2">
          <li v-for="section in classTeacherOf" :key="section.id" class="flex items-center justify-between">
            <span class="font-medium">{{ section.class?.name }} – {{ section.name }}</span>
            <router-link :to="{ path: '/students', query: { section_id: section.id } }" class="text-primary-600 hover:text-primary-800 text-sm">
              View students
            </router-link>
          </li>
        </ul>
      </div>
    </template>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '@/services/api'
import { GROUP_LABELS } from '@/constants/academic'

const loading = ref(true)
const error = ref('')
const academicYear = ref(null)
const subjects = ref([])
const classTeacherOf = ref([])

onMounted(async () => {
  try {
    const { data } = await api.get('/my/assignments')
    academicYear.value = data.data.academic_year
    subjects.value = data.data.subjects
    classTeacherOf.value = data.data.class_teacher_of
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to load your subjects'
  } finally {
    loading.value = false
  }
})
</script>
