import { ref } from 'vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { isTeacherOnly } from '@/utils/access'
import { GROUP_LABELS } from '@/constants/academic'

// The sections the attendance pickers offer: every section for an admin, and for a teacher
// only the sections they lead as class teacher (/my/assignments). The API enforces the same.
export function useAttendanceSections() {
  const authStore = useAuthStore()
  const teacherOnly = isTeacherOnly(authStore)
  const sections = ref([])
  const loading = ref(true)
  const error = ref('')

  const load = async () => {
    loading.value = true
    error.value = ''
    try {
      if (teacherOnly) {
        const { data } = await api.get('/my/assignments')
        sections.value = data.data.class_teacher_of
      } else {
        const { data } = await api.get('/sections', { params: { per_page: 100 } })
        sections.value = data.data
      }
    } catch (e) {
      error.value = e.response?.data?.message || 'Failed to load the sections'
    } finally {
      loading.value = false
    }
  }

  const sectionLabel = (section) => {
    const parts = [`${section.class?.name ?? ''} – ${section.name}`.trim()]
    if (section.group) parts.push(GROUP_LABELS[section.group] || section.group)
    if (section.shift) parts.push(section.shift.name_en)
    return parts.join(' · ')
  }

  return { sections, loading, error, teacherOnly, load, sectionLabel }
}
