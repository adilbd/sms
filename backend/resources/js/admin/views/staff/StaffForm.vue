<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">{{ isEdit ? 'Edit Staff' : 'Add Staff' }}</h1>

    <form class="space-y-6" @submit.prevent="save">
      <section class="card space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Basic</h2>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Name (English)</label>
            <input v-model="form.name_en" type="text" class="input" />
            <p v-if="errors.name_en" class="text-sm text-red-600 mt-1">{{ errors.name_en[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Name (Bangla)</label>
            <input v-model="form.name_bn" type="text" class="input" />
            <p v-if="errors.name_bn" class="text-sm text-red-600 mt-1">{{ errors.name_bn[0] }}</p>
          </div>
          <p class="md:col-span-2 text-xs text-gray-500">At least one of the two names is required.</p>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Employee ID</label>
            <input v-model="form.employee_id" type="text" class="input" />
            <p v-if="errors.employee_id" class="text-sm text-red-600 mt-1">{{ errors.employee_id[0] }}</p>
          </div>
          <div class="flex items-center gap-2 pt-6">
            <input v-model="form.is_published" type="checkbox" id="is_published" />
            <label for="is_published" class="text-sm text-gray-700">Published on the public site</label>
          </div>
        </div>
      </section>

      <section class="card space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Employment</h2>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
            <select v-model="form.category" class="input" required>
              <option value="teacher">Teacher</option>
              <option value="staff">Staff</option>
            </select>
            <p v-if="errors.category" class="text-sm text-red-600 mt-1">{{ errors.category[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Position</label>
            <select v-model="form.position" class="input" required>
              <option value="head">Head</option>
              <option value="assistant_head">Assistant Head</option>
              <option value="teacher">Teacher</option>
              <option value="staff">Staff</option>
            </select>
            <p v-if="errors.position" class="text-sm text-red-600 mt-1">{{ errors.position[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Designation</label>
            <input v-model="form.designation" type="text" class="input" />
            <p v-if="errors.designation" class="text-sm text-red-600 mt-1">{{ errors.designation[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Teaching subject</label>
            <input v-model="form.subject" type="text" class="input" />
            <p v-if="errors.subject" class="text-sm text-red-600 mt-1">{{ errors.subject[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">MPO index</label>
            <input v-model="form.mpo_index" type="text" class="input" />
            <p v-if="errors.mpo_index" class="text-sm text-red-600 mt-1">{{ errors.mpo_index[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Joining date</label>
            <input v-model="form.joining_date" type="date" class="input" />
            <p v-if="errors.joining_date" class="text-sm text-red-600 mt-1">{{ errors.joining_date[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select v-model="form.status" class="input">
              <option value="active">Active</option>
              <option value="retired">Retired</option>
              <option value="transferred">Transferred</option>
              <option value="resigned">Resigned</option>
              <option value="deceased">Deceased</option>
            </select>
            <p v-if="errors.status" class="text-sm text-red-600 mt-1">{{ errors.status[0] }}</p>
          </div>
          <div v-if="form.status !== 'active'">
            <label class="block text-sm font-medium text-gray-700 mb-1">Leaving date</label>
            <input v-model="form.leaving_date" type="date" class="input" />
            <p v-if="errors.leaving_date" class="text-sm text-red-600 mt-1">{{ errors.leaving_date[0] }}</p>
          </div>
        </div>
      </section>

      <section class="card space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Shifts</h2>
        <div class="flex flex-wrap gap-4">
          <label v-for="shift in shifts" :key="shift.id" class="flex items-center gap-2">
            <input type="checkbox" :value="shift.id" v-model="form.shift_ids" />
            <span class="text-sm text-gray-700">{{ shift.name_bn }} ({{ shift.name_en }})</span>
          </label>
        </div>
        <p v-if="errors.shift_ids" class="text-sm text-red-600 mt-1">{{ errors.shift_ids[0] }}</p>
        <p class="text-xs text-gray-500">Every staff member must have at least one shift.</p>
      </section>

      <section class="card space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Personal</h2>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Gender</label>
            <select v-model="form.gender" class="input">
              <option value="">— Select —</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
              <option value="other">Other</option>
            </select>
            <p v-if="errors.gender" class="text-sm text-red-600 mt-1">{{ errors.gender[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Religion</label>
            <input v-model="form.religion" type="text" class="input" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Date of birth</label>
            <input v-model="form.date_of_birth" type="date" class="input" />
            <p v-if="errors.date_of_birth" class="text-sm text-red-600 mt-1">{{ errors.date_of_birth[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Blood group</label>
            <select v-model="form.blood_group" class="input">
              <option value="">— Select —</option>
              <option v-for="bg in bloodGroups" :key="bg" :value="bg">{{ bg }}</option>
            </select>
            <p v-if="errors.blood_group" class="text-sm text-red-600 mt-1">{{ errors.blood_group[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nationality</label>
            <input v-model="form.nationality" type="text" class="input" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">NID</label>
            <input v-model="form.nid" type="text" class="input" />
          </div>
        </div>
      </section>

      <section class="card space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Contact</h2>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mobile</label>
            <input v-model="form.mobile" type="text" class="input" />
            <p v-if="errors.mobile" class="text-sm text-red-600 mt-1">{{ errors.mobile[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input v-model="form.email" type="email" class="input" />
            <p v-if="errors.email" class="text-sm text-red-600 mt-1">{{ errors.email[0] }}</p>
          </div>
        </div>
        <label class="flex items-center gap-2">
          <input v-model="form.show_contact" type="checkbox" />
          <span class="text-sm text-gray-700">Show mobile/email on the public profile</span>
        </label>
      </section>

      <section class="card space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Address</h2>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Present address</label>
          <textarea v-model="form.present_address" rows="2" class="input"></textarea>
        </div>
        <label class="flex items-center gap-2">
          <input type="checkbox" v-model="sameAsPresent" />
          <span class="text-sm text-gray-700">Permanent address is the same as present</span>
        </label>
        <div v-if="!sameAsPresent">
          <label class="block text-sm font-medium text-gray-700 mb-1">Permanent address</label>
          <textarea v-model="form.permanent_address" rows="2" class="input"></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">District</label>
          <input v-model="form.district" type="text" class="input" />
        </div>
      </section>

      <section class="card space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Photo</h2>
        <div class="flex items-center gap-4">
          <img v-if="photoPreview" :src="photoPreview" alt="" class="h-24 w-24 rounded-full border border-gray-200 object-cover" />
          <span v-else class="flex h-24 w-24 items-center justify-center rounded-full border border-dashed border-gray-300 text-3xl text-gray-300">🧑</span>
          <div class="flex flex-col gap-2">
            <input type="file" accept="image/jpeg,image/png,image/webp" class="input" @change="onPhotoChange" />
            <button v-if="photoPreview" type="button" class="text-sm text-red-600 hover:underline" @click="removePhoto">Remove photo</button>
          </div>
        </div>
        <p v-if="errors.photo" class="text-sm text-red-600 mt-1">{{ errors.photo[0] }}</p>
      </section>

      <section class="card space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Bio</h2>
        <textarea v-model="form.bio" rows="4" class="input"></textarea>
        <p v-if="errors.bio" class="text-sm text-red-600 mt-1">{{ errors.bio[0] }}</p>
      </section>

      <section class="card space-y-4">
        <div class="flex items-center justify-between">
          <h2 class="text-lg font-semibold text-gray-900">Education</h2>
          <button type="button" class="btn btn-secondary" @click="addEducation">➕ Add</button>
        </div>
        <p v-if="educationErrors.length" class="text-sm text-red-600">{{ educationErrors.join(' ') }}</p>
        <p v-if="educations.length === 0" class="text-sm text-gray-500">No education rows yet.</p>
        <ul v-else class="space-y-3">
          <li v-for="(row, index) in educations" :key="row.key" class="grid grid-cols-1 gap-2 rounded-lg border border-gray-200 p-3 md:grid-cols-5">
            <input v-model="row.degree" type="text" placeholder="Degree / examination" class="input md:col-span-2" />
            <input v-model="row.board_university" type="text" placeholder="Board / University" class="input" />
            <input v-model="row.passing_year" type="text" placeholder="Passing year" class="input" />
            <div class="flex items-center gap-2">
              <input v-model="row.result" type="text" placeholder="Result" class="input" />
              <button type="button" class="text-gray-500 hover:text-gray-800 disabled:opacity-30" :disabled="index === 0" @click="moveRow(educations, index, -1)" title="Move up">↑</button>
              <button type="button" class="text-gray-500 hover:text-gray-800 disabled:opacity-30" :disabled="index === educations.length - 1" @click="moveRow(educations, index, 1)" title="Move down">↓</button>
              <button type="button" class="text-red-600 hover:text-red-800" @click="educations.splice(index, 1)" title="Remove">🗑️</button>
            </div>
          </li>
        </ul>
      </section>

      <section class="card space-y-4">
        <div class="flex items-center justify-between">
          <h2 class="text-lg font-semibold text-gray-900">Training</h2>
          <button type="button" class="btn btn-secondary" @click="addTraining">➕ Add</button>
        </div>
        <p v-if="trainingErrors.length" class="text-sm text-red-600">{{ trainingErrors.join(' ') }}</p>
        <p v-if="trainings.length === 0" class="text-sm text-gray-500">No training rows yet.</p>
        <ul v-else class="space-y-3">
          <li v-for="(row, index) in trainings" :key="row.key" class="grid grid-cols-1 gap-2 rounded-lg border border-gray-200 p-3 md:grid-cols-5">
            <input v-model="row.title" type="text" placeholder="Training title" class="input md:col-span-2" />
            <input v-model="row.organizer" type="text" placeholder="Organizer / place" class="input" />
            <input v-model="row.duration" type="text" placeholder="Duration" class="input" />
            <div class="flex items-center gap-2">
              <input v-model="row.year" type="text" placeholder="Year" class="input" />
              <button type="button" class="text-gray-500 hover:text-gray-800 disabled:opacity-30" :disabled="index === 0" @click="moveRow(trainings, index, -1)" title="Move up">↑</button>
              <button type="button" class="text-gray-500 hover:text-gray-800 disabled:opacity-30" :disabled="index === trainings.length - 1" @click="moveRow(trainings, index, 1)" title="Move down">↓</button>
              <button type="button" class="text-red-600 hover:text-red-800" @click="trainings.splice(index, 1)" title="Remove">🗑️</button>
            </div>
          </li>
        </ul>
      </section>

      <div class="flex justify-end space-x-2">
        <router-link to="/staff" class="btn btn-secondary">Cancel</router-link>
        <button type="submit" class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving...' : 'Save' }}</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'

const route = useRoute()
const router = useRouter()

const isEdit = computed(() => !!route.params.id)
const saving = ref(false)
const errors = ref({})
const shifts = ref([])
const bloodGroups = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-']

const form = reactive({
  name_en: '',
  name_bn: '',
  employee_id: '',
  category: 'teacher',
  position: 'teacher',
  designation: '',
  subject: '',
  mpo_index: '',
  joining_date: '',
  leaving_date: '',
  status: 'active',
  gender: '',
  religion: '',
  date_of_birth: '',
  blood_group: '',
  nationality: 'Bangladeshi',
  nid: '',
  mobile: '',
  email: '',
  show_contact: false,
  present_address: '',
  permanent_address: '',
  district: '',
  bio: '',
  is_published: true,
  shift_ids: [],
})

const sameAsPresent = ref(false)
watch(sameAsPresent, (value) => {
  if (value) form.permanent_address = form.present_address
})
watch(() => form.present_address, (value) => {
  if (sameAsPresent.value) form.permanent_address = value
})

let nextKey = 0
const educations = ref([])
const trainings = ref([])

const educationErrors = computed(() =>
  Object.entries(errors.value).filter(([key]) => key.startsWith('educations.')).flatMap(([, m]) => m)
)
const trainingErrors = computed(() =>
  Object.entries(errors.value).filter(([key]) => key.startsWith('trainings.')).flatMap(([, m]) => m)
)

const addEducation = () => {
  educations.value.push({ key: nextKey++, id: null, degree: '', institution: '', board_university: '', passing_year: '', result: '' })
}
const addTraining = () => {
  trainings.value.push({ key: nextKey++, id: null, title: '', organizer: '', duration: '', year: '' })
}
const moveRow = (list, index, delta) => {
  const target = index + delta
  if (target < 0 || target >= list.length) return
  const copy = [...list]
  ;[copy[index], copy[target]] = [copy[target], copy[index]]
  list.splice(0, list.length, ...copy)
}

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

const fetchShifts = async () => {
  try {
    const { data } = await api.get('/shifts', { params: { per_page: 100, is_active: 1 } })
    shifts.value = data.data
  } catch (error) {
    console.error('Failed to fetch shifts:', error)
  }
}

const fetchStaff = async () => {
  try {
    const { data } = await api.get(`/staff/${route.params.id}`)
    const member = data.data
    Object.assign(form, {
      name_en: member.name_en || '',
      name_bn: member.name_bn || '',
      employee_id: member.employee_id || '',
      category: member.category,
      position: member.position,
      designation: member.designation || '',
      subject: member.subject || '',
      mpo_index: member.mpo_index || '',
      joining_date: member.joining_date || '',
      leaving_date: member.leaving_date || '',
      status: member.status,
      gender: member.gender || '',
      religion: member.religion || '',
      date_of_birth: member.date_of_birth || '',
      blood_group: member.blood_group || '',
      nationality: member.nationality || 'Bangladeshi',
      nid: member.nid || '',
      mobile: member.mobile || '',
      email: member.email || '',
      show_contact: member.show_contact,
      present_address: member.present_address || '',
      permanent_address: member.permanent_address || '',
      district: member.district || '',
      bio: member.bio || '',
      is_published: member.is_published,
      shift_ids: (member.shifts || []).map((s) => s.id),
    })
    sameAsPresent.value = !!member.present_address && member.present_address === member.permanent_address
    existingPhotoUrl.value = member.photo_url
    educations.value = (member.educations || []).map((e) => ({ key: nextKey++, ...e }))
    trainings.value = (member.trainings || []).map((t) => ({ key: nextKey++, ...t }))
  } catch (error) {
    console.error('Failed to fetch staff member:', error)
  }
}

const save = async () => {
  errors.value = {}
  saving.value = true

  const payload = new FormData()
  if (isEdit.value) payload.append('_method', 'PUT')

  const scalarFields = [
    'name_en', 'name_bn', 'employee_id', 'category', 'position', 'designation', 'subject',
    'mpo_index', 'joining_date', 'leaving_date', 'status', 'gender', 'religion', 'date_of_birth',
    'blood_group', 'nationality', 'nid', 'mobile', 'email', 'present_address', 'permanent_address',
    'district', 'bio',
  ]
  scalarFields.forEach((key) => payload.append(key, form[key] ?? ''))
  payload.append('show_contact', form.show_contact ? '1' : '0')
  payload.append('is_published', form.is_published ? '1' : '0')
  form.shift_ids.forEach((id) => payload.append('shift_ids[]', id))

  educations.value.forEach((row, index) => {
    if (row.id) payload.append(`educations[${index}][id]`, row.id)
    payload.append(`educations[${index}][degree]`, row.degree || '')
    payload.append(`educations[${index}][institution]`, row.institution || '')
    payload.append(`educations[${index}][board_university]`, row.board_university || '')
    payload.append(`educations[${index}][passing_year]`, row.passing_year || '')
    payload.append(`educations[${index}][result]`, row.result || '')
  })
  trainings.value.forEach((row, index) => {
    if (row.id) payload.append(`trainings[${index}][id]`, row.id)
    payload.append(`trainings[${index}][title]`, row.title || '')
    payload.append(`trainings[${index}][organizer]`, row.organizer || '')
    payload.append(`trainings[${index}][duration]`, row.duration || '')
    payload.append(`trainings[${index}][year]`, row.year || '')
  })

  if (photoFile.value) payload.append('photo', photoFile.value)
  if (removePhotoFlag.value) payload.append('remove_photo', '1')

  try {
    if (isEdit.value) {
      await api.post(`/staff/${route.params.id}`, payload, { headers: { 'Content-Type': 'multipart/form-data' } })
    } else {
      await api.post('/staff', payload, { headers: { 'Content-Type': 'multipart/form-data' } })
    }
    router.push('/staff')
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors
    } else {
      console.error('Failed to save staff member:', error)
      alert(error.response?.data?.message || 'Failed to save staff member')
    }
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchShifts()
  if (isEdit.value) fetchStaff()
})
</script>
