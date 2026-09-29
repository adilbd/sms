<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">Institute Settings</h1>

    <p v-if="successMessage" class="rounded-lg bg-green-50 px-4 py-3 text-green-800" role="status">
      {{ successMessage }}
    </p>

    <form class="space-y-6" @submit.prevent="save">
      <section class="card space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Identity</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Name (English)</label>
            <input v-model="form.name_en" type="text" class="input" maxlength="255" />
            <p v-if="errors.name_en" class="text-sm text-red-600 mt-1">{{ errors.name_en[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Name (Bangla)</label>
            <input v-model="form.name_bn" type="text" class="input" maxlength="255" />
            <p v-if="errors.name_bn" class="text-sm text-red-600 mt-1">{{ errors.name_bn[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Short name</label>
            <input v-model="form.short_name" type="text" class="input" maxlength="255" />
            <p v-if="errors.short_name" class="text-sm text-red-600 mt-1">{{ errors.short_name[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Motto</label>
            <input v-model="form.motto" type="text" class="input" maxlength="255" />
            <p v-if="errors.motto" class="text-sm text-red-600 mt-1">{{ errors.motto[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Established year</label>
            <input v-model="form.established_year" type="number" class="input" />
            <p v-if="errors.established_year" class="text-sm text-red-600 mt-1">{{ errors.established_year[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">EIIN</label>
            <input v-model="form.eiin" type="text" class="input" maxlength="6" placeholder="6-digit BANBEIS number" />
            <p v-if="errors.eiin" class="text-sm text-red-600 mt-1">{{ errors.eiin[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Institute code</label>
            <input v-model="form.institute_code" type="text" class="input" maxlength="255" />
            <p v-if="errors.institute_code" class="text-sm text-red-600 mt-1">{{ errors.institute_code[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">MPO code</label>
            <input v-model="form.mpo_code" type="text" class="input" maxlength="255" />
            <p v-if="errors.mpo_code" class="text-sm text-red-600 mt-1">{{ errors.mpo_code[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Education board</label>
            <select v-model="form.education_board" class="input">
              <option value="">— Select —</option>
              <option v-for="board in educationBoards" :key="board" :value="board">{{ label(board) }}</option>
            </select>
            <p v-if="errors.education_board" class="text-sm text-red-600 mt-1">{{ errors.education_board[0] }}</p>
          </div>
        </div>
      </section>

      <section class="card space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Branding</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Logo</label>
            <div v-if="logoPreview" class="mb-2 flex items-center gap-3">
              <img :src="logoPreview" alt="Logo preview" class="h-16 w-16 rounded border border-gray-200 object-contain" />
              <button type="button" class="text-sm text-red-600 hover:underline" @click="removeLogo">Remove</button>
            </div>
            <input type="file" accept="image/jpeg,image/png,image/webp" class="input" @change="onLogoChange" />
            <p class="text-xs text-gray-500 mt-1">JPG, PNG or WEBP, up to 2MB.</p>
            <p v-if="errors.logo" class="text-sm text-red-600 mt-1">{{ errors.logo[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Favicon</label>
            <div v-if="faviconPreview" class="mb-2 flex items-center gap-3">
              <img :src="faviconPreview" alt="Favicon preview" class="h-8 w-8 rounded border border-gray-200 object-contain" />
              <button type="button" class="text-sm text-red-600 hover:underline" @click="removeFavicon">Remove</button>
            </div>
            <input type="file" accept="image/png,image/x-icon,.ico" class="input" @change="onFaviconChange" />
            <p class="text-xs text-gray-500 mt-1">PNG or ICO, up to 512KB.</p>
            <p v-if="errors.favicon" class="text-sm text-red-600 mt-1">{{ errors.favicon[0] }}</p>
          </div>
        </div>
      </section>

      <section class="card space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Contact</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
            <input v-model="form.phone" type="text" class="input" maxlength="255" />
            <p v-if="errors.phone" class="text-sm text-red-600 mt-1">{{ errors.phone[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Telephone</label>
            <input v-model="form.telephone" type="text" class="input" maxlength="255" />
            <p v-if="errors.telephone" class="text-sm text-red-600 mt-1">{{ errors.telephone[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input v-model="form.email" type="email" class="input" maxlength="255" />
            <p v-if="errors.email" class="text-sm text-red-600 mt-1">{{ errors.email[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Office hours</label>
            <input v-model="form.office_hours" type="text" class="input" maxlength="255" placeholder="Sun–Thu, 9am–4pm" />
            <p v-if="errors.office_hours" class="text-sm text-red-600 mt-1">{{ errors.office_hours[0] }}</p>
          </div>
        </div>
      </section>

      <section class="card space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Address</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Village</label>
            <input v-model="form.village" type="text" class="input" maxlength="255" />
            <p v-if="errors.village" class="text-sm text-red-600 mt-1">{{ errors.village[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ward</label>
            <input v-model="form.ward" type="text" class="input" maxlength="255" />
            <p v-if="errors.ward" class="text-sm text-red-600 mt-1">{{ errors.ward[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Union</label>
            <input v-model="form.union" type="text" class="input" maxlength="255" />
            <p v-if="errors.union" class="text-sm text-red-600 mt-1">{{ errors.union[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Post office</label>
            <input v-model="form.post_office" type="text" class="input" maxlength="255" />
            <p v-if="errors.post_office" class="text-sm text-red-600 mt-1">{{ errors.post_office[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Post code</label>
            <input v-model="form.post_code" type="text" class="input" maxlength="255" />
            <p v-if="errors.post_code" class="text-sm text-red-600 mt-1">{{ errors.post_code[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Upazila</label>
            <input v-model="form.upazila" type="text" class="input" maxlength="255" />
            <p v-if="errors.upazila" class="text-sm text-red-600 mt-1">{{ errors.upazila[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">District</label>
            <input v-model="form.district" type="text" class="input" maxlength="255" />
            <p v-if="errors.district" class="text-sm text-red-600 mt-1">{{ errors.district[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Division</label>
            <select v-model="form.division" class="input">
              <option value="">— Select —</option>
              <option v-for="division in divisions" :key="division" :value="division">{{ label(division) }}</option>
            </select>
            <p v-if="errors.division" class="text-sm text-red-600 mt-1">{{ errors.division[0] }}</p>
          </div>
        </div>
      </section>

      <section class="card space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Location</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Latitude</label>
            <input v-model="form.latitude" type="number" step="any" class="input" />
            <p v-if="errors.latitude" class="text-sm text-red-600 mt-1">{{ errors.latitude[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Longitude</label>
            <input v-model="form.longitude" type="number" step="any" class="input" />
            <p v-if="errors.longitude" class="text-sm text-red-600 mt-1">{{ errors.longitude[0] }}</p>
          </div>
        </div>
        <a v-if="mapUrl" :href="mapUrl" target="_blank" rel="noopener" class="text-sm font-medium text-primary-700 hover:underline">
          View on map →
        </a>
      </section>

      <section class="card space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Social</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Facebook URL</label>
            <input v-model="form.facebook_url" type="url" class="input" maxlength="255" />
            <p v-if="errors.facebook_url" class="text-sm text-red-600 mt-1">{{ errors.facebook_url[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">YouTube URL</label>
            <input v-model="form.youtube_url" type="url" class="input" maxlength="255" />
            <p v-if="errors.youtube_url" class="text-sm text-red-600 mt-1">{{ errors.youtube_url[0] }}</p>
          </div>
        </div>
      </section>

      <div class="flex items-center justify-end gap-3">
        <p v-if="successMessage" class="text-sm text-green-800">{{ successMessage }}</p>
        <button type="submit" class="btn btn-primary" :disabled="saving">
          {{ saving ? 'Saving...' : 'Save' }}
        </button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import api from '@/services/api'

// Mirrors App\Support\InstituteSettings::EDUCATION_BOARDS / DIVISIONS.
const EDUCATION_BOARDS = [
  'dhaka', 'rajshahi', 'cumilla', 'jashore', 'chattogram',
  'barishal', 'sylhet', 'dinajpur', 'mymensingh', 'madrasah', 'technical',
]
const DIVISIONS = [
  'barishal', 'chattogram', 'dhaka', 'khulna',
  'mymensingh', 'rajshahi', 'rangpur', 'sylhet',
]

const educationBoards = EDUCATION_BOARDS
const divisions = DIVISIONS

const label = (value) => value.charAt(0).toUpperCase() + value.slice(1)

// Every text/select key the backend registry stores (see App\Support\InstituteSettings).
const TEXT_FIELDS = [
  'name_en', 'name_bn', 'short_name', 'motto', 'established_year', 'eiin', 'institute_code', 'mpo_code', 'education_board',
  'phone', 'telephone', 'email', 'office_hours',
  'village', 'ward', 'union', 'post_office', 'post_code', 'upazila', 'district', 'division',
  'latitude', 'longitude',
  'facebook_url', 'youtube_url',
]

const form = reactive(Object.fromEntries(TEXT_FIELDS.map((key) => [key, ''])))

const errors = ref({})
const saving = ref(false)
const successMessage = ref('')

const logoFile = ref(null)
const faviconFile = ref(null)
const removeLogoFlag = ref(false)
const removeFaviconFlag = ref(false)
const existingLogoUrl = ref(null)
const existingFaviconUrl = ref(null)
const logoObjectUrl = ref(null)
const faviconObjectUrl = ref(null)

const logoPreview = computed(() => logoObjectUrl.value || (removeLogoFlag.value ? null : existingLogoUrl.value))
const faviconPreview = computed(() => faviconObjectUrl.value || (removeFaviconFlag.value ? null : existingFaviconUrl.value))

const mapUrl = computed(() => {
  if (!form.latitude || !form.longitude) return null
  return `https://www.google.com/maps?q=${form.latitude},${form.longitude}`
})

const applySettings = (settings) => {
  TEXT_FIELDS.forEach((key) => {
    form[key] = settings[key] ?? ''
  })
  existingLogoUrl.value = settings.logo_url
  existingFaviconUrl.value = settings.favicon_url
}

const fetchSettings = async () => {
  try {
    const { data } = await api.get('/settings/institute')
    applySettings(data.data)
  } catch (error) {
    console.error('Failed to fetch institute settings:', error)
  }
}

const revokeLogoObjectUrl = () => {
  if (logoObjectUrl.value) URL.revokeObjectURL(logoObjectUrl.value)
}

const revokeFaviconObjectUrl = () => {
  if (faviconObjectUrl.value) URL.revokeObjectURL(faviconObjectUrl.value)
}

const onLogoChange = (event) => {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  logoFile.value = file
  removeLogoFlag.value = false
  revokeLogoObjectUrl()
  logoObjectUrl.value = URL.createObjectURL(file)
}

const onFaviconChange = (event) => {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  faviconFile.value = file
  removeFaviconFlag.value = false
  revokeFaviconObjectUrl()
  faviconObjectUrl.value = URL.createObjectURL(file)
}

const removeLogo = () => {
  logoFile.value = null
  revokeLogoObjectUrl()
  logoObjectUrl.value = null
  removeLogoFlag.value = true
}

const removeFavicon = () => {
  faviconFile.value = null
  revokeFaviconObjectUrl()
  faviconObjectUrl.value = null
  removeFaviconFlag.value = true
}

onBeforeUnmount(() => {
  revokeLogoObjectUrl()
  revokeFaviconObjectUrl()
})

const save = async () => {
  errors.value = {}
  successMessage.value = ''
  saving.value = true

  const payload = new FormData()
  payload.append('_method', 'PUT')
  // Always send every field (even blank) so clearing a field in the form clears the
  // setting too; Laravel's ConvertEmptyStringsToNull middleware turns '' into null.
  TEXT_FIELDS.forEach((key) => payload.append(key, form[key] ?? ''))
  if (logoFile.value) payload.append('logo', logoFile.value)
  if (faviconFile.value) payload.append('favicon', faviconFile.value)
  if (removeLogoFlag.value) payload.append('remove_logo', '1')
  if (removeFaviconFlag.value) payload.append('remove_favicon', '1')

  try {
    const { data } = await api.post('/settings/institute', payload, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    applySettings(data.data)
    logoFile.value = null
    faviconFile.value = null
    revokeLogoObjectUrl()
    revokeFaviconObjectUrl()
    logoObjectUrl.value = null
    faviconObjectUrl.value = null
    removeLogoFlag.value = false
    removeFaviconFlag.value = false
    successMessage.value = data.message || 'Institute settings saved'
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors
    } else {
      console.error('Failed to save institute settings:', error)
      alert(error.response?.data?.message || 'Failed to save institute settings')
    }
  } finally {
    saving.value = false
  }
}

onMounted(fetchSettings)
</script>
