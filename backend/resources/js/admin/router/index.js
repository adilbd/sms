import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { canAccess } from '@/utils/access'

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
        meta: { title: 'Dashboard' },
      },
      {
        path: 'my-subjects',
        name: 'My Subjects',
        component: () => import('@/views/teacher/MySubjects.vue'),
        meta: { title: 'My Subjects', role: 'teacher' },
      },
      {
        path: 'students',
        name: 'Students',
        component: () => import('@/views/students/StudentList.vue'),
        meta: { title: 'Students', permission: 'view-students' },
      },
      {
        path: 'students/promotion',
        name: 'StudentPromotion',
        component: () => import('@/views/students/Promotion.vue'),
        meta: { title: 'Student Promotion', permission: 'edit-students' },
      },
      {
        path: 'students/create',
        name: 'CreateStudent',
        component: () => import('@/views/students/StudentForm.vue'),
        meta: { title: 'Add Student', permission: 'create-students' },
      },
      {
        path: 'students/:id/edit',
        name: 'EditStudent',
        component: () => import('@/views/students/StudentForm.vue'),
        meta: { title: 'Edit Student', permission: 'edit-students' },
      },
      {
        path: 'students/:id',
        name: 'StudentDetails',
        component: () => import('@/views/students/StudentDetails.vue'),
        meta: { title: 'Student Details', permission: 'view-students' },
      },
      {
        path: 'staff',
        name: 'Staff',
        component: () => import('@/views/staff/StaffList.vue'),
        meta: { title: 'Staff', permission: 'view-teachers' },
      },
      {
        path: 'staff/create',
        name: 'Add Staff',
        component: () => import('@/views/staff/StaffForm.vue'),
        meta: { title: 'Add Staff', permission: 'create-teachers' },
      },
      {
        path: 'staff/:id/edit',
        name: 'Edit Staff',
        component: () => import('@/views/staff/StaffForm.vue'),
        meta: { title: 'Edit Staff', permission: 'edit-teachers' },
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
        meta: { title: 'Shifts', permission: 'edit-settings' },
      },
      {
        path: 'shifts/create',
        name: 'Add Shift',
        component: () => import('@/views/shifts/ShiftForm.vue'),
        meta: { title: 'Add Shift', permission: 'edit-settings' },
      },
      {
        path: 'shifts/:id/edit',
        name: 'Edit Shift',
        component: () => import('@/views/shifts/ShiftForm.vue'),
        meta: { title: 'Edit Shift', permission: 'edit-settings' },
      },
      {
        path: 'classes',
        name: 'Classes',
        component: () => import('@/views/classes/ClassList.vue'),
        meta: { title: 'Classes', permission: 'view-classes' },
      },
      {
        path: 'classes/create',
        name: 'Add Class',
        component: () => import('@/views/classes/ClassForm.vue'),
        meta: { title: 'Add Class', permission: 'create-classes' },
      },
      {
        path: 'classes/:id/subjects',
        name: 'Class Curriculum',
        component: () => import('@/views/classes/CurriculumEditor.vue'),
        meta: { title: 'Class Curriculum', permission: 'edit-classes' },
      },
      {
        path: 'classes/:id/edit',
        name: 'Edit Class',
        component: () => import('@/views/classes/ClassForm.vue'),
        meta: { title: 'Edit Class', permission: 'edit-classes' },
      },
      {
        path: 'sections',
        name: 'Sections',
        component: () => import('@/views/sections/SectionList.vue'),
        meta: { title: 'Sections', permission: 'view-classes' },
      },
      {
        path: 'sections/create',
        name: 'Add Section',
        component: () => import('@/views/sections/SectionForm.vue'),
        meta: { title: 'Add Section', permission: 'create-classes' },
      },
      {
        path: 'sections/:id/subject-teachers',
        name: 'Section Subject Teachers',
        component: () => import('@/views/sections/SubjectTeachers.vue'),
        meta: { title: 'Subject Teachers', permission: 'edit-classes' },
      },
      {
        path: 'sections/:id/edit',
        name: 'Edit Section',
        component: () => import('@/views/sections/SectionForm.vue'),
        meta: { title: 'Edit Section', permission: 'edit-classes' },
      },
      {
        path: 'academic-years',
        name: 'Academic Years',
        component: () => import('@/views/academic-years/AcademicYearList.vue'),
        meta: { title: 'Academic Years', permission: 'edit-settings' },
      },
      {
        path: 'academic-years/create',
        name: 'Add Academic Year',
        component: () => import('@/views/academic-years/AcademicYearForm.vue'),
        meta: { title: 'Add Academic Year', permission: 'edit-settings' },
      },
      {
        path: 'academic-years/:id/edit',
        name: 'Edit Academic Year',
        component: () => import('@/views/academic-years/AcademicYearForm.vue'),
        meta: { title: 'Edit Academic Year', permission: 'edit-settings' },
      },
      {
        path: 'subjects',
        name: 'Subjects',
        component: () => import('@/views/subjects/SubjectList.vue'),
        meta: { title: 'Subjects', permission: 'view-subjects' },
      },
      {
        path: 'subjects/create',
        name: 'Add Subject',
        component: () => import('@/views/subjects/SubjectForm.vue'),
        meta: { title: 'Add Subject', permission: 'create-subjects' },
      },
      {
        path: 'subjects/:id/edit',
        name: 'Edit Subject',
        component: () => import('@/views/subjects/SubjectForm.vue'),
        meta: { title: 'Edit Subject', permission: 'edit-subjects' },
      },
      {
        path: 'attendance',
        name: 'Attendance',
        component: () => import('@/views/attendance/AttendanceList.vue'),
        meta: { title: 'Attendance', permission: 'view-attendance' },
      },
      {
        path: 'attendance/mark',
        name: 'MarkAttendance',
        component: () => import('@/views/attendance/MarkAttendance.vue'),
        meta: { title: 'Mark Attendance', permission: 'mark-attendance' },
      },
      {
        path: 'holidays',
        name: 'Holidays',
        component: () => import('@/views/attendance/Holidays.vue'),
        meta: { title: 'Holidays', permission: 'edit-settings' },
      },
      {
        path: 'exams',
        name: 'Exams',
        component: () => import('@/views/exams/ExamList.vue'),
        meta: { title: 'Exams', permission: 'view-exams' },
      },
      {
        path: 'exams/create',
        name: 'Add Exam',
        component: () => import('@/views/exams/ExamForm.vue'),
        meta: { title: 'Add Exam', permission: 'create-exams' },
      },
      {
        path: 'exams/:id/edit',
        name: 'Edit Exam',
        component: () => import('@/views/exams/ExamForm.vue'),
        meta: { title: 'Edit Exam', permission: 'edit-exams' },
      },
      {
        path: 'exams/:id/schedule',
        name: 'Exam Schedule',
        component: () => import('@/views/exams/ExamSchedule.vue'),
        meta: { title: 'Exam Schedule', permission: 'edit-exams' },
      },
      {
        path: 'exams/:id/marks',
        name: 'Mark Entry',
        component: () => import('@/views/exams/MarkEntry.vue'),
        meta: { title: 'Mark Entry', permission: 'enter-results' },
      },
      {
        path: 'exams/:id/results',
        name: 'Exam Results',
        component: () => import('@/views/exams/ExamResults.vue'),
        meta: { title: 'Exam Results', permission: 'publish-exams' },
      },
      {
        path: 'exams/:id/report-cards',
        name: 'Report Cards',
        component: () => import('@/views/exams/ReportCards.vue'),
        meta: { title: 'Report Cards', permission: 'publish-exams' },
      },
      {
        path: 'exams/:id/report-cards/:studentId',
        name: 'Report Card',
        component: () => import('@/views/exams/ReportCards.vue'),
        meta: { title: 'Report Card', permission: 'publish-exams' },
      },
      {
        path: 'fees',
        name: 'Fees',
        component: () => import('@/views/fees/FeeList.vue'),
        meta: { title: 'Fees', permission: 'view-fees' },
      },
      {
        path: 'institute',
        name: 'Institute',
        component: () => import('@/views/settings/InstituteSettings.vue'),
        meta: { title: 'Institute', permission: 'edit-settings' },
      },
      {
        path: 'news',
        name: 'News',
        component: () => import('@/views/posts/PostList.vue'),
        meta: { type: 'news', title: 'News', role: 'admin' },
      },
      {
        path: 'news/create',
        name: 'Add News',
        component: () => import('@/views/posts/PostForm.vue'),
        meta: { type: 'news', title: 'Add News', role: 'admin' },
      },
      {
        path: 'news/:id/edit',
        name: 'Edit News',
        component: () => import('@/views/posts/PostForm.vue'),
        meta: { type: 'news', title: 'Edit News', role: 'admin' },
      },
      {
        path: 'events',
        name: 'Events',
        component: () => import('@/views/posts/PostList.vue'),
        meta: { type: 'event', title: 'Events', role: 'admin' },
      },
      {
        path: 'events/create',
        name: 'Add Event',
        component: () => import('@/views/posts/PostForm.vue'),
        meta: { type: 'event', title: 'Add Event', role: 'admin' },
      },
      {
        path: 'events/:id/edit',
        name: 'Edit Event',
        component: () => import('@/views/posts/PostForm.vue'),
        meta: { type: 'event', title: 'Edit Event', role: 'admin' },
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
        meta: { title: 'Pages', role: 'admin' },
      },
      {
        path: 'pages/create',
        name: 'Add Page',
        component: () => import('@/views/pages/PageForm.vue'),
        meta: { title: 'Add Page', role: 'admin' },
      },
      {
        path: 'pages/:id/edit',
        name: 'Edit Page',
        component: () => import('@/views/pages/PageForm.vue'),
        meta: { title: 'Edit Page', role: 'admin' },
      },
      {
        path: 'media',
        name: 'Media',
        component: () => import('@/views/media/MediaLibrary.vue'),
        meta: { title: 'Media', role: 'admin' },
      },
      {
        path: 'galleries',
        name: 'Galleries',
        component: () => import('@/views/galleries/GalleryList.vue'),
        meta: { title: 'Galleries', role: 'admin' },
      },
      {
        path: 'galleries/create',
        name: 'Add Gallery',
        component: () => import('@/views/galleries/GalleryForm.vue'),
        meta: { title: 'Add Gallery', role: 'admin' },
      },
      {
        path: 'galleries/:id/edit',
        name: 'Edit Gallery',
        component: () => import('@/views/galleries/GalleryForm.vue'),
        meta: { title: 'Edit Gallery', role: 'admin' },
      },
      {
        path: 'menu',
        name: 'Menu',
        component: () => import('@/views/menu/MenuList.vue'),
        meta: { title: 'Menu', role: 'admin' },
      },
      {
        path: 'menu/create',
        name: 'Add Menu Item',
        component: () => import('@/views/menu/MenuForm.vue'),
        meta: { title: 'Add Menu Item', role: 'admin' },
      },
      {
        path: 'menu/:id/edit',
        name: 'Edit Menu Item',
        component: () => import('@/views/menu/MenuForm.vue'),
        meta: { title: 'Edit Menu Item', role: 'admin' },
      },
      {
        path: 'profile',
        name: 'Profile',
        component: () => import('@/views/Profile.vue'),
        meta: { title: 'Profile' },
      },
      {
        // An unknown /admin/... path (a stale bookmark, a typo) goes to the dashboard instead
        // of rendering an empty layout. Last, so every real route matches first.
        path: ':pathMatch(.*)*',
        name: 'NotFound',
        redirect: '/',
      },
    ],
  },
]

const router = createRouter({
  history: createWebHistory('/admin/'),
  routes,
})

router.beforeEach(async (to, from, next) => {
  const authStore = useAuthStore()
  const requiresAuth = to.matched.some(record => record.meta.requiresAuth)
  const requiresGuest = to.matched.some(record => record.meta.requiresGuest)

  if (requiresAuth && !authStore.isAuthenticated) {
    next('/login')
  } else if (requiresGuest && authStore.isAuthenticated) {
    next('/')
  } else if (requiresAuth && !authStore.user) {
    // A token without a loaded user (e.g. localStorage was cleared): load it before
    // checking what the user may open.
    await authStore.checkAuth()
    next(authStore.isAuthenticated ? to.fullPath : '/login')
  } else if (to.matched.some(record => !canAccess(authStore, record.meta))) {
    // Each route declares the permission or role it needs in `meta`; anyone without it
    // goes to the dashboard (the API refuses them too).
    next('/')
  } else {
    next()
  }
})

export default router

