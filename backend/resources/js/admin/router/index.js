import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/Login.vue'),
    meta: { requiresGuest: true },
  },
  {
    path: '/',
    component: () => import('@/views/Layout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Dashboard',
        component: () => import('@/views/Dashboard.vue'),
      },
      {
        path: 'students',
        name: 'Students',
        component: () => import('@/views/students/StudentList.vue'),
      },
      {
        path: 'students/create',
        name: 'CreateStudent',
        component: () => import('@/views/students/StudentForm.vue'),
      },
      {
        path: 'students/:id',
        name: 'StudentDetails',
        component: () => import('@/views/students/StudentDetails.vue'),
      },
      {
        path: 'teachers',
        name: 'Teachers',
        component: () => import('@/views/teachers/TeacherList.vue'),
      },
      {
        path: 'classes',
        name: 'Classes',
        component: () => import('@/views/classes/ClassList.vue'),
      },
      {
        path: 'subjects',
        name: 'Subjects',
        component: () => import('@/views/subjects/SubjectList.vue'),
      },
      {
        path: 'attendance',
        name: 'Attendance',
        component: () => import('@/views/attendance/AttendanceList.vue'),
      },
      {
        path: 'attendance/mark',
        name: 'MarkAttendance',
        component: () => import('@/views/attendance/MarkAttendance.vue'),
      },
      {
        path: 'exams',
        name: 'Exams',
        component: () => import('@/views/exams/ExamList.vue'),
      },
      {
        path: 'fees',
        name: 'Fees',
        component: () => import('@/views/fees/FeeList.vue'),
      },
      {
        path: 'news',
        name: 'News',
        component: () => import('@/views/posts/PostList.vue'),
        meta: { type: 'news' },
      },
      {
        path: 'news/create',
        name: 'Add News',
        component: () => import('@/views/posts/PostForm.vue'),
        meta: { type: 'news' },
      },
      {
        path: 'news/:id/edit',
        name: 'Edit News',
        component: () => import('@/views/posts/PostForm.vue'),
        meta: { type: 'news' },
      },
      {
        path: 'events',
        name: 'Events',
        component: () => import('@/views/posts/PostList.vue'),
        meta: { type: 'event' },
      },
      {
        path: 'events/create',
        name: 'Add Event',
        component: () => import('@/views/posts/PostForm.vue'),
        meta: { type: 'event' },
      },
      {
        path: 'events/:id/edit',
        name: 'Edit Event',
        component: () => import('@/views/posts/PostForm.vue'),
        meta: { type: 'event' },
      },
      // Old links to the single News & Events section now go to News.
      {
        path: 'posts',
        redirect: '/news',
      },
      {
        path: 'posts/create',
        redirect: '/news/create',
      },
      {
        path: 'posts/:id/edit',
        redirect: (to) => `/news/${to.params.id}/edit`,
      },
      {
        path: 'pages',
        name: 'Pages',
        component: () => import('@/views/pages/PageList.vue'),
      },
      {
        path: 'pages/create',
        name: 'Add Page',
        component: () => import('@/views/pages/PageForm.vue'),
      },
      {
        path: 'pages/:id/edit',
        name: 'Edit Page',
        component: () => import('@/views/pages/PageForm.vue'),
      },
      {
        path: 'profile',
        name: 'Profile',
        component: () => import('@/views/Profile.vue'),
      },
    ],
  },
]

const router = createRouter({
  history: createWebHistory('/admin/'),
  routes,
})

router.beforeEach((to, from, next) => {
  const authStore = useAuthStore()
  const requiresAuth = to.matched.some(record => record.meta.requiresAuth)
  const requiresGuest = to.matched.some(record => record.meta.requiresGuest)

  if (requiresAuth && !authStore.isAuthenticated) {
    next('/login')
  } else if (requiresGuest && authStore.isAuthenticated) {
    next('/')
  } else {
    next()
  }
})

export default router

