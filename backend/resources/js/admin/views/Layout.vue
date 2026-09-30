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
          <div>
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

          <!-- CMS group: News, Events and Pages -->
          <div>
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
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const menuItems = computed(() => {
  const items = [
    { name: 'Dashboard', path: '/', icon: '📊' },
    { name: 'Students', path: '/students', icon: '👨‍🎓' },
    { name: 'Staff', path: '/staff', icon: '👨‍🏫' },
    { name: 'Attendance', path: '/attendance', icon: '📋' },
    { name: 'Exams', path: '/exams', icon: '📝' },
    { name: 'Fees', path: '/fees', icon: '💰' },
  ]

  // Filter menu based on user role
  return items
})

const cmsItems = [
  { name: 'Institute', path: '/institute', icon: '🏛️' },
  { name: 'Shifts', path: '/shifts', icon: '⏰' },
  { name: 'News', path: '/news', icon: '📰' },
  { name: 'Events', path: '/events', icon: '📅' },
  { name: 'Pages', path: '/pages', icon: '📄' },
  { name: 'Media', path: '/media', icon: '🖼️' },
  { name: 'Galleries', path: '/galleries', icon: '📸' },
  { name: 'Menu', path: '/menu', icon: '🧭' },
]

const academicItems = [
  { name: 'Classes', path: '/classes', icon: '🏫' },
  { name: 'Sections', path: '/sections', icon: '🧑‍🤝‍🧑' },
  { name: 'Subjects', path: '/subjects', icon: '📚' },
  { name: 'Academic Years', path: '/academic-years', icon: '📆' },
]

const isChildActive = (child) => route.path === child.path || route.path.startsWith(`${child.path}/`)

const isCmsActive = computed(() => cmsItems.some(isChildActive))
const isAcademicActive = computed(() => academicItems.some(isChildActive))

const cmsOpen = ref(isCmsActive.value)
const academicOpen = ref(isAcademicActive.value)

// Auto-expand each group whenever the current route is one of its children, without
// collapsing it back when the user navigates away (they can toggle it).
watch(isCmsActive, (active) => {
  if (active) cmsOpen.value = true
})
watch(isAcademicActive, (active) => {
  if (active) academicOpen.value = true
})

const currentPageTitle = computed(() => {
  return route.name || 'Dashboard'
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

