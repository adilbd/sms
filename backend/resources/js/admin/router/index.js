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
        path: 'staff',
        name: 'Staff',
        component: () => import('@/views/staff/StaffList.vue'),
      },
      {
        path: 'staff/create',
        name: 'Add Staff',
        component: () => import('@/views/staff/StaffForm.vue'),
      },
      {
        path: 'staff/:id/edit',
        name: 'Edit Staff',
        component: () => import('@/views/staff/StaffForm.vue'),
      },
      // Old link to the teachers-only page now goes to Staff.
      {
        path: 'teachers',
        redirect: '/staff',
      },
      {
        path: 'shifts',
        name: 'Shifts',
        component: () => import('@/views/shifts/ShiftList.vue'),
      },
      {
        path: 'shifts/create',
        name: 'Add Shift',
        component: () => import('@/views/shifts/ShiftForm.vue'),
      },
      {
        path: 'shifts/:id/edit',
        name: 'Edit Shift',
        component: () => import('@/views/shifts/ShiftForm.vue'),
      },
      {
        path: 'classes',
        name: 'Classes',
        component: () => import('@/views/classes/ClassList.vue'),
      },
      {
        path: 'classes/create',
        name: 'Add Class',
        component: () => import('@/views/classes/ClassForm.vue'),
      },
      {
        path: 'classes/:id/subjects',
        name: 'Class Curriculum',
        component: () => import('@/views/classes/CurriculumEditor.vue'),
      },
      {
        path: 'classes/:id/edit',
        name: 'Edit Class',
        component: () => import('@/views/classes/ClassForm.vue'),
      },
      {
        path: 'sections',
        name: 'Sections',
        component: () => import('@/views/sections/SectionList.vue'),
      },
      {
        path: 'sections/create',
        name: 'Add Section',
        component: () => import('@/views/sections/SectionForm.vue'),
      },
      {
        path: 'sections/:id/edit',
        name: 'Edit Section',
        component: () => import('@/views/sections/SectionForm.vue'),
      },
      {
        path: 'academic-years',
        name: 'Academic Years',
        component: () => import('@/views/academic-years/AcademicYearList.vue'),
      },
      {
        path: 'academic-years/create',
        name: 'Add Academic Year',
        component: () => import('@/views/academic-years/AcademicYearForm.vue'),
      },
      {
        path: 'academic-years/:id/edit',
        name: 'Edit Academic Year',
        component: () => import('@/views/academic-years/AcademicYearForm.vue'),
      },
      {
        path: 'subjects',
        name: 'Subjects',
        component: () => import('@/views/subjects/SubjectList.vue'),
      },
      {
        path: 'subjects/create',
        name: 'Add Subject',
        component: () => import('@/views/subjects/SubjectForm.vue'),
      },
      {
        path: 'subjects/:id/edit',
        name: 'Edit Subject',
        component: () => import('@/views/subjects/SubjectForm.vue'),
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
        path: 'institute',
        name: 'Institute',
        component: () => import('@/views/settings/InstituteSettings.vue'),
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
        path: 'media',
        name: 'Media',
        component: () => import('@/views/media/MediaLibrary.vue'),
      },
      {
        path: 'galleries',
        name: 'Galleries',
        component: () => import('@/views/galleries/GalleryList.vue'),
      },
      {
        path: 'galleries/create',
        name: 'Add Gallery',
        component: () => import('@/views/galleries/GalleryForm.vue'),
      },
      {
        path: 'galleries/:id/edit',
        name: 'Edit Gallery',
        component: () => import('@/views/galleries/GalleryForm.vue'),
      },
      {
        path: 'menu',
        name: 'Menu',
        component: () => import('@/views/menu/MenuList.vue'),
      },
      {
        path: 'menu/create',
        name: 'Add Menu Item',
        component: () => import('@/views/menu/MenuForm.vue'),
      },
      {
        path: 'menu/:id/edit',
        name: 'Edit Menu Item',
        component: () => import('@/views/menu/MenuForm.vue'),
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

