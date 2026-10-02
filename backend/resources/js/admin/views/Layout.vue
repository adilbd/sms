<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Sidebar -->
    <aside class="fixed inset-y-0 left-0 w-64 bg-white shadow-lg transform transition-transform duration-200 ease-in-out z-30">
      <div class="flex flex-col h-full">
        <!-- Logo -->
        <div class="flex items-center justify-center h-16 bg-primary-600 text-white">
          <h1 class="text-xl font-bold">SMS</h1>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 overflow-y-auto py-4">
          <router-link
            v-for="item in menuItems"
            :key="item.name"
            :to="item.path"
            class="flex items-center px-6 py-3 text-gray-700 hover:bg-primary-50 hover:text-primary-600 transition-colors"
            active-class="bg-primary-50 text-primary-600 border-r-4 border-primary-600"
          >
            <span class="text-lg mr-3">{{ item.icon }}</span>
            <span class="font-medium">{{ item.name }}</span>
          </router-link>

          <!-- Academic group: Classes, Sections, Subjects and Academic Years -->
          <div v-if="academicItems.length">
            <button
              type="button"
              class="flex w-full items-center justify-between px-6 py-3 text-gray-700 hover:bg-primary-50 hover:text-primary-600 transition-colors"
              :class="{ 'text-primary-600': isAcademicActive }"
              @click="academicOpen = !academicOpen"
              :aria-expanded="academicOpen"
            >
              <span class="flex items-center">
                <span class="text-lg mr-3">🏫</span>
                <span class="font-medium">Academic</span>
              </span>
              <span class="text-xs transition-transform duration-150" :class="{ 'rotate-90': academicOpen }">▶</span>
            </button>
            <div v-show="academicOpen">
              <router-link
                v-for="child in academicItems"
                :key="child.name"
                :to="child.path"
                class="flex items-center pl-14 pr-6 py-2 text-sm text-gray-600 hover:bg-primary-50 hover:text-primary-600 transition-colors"
                :class="{ 'bg-primary-50 text-primary-600 border-r-4 border-primary-600': isChildActive(child) }"
              >
                <span class="mr-2">{{ child.icon }}</span>
                <span>{{ child.name }}</span>
              </router-link>
            </div>
          </div>

          <!-- Fees group: Collect, Dues, Reports and (admin) Heads, Rates, Generate -->
          <div v-if="feeItems.length">
            <button
              type="button"
              class="flex w-full items-center justify-between px-6 py-3 text-gray-700 hover:bg-primary-50 hover:text-primary-600 transition-colors"
              :class="{ 'text-primary-600': isFeesActive }"
              @click="feesOpen = !feesOpen"
              :aria-expanded="feesOpen"
            >
              <span class="flex items-center">
                <span class="text-lg mr-3">💰</span>
                <span class="font-medium">Fees</span>
              </span>
              <span class="text-xs transition-transform duration-150" :class="{ 'rotate-90': feesOpen }">▶</span>
            </button>
            <div v-show="feesOpen">
              <router-link
                v-for="child in feeItems"
                :key="child.name"
                :to="child.path"
                class="flex items-center pl-14 pr-6 py-2 text-sm text-gray-600 hover:bg-primary-50 hover:text-primary-600 transition-colors"
                :class="{ 'bg-primary-50 text-primary-600 border-r-4 border-primary-600': isChildActive(child) }"
              >
                <span class="mr-2">{{ child.icon }}</span>
                <span>{{ child.name }}</span>
              </router-link>
            </div>
          </div>

          <!-- CMS group: News, Events and Pages -->
          <div v-if="cmsItems.length">
            <button
              type="button"
              class="flex w-full items-center justify-between px-6 py-3 text-gray-700 hover:bg-primary-50 hover:text-primary-600 transition-colors"
              :class="{ 'text-primary-600': isCmsActive }"
              @click="cmsOpen = !cmsOpen"
              :aria-expanded="cmsOpen"
            >
              <span class="flex items-center">
                <span class="text-lg mr-3">🗂️</span>
                <span class="font-medium">CMS</span>
              </span>
              <span class="text-xs transition-transform duration-150" :class="{ 'rotate-90': cmsOpen }">▶</span>
            </button>
            <div v-show="cmsOpen">
              <router-link
                v-for="child in cmsItems"
                :key="child.name"
                :to="child.path"
                class="flex items-center pl-14 pr-6 py-2 text-sm text-gray-600 hover:bg-primary-50 hover:text-primary-600 transition-colors"
                :class="{ 'bg-primary-50 text-primary-600 border-r-4 border-primary-600': isChildActive(child) }"
              >
                <span class="mr-2">{{ child.icon }}</span>
                <span>{{ child.name }}</span>
              </router-link>
            </div>
          </div>
        </nav>

        <!-- User Profile -->
        <div class="border-t border-gray-200 p-4">
          <div class="flex items-center">
            <div class="w-10 h-10 rounded-full bg-primary-600 flex items-center justify-center text-white font-bold">
              {{ userInitials }}
            </div>
            <div class="ml-3 flex-1">
              <p class="text-sm font-medium text-gray-900">{{ authStore.user?.name }}</p>
              <p class="text-xs text-gray-500">{{ authStore.userRole }}</p>
            </div>
            <button
              @click="handleLogout"
              class="text-gray-400 hover:text-gray-600"
              title="Logout"
            >
              🚪
            </button>
          </div>
        </div>
      </div>
    </aside>

    <!-- Main Content -->
    <div class="ml-64">
      <!-- Top Bar -->
      <header class="bg-white shadow-sm h-16 flex items-center justify-between px-6">
        <h2 class="text-xl font-semibold text-gray-800">
          {{ currentPageTitle }}
        </h2>
        <div class="flex items-center space-x-4">
          <button class="text-gray-600 hover:text-gray-900">
            🔔
          </button>
          <router-link to="/profile" class="text-gray-600 hover:text-gray-900">
            👤
          </router-link>
        </div>
      </header>

      <!-- Page Content -->
      <main class="p-6">
        <router-view />
      </main>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { canAccess, isTeacherOnly } from '@/utils/access'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

// Each entry declares what it needs (see utils/access.js); the sidebar only shows what the
// signed-in user can open. A teacher who isn't also an admin gets the short teacher menu.
const teacherOnly = computed(() => isTeacherOnly(authStore))
const visible = (items) => items.filter((item) => canAccess(authStore, item.access) && !(teacherOnly.value && item.hideForTeacher) && (item.when ? item.when() : true))

// A teacher marks attendance only for the sections they lead, so the Attendance link
// appears once /my/assignments shows at least one (admins always see it).
const leadsSection = ref(false)
const loadClassTeacherSections = async () => {
  if (!teacherOnly.value) return

  try {
    const { data } = await api.get('/my/assignments')
    leadsSection.value = data.data.class_teacher_of.length > 0
  } catch (error) {
    leadsSection.value = false
  }
}
loadClassTeacherSections()

const menuItems = computed(() => visible([
  { name: 'Dashboard', path: '/', icon: '📊' },
  { name: 'My subjects', path: '/my-subjects', icon: '🧑‍🏫', access: { role: 'teacher' } },
  { name: 'My routine', path: '/my-routine', icon: '🗓️', access: { role: 'teacher' } },
  { name: 'Homework', path: '/homework', icon: '📚', access: { permission: 'view-homework' } },
  { name: 'Students', path: '/students', icon: '👨‍🎓', access: { permission: 'view-students' } },
  { name: 'Admissions', path: '/admissions', icon: '📝', access: { permission: 'edit-students' } },
  { name: 'Certificates', path: '/certificates', icon: '🎓', access: { permission: 'view-students' } },
  { name: 'ID cards', path: '/id-cards', icon: '🪪', access: { permission: 'view-students' } },
  { name: 'Staff', path: '/staff', icon: '👨‍🏫', access: { permission: 'view-teachers' } },
  { name: 'Attendance', path: '/attendance', icon: '📋', access: { permission: 'view-attendance' }, when: () => !teacherOnly.value || leadsSection.value },
  // A teacher's only exam page is mark entry.
  { name: teacherOnly.value ? 'Mark entry' : 'Exams', path: '/exams', icon: '📝', access: { permission: 'view-exams' } },
]))

const cmsItems = computed(() => visible([
  { name: 'Institute', path: '/institute', icon: '🏛️', access: { permission: 'edit-settings' } },
  { name: 'Shifts', path: '/shifts', icon: '⏰', access: { permission: 'edit-settings' } },
  { name: 'News', path: '/news', icon: '📰', access: { role: 'admin' } },
  { name: 'Events', path: '/events', icon: '📅', access: { role: 'admin' } },
  { name: 'Pages', path: '/pages', icon: '📄', access: { role: 'admin' } },
  { name: 'Media', path: '/media', icon: '🖼️', access: { role: 'admin' } },
  { name: 'Galleries', path: '/galleries', icon: '📸', access: { role: 'admin' } },
  { name: 'Menu', path: '/menu', icon: '🧭', access: { role: 'admin' } },
]))

// Classes and Subjects are read-only for office staff (view permissions only).
const academicItems = computed(() => visible([
  { name: 'Classes', path: '/classes', icon: '🏫', access: { permission: 'view-classes' }, hideForTeacher: true },
  { name: 'Sections', path: '/sections', icon: '🧑‍🤝‍🧑', access: { permission: 'edit-classes' } },
  { name: 'Class routine', path: '/routines', icon: '🗓️', access: { permission: 'view-classes' }, hideForTeacher: true },
  { name: 'Teacher routines', path: '/teacher-routines', icon: '🧑‍🏫', access: { permission: 'view-teachers' }, hideForTeacher: true },
  { name: 'Subjects', path: '/subjects', icon: '📚', access: { permission: 'view-subjects' }, hideForTeacher: true },
  { name: 'Academic Years', path: '/academic-years', icon: '📆', access: { permission: 'edit-settings' } },
  { name: 'Holidays', path: '/holidays', icon: '🎉', access: { permission: 'edit-settings' } },
]))

// Office staff collect fees and read dues and reports; the admin also manages heads, rates
// and the dues generation (those need edit-fees, which only the admin role holds).
const feeItems = computed(() => visible([
  { name: 'Collect', path: '/fees/collect', icon: '💵', access: { permission: 'collect-fees' } },
  { name: 'Dues', path: '/fees/dues', icon: '🧾', access: { permission: 'view-fees' } },
  { name: 'Reports', path: '/fees/reports', icon: '📈', access: { permission: 'view-fees' } },
  { name: 'Heads', path: '/fees/heads', icon: '🏷️', access: { permission: 'edit-fees' } },
  { name: 'Rates', path: '/fees/rates', icon: '💲', access: { permission: 'edit-fees' } },
  { name: 'Generate', path: '/fees/generate', icon: '⚙️', access: { permission: 'edit-fees' } },
]))

const isChildActive = (child) => route.path === child.path || route.path.startsWith(`${child.path}/`)

const isCmsActive = computed(() => cmsItems.value.some(isChildActive))
const isAcademicActive = computed(() => academicItems.value.some(isChildActive))
const isFeesActive = computed(() => feeItems.value.some(isChildActive))

const cmsOpen = ref(isCmsActive.value)
const academicOpen = ref(isAcademicActive.value)
const feesOpen = ref(isFeesActive.value)

// Auto-expand each group whenever the current route is one of its children, without
// collapsing it back when the user navigates away (they can toggle it).
watch(isCmsActive, (active) => {
  if (active) cmsOpen.value = true
})
watch(isAcademicActive, (active) => {
  if (active) academicOpen.value = true
})
watch(isFeesActive, (active) => {
  if (active) feesOpen.value = true
})

// Titles come from the route's meta (an unnamed fallback keeps new routes readable).
const currentPageTitle = computed(() => {
  return route.meta.title || route.name || 'Dashboard'
})

const userInitials = computed(() => {
  const name = authStore.user?.name || ''
  return name
    .split(' ')
    .map(n => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2)
})

const handleLogout = async () => {
  if (confirm('Are you sure you want to logout?')) {
    await authStore.logout()
  }
}
</script>

