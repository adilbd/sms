<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">{{ isEdit ? 'Edit Student' : 'Add Student' }}</h1>

    <form class="space-y-6" @submit.prevent="save">
      <div v-if="formError" class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ formError }}</div>

      <!-- Profile -->
      <section class="card space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Profile</h2>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Name (English)</label>
            <input v-model="form.name_en" type="text" class="input" />
            <p v-if="err('name_en')" class="text-sm text-red-600 mt-1">{{ err('name_en') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Name (Bangla)</label>
            <input v-model="form.name_bn" type="text" class="input" />
            <p v-if="err('name_bn')" class="text-sm text-red-600 mt-1">{{ err('name_bn') }}</p>
          </div>
          <p class="md:col-span-2 text-xs text-gray-500">At least one of the two names is required.</p>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Date of birth</label>
            <input v-model="form.date_of_birth" type="date" class="input" />
            <p v-if="err('date_of_birth')" class="text-sm text-red-600 mt-1">{{ err('date_of_birth') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Gender</label>
            <select v-model="form.gender" class="input">
              <option value="">Select</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
              <option value="other">Other</option>
            </select>
            <p v-if="err('gender')" class="text-sm text-red-600 mt-1">{{ err('gender') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Religion</label>
            <select v-model="form.religion" class="input">
              <option value="">Select</option>
              <option v-for="r in religions" :key="r" :value="r">{{ r }}</option>
            </select>
            <p v-if="err('religion')" class="text-sm text-red-600 mt-1">{{ err('religion') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Blood group</label>
            <select v-model="form.blood_group" class="input">
              <option value="">Select</option>
              <option v-for="b in bloodGroups" :key="b" :value="b">{{ b }}</option>
            </select>
            <p v-if="err('blood_group')" class="text-sm text-red-600 mt-1">{{ err('blood_group') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Birth registration number (17 digits)</label>
            <input v-model="form.birth_registration_number" type="text" inputmode="numeric" maxlength="17" class="input" />
            <p v-if="err('birth_registration_number')" class="text-sm text-red-600 mt-1">{{ err('birth_registration_number') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nationality</label>
            <input v-model="form.nationality" type="text" class="input" />
            <p v-if="err('nationality')" class="text-sm text-red-600 mt-1">{{ err('nationality') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mobile</label>
            <input v-model="form.mobile" type="text" placeholder="01XXXXXXXXX" class="input" />
            <p v-if="err('mobile')" class="text-sm text-red-600 mt-1">{{ err('mobile') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email (optional)</label>
            <input v-model="form.email" type="email" class="input" />
            <p v-if="err('email')" class="text-sm text-red-600 mt-1">{{ err('email') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Admission date</label>
            <input v-model="form.admission_date" type="date" class="input" :disabled="isEdit" />
            <p v-if="err('admission_date')" class="text-sm text-red-600 mt-1">{{ err('admission_date') }}</p>
          </div>
          <div v-if="isEdit">
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select v-model="form.status" class="input">
              <option value="active">Active</option>
              <option value="left">Left</option>
              <option value="graduated">Graduated</option>
            </select>
            <p v-if="err('status')" class="text-sm text-red-600 mt-1">{{ err('status') }}</p>
          </div>
          <div v-if="form.status !== 'active'">
            <label class="block text-sm font-medium text-gray-700 mb-1">Leaving date</label>
            <input v-model="form.leaving_date" type="date" class="input" />
            <p v-if="err('leaving_date')" class="text-sm text-red-600 mt-1">{{ err('leaving_date') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">District</label>
            <input v-model="form.district" type="text" class="input" />
            <p v-if="err('district')" class="text-sm text-red-600 mt-1">{{ err('district') }}</p>
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Present address</label>
            <textarea v-model="form.present_address" rows="2" class="input"></textarea>
            <p v-if="err('present_address')" class="text-sm text-red-600 mt-1">{{ err('present_address') }}</p>
          </div>
          <div class="md:col-span-2">
            <label class="flex items-center gap-2 text-sm text-gray-700 mb-1">
              <input v-model="sameAsPresent" type="checkbox" />
              Permanent address is the same as present
            </label>
            <label class="block text-sm font-medium text-gray-700 mb-1">Permanent address</label>
            <textarea v-model="form.permanent_address" rows="2" class="input" :disabled="sameAsPresent"></textarea>
            <p v-if="err('permanent_address')" class="text-sm text-red-600 mt-1">{{ err('permanent_address') }}</p>
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Photo</label>
            <div class="flex items-center gap-4">
              <img v-if="photoPreview" :src="photoPreview" alt="" class="h-20 w-20 rounded-lg object-cover" />
              <input type="file" accept="image/jpeg,image/png,image/webp" @change="onPhotoChange" />
              <button v-if="photoPreview" type="button" class="text-sm text-red-600 hover:text-red-800" @click="removePhoto">Remove photo</button>
            </div>
            <p v-if="err('photo')" class="text-sm text-red-600 mt-1">{{ err('photo') }}</p>
          </div>
        </div>
      </section>

      <!-- Father / Mother -->
      <section class="card space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Father and mother</h2>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div v-for="parent in ['father', 'mother']" :key="parent" class="space-y-3">
            <h3 class="font-medium capitalize text-gray-800">{{ parent }}</h3>
            <div>
              <label class="block text-sm text-gray-700 mb-1">Name (English)</label>
              <input v-model="form[`${parent}_name_en`]" type="text" class="input" />
              <p v-if="err(`${parent}_name_en`)" class="text-sm text-red-600 mt-1">{{ err(`${parent}_name_en`) }}</p>
            </div>
            <div>
              <label class="block text-sm text-gray-700 mb-1">Name (Bangla)</label>
              <input v-model="form[`${parent}_name_bn`]" type="text" class="input" />
              <p v-if="err(`${parent}_name_bn`)" class="text-sm text-red-600 mt-1">{{ err(`${parent}_name_bn`) }}</p>
            </div>
            <div>
              <label class="block text-sm text-gray-700 mb-1">Mobile</label>
              <input v-model="form[`${parent}_mobile`]" type="text" placeholder="01XXXXXXXXX" class="input" />
              <p v-if="err(`${parent}_mobile`)" class="text-sm text-red-600 mt-1">{{ err(`${parent}_mobile`) }}</p>
            </div>
            <div>
              <label class="block text-sm text-gray-700 mb-1">Occupation</label>
              <input v-model="form[`${parent}_occupation`]" type="text" class="input" />
              <p v-if="err(`${parent}_occupation`)" class="text-sm text-red-600 mt-1">{{ err(`${parent}_occupation`) }}</p>
            </div>
          </div>
        </div>
      </section>

      <!-- Guardian -->
      <section class="card space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="text-lg font-semibold text-gray-900">Guardian</h2>
          <div class="flex gap-2">
            <button type="button" class="btn btn-secondary text-sm" @click="copyGuardian('father')">Same as father</button>
            <button type="button" class="btn btn-secondary text-sm" @click="copyGuardian('mother')">Same as mother</button>
          </div>
        </div>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Relation</label>
            <select v-model="form.guardian_relation" class="input">
              <option value="father">Father</option>
              <option value="mother">Mother</option>
              <option value="other">Other</option>
            </select>
            <p v-if="err('guardian_relation')" class="text-sm text-red-600 mt-1">{{ err('guardian_relation') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
            <input v-model="form.guardian_name" type="text" class="input" />
            <p v-if="err('guardian_name')" class="text-sm text-red-600 mt-1">{{ err('guardian_name') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mobile (guardian's login)</label>
            <input v-model="form.guardian_mobile" type="text" placeholder="01XXXXXXXXX" class="input" />
            <p v-if="err('guardian_mobile')" class="text-sm text-red-600 mt-1">{{ err('guardian_mobile') }}</p>
          </div>
        </div>
        <p class="text-xs text-gray-500">
          Siblings with the same guardian mobile share one guardian login.
        </p>
      </section>

      <!-- Enrolment -->
      <section class="card space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Enrolment ({{ activeYearName || 'no active academic year' }})</h2>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
            <select v-model="form.enrolment.section_id" class="input" @change="onSectionChange">
              <option value="">Select section</option>
              <option v-for="section in sections" :key="section.id" :value="section.id">
                {{ sectionLabel(section) }}
              </option>
            </select>
            <p v-if="err('enrolment.section_id')" class="text-sm text-red-600 mt-1">{{ err('enrolment.section_id') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Roll number</label>
            <input v-model="form.enrolment.roll_number" type="number" min="1" class="input" />
            <p v-if="err('enrolment.roll_number')" class="text-sm text-red-600 mt-1">{{ err('enrolment.roll_number') }}</p>
          </div>
          <template v-if="hasGroups">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Group</label>
              <select v-model="form.enrolment.group" class="input" :disabled="!!selectedSection?.group" @change="onGroupChange">
                <option value="">Select group</option>
                <option v-for="(label, value) in GROUP_LABELS" :key="value" :value="value">{{ label }}</option>
              </select>
              <p v-if="err('enrolment.group')" class="text-sm text-red-600 mt-1">{{ err('enrolment.group') }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">4th subject</label>
              <select v-model="form.enrolment.optional_subject_id" class="input" :disabled="!form.enrolment.group">
                <option value="">None</option>
                <option v-for="row in optionalSubjects" :key="row.subject_id" :value="row.subject_id">
                  {{ row.subject?.name }}
                </option>
              </select>
              <p v-if="err('enrolment.optional_subject_id')" class="text-sm text-red-600 mt-1">{{ err('enrolment.optional_subject_id') }}</p>
            </div>
          </template>
        </div>
      </section>

      <!-- Login -->
      <section class="card space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Login</h2>
        <p class="text-sm text-gray-600">
          The student signs in with their student ID
          <strong>{{ isEdit ? form.student_id : '(assigned when saved)' }}</strong>.
          The guardian signs in with their mobile number.
        </p>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Student password{{ isEdit ? ' (leave blank to keep)' : '' }}
            </label>
            <input v-model="form.password" type="password" autocomplete="new-password" class="input" />
            <p v-if="err('password')" class="text-sm text-red-600 mt-1">{{ err('password') }}</p>
          </div>
          <div v-if="showGuardianPassword">
            <label class="block text-sm font-medium text-gray-700 mb-1">Guardian password</label>
            <input v-model="form.guardian_password" type="password" autocomplete="new-password" class="input" />
            <p v-if="err('guardian_password')" class="text-sm text-red-600 mt-1">{{ err('guardian_password') }}</p>
            <p v-else class="text-xs text-gray-500 mt-1">
              {{ isEdit ? 'Sets a new password for the guardian login.' : 'This mobile number has no guardian login yet.' }}
            </p>
          </div>
          <div v-else-if="isEdit" class="flex items-end">
            <button type="button" class="text-sm text-primary-600 hover:text-primary-800" @click="showGuardianPassword = true">
              Reset the guardian's password
            </button>
          </div>
        </div>
      </section>

      <div class="flex justify-end space-x-2">
        <router-link to="/students" class="btn btn-secondary">Cancel</router-link>
        <button type="submit" class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving...' : 'Save' }}</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'

const GROUP_LABELS = {
  science: 'Science',
  business_studies: 'Business Studies',
  humanities: 'Humanities',
}
const religions = ['islam', 'hinduism', 'buddhism', 'christianity', 'other']
const bloodGroups = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-']

const route = useRoute()
const router = useRouter()

const isEdit = computed(() => !!route.params.id)
const saving = ref(false)
const errors = ref({})
const formError = ref('')
const sections = ref([])
const optionalSubjects = ref([])
const activeYearName = ref('')
const showGuardianPassword = ref(false)
const sameAsPresent = ref(false)

const today = () => {
  const d = new Date()
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

const form = reactive({
  student_id: '',
  name_en: '',
  name_bn: '',
  date_of_birth: '',
  gender: '',
  religion: '',
  blood_group: '',
  birth_registration_number: '',
  nationality: 'Bangladeshi',
  mobile: '',
  email: '',
  present_address: '',
  permanent_address: '',
  district: '',
  admission_date: today(),
  status: 'active',
  leaving_date: '',
  father_name_en: '',
  father_name_bn: '',
  father_mobile: '',
  father_occupation: '',
  mother_name_en: '',
  mother_name_bn: '',
  mother_mobile: '',
  mother_occupation: '',
  guardian_relation: 'father',
  guardian_name: '',
  guardian_mobile: '',
  password: '',
  guardian_password: '',
  enrolment: { section_id: '', group: '', optional_subject_id: '', roll_number: '' },
})

// Laravel reports nested errors as "enrolment.group"; show the first message per key.
const err = (key) => errors.value[key]?.[0]

const selectedSection = computed(() => sections.value.find((s) => s.id === form.enrolment.section_id) || null)
const hasGroups = computed(() => !!selectedSection.value?.class?.has_groups)

const sectionLabel = (section) => {
  const inactive = section.is_active ? '' : ' [inactive]'
  const group = section.group ? ` - ${GROUP_LABELS[section.group]}` : ''
  return `${section.class?.name} - ${section.name} (${section.shift?.name_en || 'no shift'})${group}${inactive}`
}

watch(sameAsPresent, (value) => {
  if (value) form.permanent_address = form.present_address
})
watch(() => form.present_address, (value) => {
  if (sameAsPresent.value) form.permanent_address = value
})

const copyGuardian = (who) => {
  form.guardian_relation = who
  form.guardian_name = form[`${who}_name_en`] || form[`${who}_name_bn`] || form.guardian_name
  form.guardian_mobile = form[`${who}_mobile`] || form.guardian_mobile
}

const loadOptionalSubjects = async () => {
  optionalSubjects.value = []
  const section = selectedSection.value
  if (!section || !hasGroups.value || !form.enrolment.group) return

  try {
    const { data } = await api.get(`/classes/${section.class_id}/subjects`, { params: { group: form.enrolment.group } })
    optionalSubjects.value = data.data.filter((row) => row.type === 'optional')
    const chosen = form.enrolment.optional_subject_id
    if (chosen && !optionalSubjects.value.some((row) => row.subject_id === chosen)) {
      form.enrolment.optional_subject_id = ''
    }
  } catch (error) {
    console.error('Failed to fetch the 4th-subject choices:', error)
  }
}

const onSectionChange = () => {
  const section = selectedSection.value
  if (!section?.class?.has_groups) {
    form.enrolment.group = ''
    form.enrolment.optional_subject_id = ''
  } else if (section.group) {
    form.enrolment.group = section.group
  }
  loadOptionalSubjects()
}

const onGroupChange = () => {
  form.enrolment.optional_subject_id = ''
  loadOptionalSubjects()
}

// Photo: same flow as StaffForm.
const photoFile = ref(null)
const removePhotoFlag = ref(false)
const existingPhotoUrl = ref(null)
const photoObjectUrl = ref(null)
const photoPreview = computed(() => photoObjectUrl.value || (removePhotoFlag.value ? null : existingPhotoUrl.value))

const revokePhotoObjectUrl = () => {
  if (photoObjectUrl.value) URL.revokeObjectURL(photoObjectUrl.value)
}
const onPhotoChange = (event) => {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  photoFile.value = file
  removePhotoFlag.value = false
  revokePhotoObjectUrl()
  photoObjectUrl.value = URL.createObjectURL(file)
}
const removePhoto = () => {
  photoFile.value = null
  revokePhotoObjectUrl()
  photoObjectUrl.value = null
  removePhotoFlag.value = true
}
onBeforeUnmount(revokePhotoObjectUrl)

const fetchLookups = async () => {
  try {
    // The API caps per_page at 100, so a school with more than 100 sections would see
    // only the first 100 here; page through meta.last_page if that ever happens.
    const [sectionRes, yearRes] = await Promise.all([
      api.get('/sections', { params: { per_page: 100, is_active: 1 } }),
      api.get('/academic-years', { params: { per_page: 100, is_active: 1 } }),
    ])
    sections.value = sectionRes.data.data
    activeYearName.value = yearRes.data.data[0]?.name || ''
  } catch (error) {
    console.error('Failed to fetch sections:', error)
  }
}

const fetchStudent = async () => {
  try {
    const { data } = await api.get(`/students/${route.params.id}`)
    const s = data.data
    Object.assign(form, {
      student_id: s.student_id,
      name_en: s.name_en || '',
      name_bn: s.name_bn || '',
      date_of_birth: s.date_of_birth || '',
      gender: s.gender || '',
      religion: s.religion || '',
      blood_group: s.blood_group || '',
      birth_registration_number: s.birth_registration_number || '',
      nationality: s.nationality || 'Bangladeshi',
      mobile: s.mobile || '',
      email: s.email || '',
      present_address: s.present_address || '',
      permanent_address: s.permanent_address || '',
      district: s.district || '',
      admission_date: s.admission_date || '',
      status: s.status,
      leaving_date: s.leaving_date || '',
      father_name_en: s.father.name_en || '',
      father_name_bn: s.father.name_bn || '',
      father_mobile: s.father.mobile || '',
      father_occupation: s.father.occupation || '',
      mother_name_en: s.mother.name_en || '',
      mother_name_bn: s.mother.name_bn || '',
      mother_mobile: s.mother.mobile || '',
      mother_occupation: s.mother.occupation || '',
      guardian_relation: s.guardian.relation,
      guardian_name: s.guardian.name,
      guardian_mobile: s.guardian.mobile,
    })
    sameAsPresent.value = !!s.present_address && s.present_address === s.permanent_address
    existingPhotoUrl.value = s.photo_url

    const enrolment = s.current_enrolment
    if (enrolment) {
      // A section that was deactivated since still has to be selectable for this student.
      if (!sections.value.some((x) => x.id === enrolment.section_id) && enrolment.section) {
        sections.value.push({ ...enrolment.section, class: enrolment.class })
      }
      form.enrolment.section_id = enrolment.section_id
      form.enrolment.group = enrolment.group || ''
      form.enrolment.optional_subject_id = enrolment.optional_subject_id || ''
      form.enrolment.roll_number = enrolment.roll_number ?? ''
      await loadOptionalSubjects()
    }
  } catch (error) {
    console.error('Failed to fetch student:', error)
  }
}

const buildPayload = () => {
  const payload = new FormData()
  if (isEdit.value) payload.append('_method', 'PUT')

  const scalarFields = [
    'name_en', 'name_bn', 'date_of_birth', 'gender', 'religion', 'blood_group',
    'birth_registration_number', 'nationality', 'mobile', 'email', 'present_address',
    'permanent_address', 'district', 'status', 'leaving_date',
    'father_name_en', 'father_name_bn', 'father_mobile', 'father_occupation',
    'mother_name_en', 'mother_name_bn', 'mother_mobile', 'mother_occupation',
    'guardian_relation', 'guardian_name', 'guardian_mobile', 'password', 'guardian_password',
  ]
  scalarFields.forEach((key) => payload.append(key, form[key] ?? ''))
  // The admission date is fixed once the student exists (it decided the student ID).
  if (!isEdit.value) payload.append('admission_date', form.admission_date)

  // Empty strings arrive as null on the API, which is how "no group" / "no roll" is sent.
  Object.entries(form.enrolment).forEach(([key, value]) => payload.append(`enrolment[${key}]`, value ?? ''))

  if (photoFile.value) payload.append('photo', photoFile.value)
  if (removePhotoFlag.value) payload.append('remove_photo', '1')

  return payload
}

const save = async () => {
  errors.value = {}
  formError.value = ''
  saving.value = true

  try {
    const config = { headers: { 'Content-Type': 'multipart/form-data' } }
    if (isEdit.value) {
      await api.post(`/students/${route.params.id}`, buildPayload(), config)
    } else {
      await api.post('/students', buildPayload(), config)
    }
    router.push('/students')
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors
      // The API asks for a guardian password only when the mobile has no login yet.
      if (errors.value.guardian_password) showGuardianPassword.value = true
      formError.value = 'Please fix the highlighted fields.'
    } else {
      console.error('Failed to save student:', error)
      formError.value = error.response?.data?.message || 'Failed to save student'
    }
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await fetchLookups()
  if (isEdit.value) await fetchStudent()
})
</script>
