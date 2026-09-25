<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
      <div class="card bg-gradient-to-br from-blue-500 to-blue-600 text-white">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-blue-100 text-sm font-medium">Total Students</p>
            <p class="text-3xl font-bold mt-2">{{ stats.students }}</p>
          </div>
          <div class="text-5xl opacity-50">👨‍🎓</div>
        </div>
      </div>

      <div class="card bg-gradient-to-br from-green-500 to-green-600 text-white">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-green-100 text-sm font-medium">Total Teachers</p>
            <p class="text-3xl font-bold mt-2">{{ stats.teachers }}</p>
          </div>
          <div class="text-5xl opacity-50">👨‍🏫</div>
        </div>
      </div>

      <div class="card bg-gradient-to-br from-purple-500 to-purple-600 text-white">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-purple-100 text-sm font-medium">Classes</p>
            <p class="text-3xl font-bold mt-2">{{ stats.classes }}</p>
          </div>
          <div class="text-5xl opacity-50">🏫</div>
        </div>
      </div>

      <div class="card bg-gradient-to-br from-orange-500 to-orange-600 text-white">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-orange-100 text-sm font-medium">Attendance Today</p>
            <p class="text-3xl font-bold mt-2">{{ stats.attendanceToday }}%</p>
          </div>
          <div class="text-5xl opacity-50">📋</div>
        </div>
      </div>
    </div>

    <!-- Charts and Recent Activities -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Recent Students -->
      <div class="card">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Recent Students</h3>
        <div class="space-y-3">
          <div
            v-for="student in recentStudents"
            :key="student.id"
            class="flex items-center justify-between p-3 bg-gray-50 rounded-lg"
          >
            <div class="flex items-center">
              <div class="w-10 h-10 rounded-full bg-primary-600 text-white flex items-center justify-center font-bold">
                {{ student.initials }}
              </div>
              <div class="ml-3">
                <p class="text-sm font-medium text-gray-900">{{ student.name }}</p>
                <p class="text-xs text-gray-500">{{ student.class }}</p>
              </div>
            </div>
            <span class="badge badge-success">Active</span>
          </div>
        </div>
      </div>

      <!-- Upcoming Exams -->
      <div class="card">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Upcoming Exams</h3>
        <div class="space-y-3">
          <div
            v-for="exam in upcomingExams"
            :key="exam.id"
            class="flex items-center justify-between p-3 bg-gray-50 rounded-lg"
          >
            <div>
              <p class="text-sm font-medium text-gray-900">{{ exam.name }}</p>
              <p class="text-xs text-gray-500">{{ exam.date }}</p>
            </div>
            <span class="badge badge-info">{{ exam.type }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="card">
      <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h3>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <router-link to="/students/create" class="btn btn-primary text-center">
          ➕ Add Student
        </router-link>
        <router-link to="/attendance/mark" class="btn btn-primary text-center">
          📋 Mark Attendance
        </router-link>
        <router-link to="/exams" class="btn btn-primary text-center">
          📝 Manage Exams
        </router-link>
        <router-link to="/fees" class="btn btn-primary text-center">
          💰 Fee Collection
        </router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'

const stats = ref({
  students: 0,
  teachers: 0,
  classes: 0,
  attendanceToday: 0,
})

const recentStudents = ref([])
const upcomingExams = ref([])

const fetchDashboardData = async () => {
  try {
    // In a real app, this would be an actual API call
    stats.value = {
      students: 1250,
      teachers: 75,
      classes: 45,
      attendanceToday: 92.5,
    }

    recentStudents.value = [
      { id: 1, name: 'John Doe', class: 'Class 10-A', initials: 'JD' },
      { id: 2, name: 'Jane Smith', class: 'Class 9-B', initials: 'JS' },
      { id: 3, name: 'Mike Johnson', class: 'Class 8-C', initials: 'MJ' },
    ]

    upcomingExams.value = [
      { id: 1, name: 'Mid Term Exam', date: '2025-12-15', type: 'Term' },
      { id: 2, name: 'Unit Test 3', date: '2025-12-20', type: 'Unit' },
    ]
  } catch (error) {
    console.error('Failed to fetch dashboard data:', error)
  }
}

onMounted(() => {
  fetchDashboardData()
})
</script>

