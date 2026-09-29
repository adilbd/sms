<template>
  <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="close">
    <div class="flex max-h-[85vh] w-full max-w-4xl flex-col rounded-lg bg-white shadow-xl">
      <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
        <h2 class="text-lg font-semibold text-gray-900">Select images</h2>
        <button type="button" class="text-gray-400 hover:text-gray-600" @click="close">✕</button>
      </div>

      <div class="flex border-b border-gray-200">
        <button
          type="button"
          class="px-6 py-3 text-sm font-medium"
          :class="tab === 'library' ? 'border-b-2 border-primary-600 text-primary-700' : 'text-gray-500'"
          @click="tab = 'library'"
        >
          Library
        </button>
        <button
          type="button"
          class="px-6 py-3 text-sm font-medium"
          :class="tab === 'upload' ? 'border-b-2 border-primary-600 text-primary-700' : 'text-gray-500'"
          @click="tab = 'upload'"
        >
          Upload
        </button>
      </div>

      <div class="flex-1 overflow-y-auto p-6">
        <div v-if="tab === 'library'">
          <input
            v-model="search"
            type="text"
            placeholder="Search by filename or alt text..."
            class="input mb-4"
            @input="onSearch"
          />

          <div v-if="loading" class="py-8 text-center text-gray-500">Loading images...</div>
          <div v-else-if="items.length === 0" class="py-8 text-center text-gray-500">No images found</div>
          <div v-else class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5">
            <button
              v-for="item in items"
              :key="item.id"
              type="button"
              class="relative overflow-hidden rounded-lg border-2"
              :class="isSelected(item) ? 'border-primary-600' : 'border-transparent'"
              @click="toggle(item)"
            >
              <img :src="item.url" :alt="item.alt || ''" class="h-24 w-full object-cover" />
              <span
                v-if="isSelected(item)"
                class="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded-full bg-primary-600 text-xs text-white"
              >
                ✓
              </span>
            </button>
          </div>

          <div v-if="pagination.total > 0" class="mt-4 flex items-center justify-between">
            <p class="text-sm text-gray-500">Page {{ pagination.current_page }} of {{ pagination.last_page }}</p>
            <div class="flex space-x-2">
              <button
                type="button"
                class="btn btn-secondary disabled:opacity-50"
                :disabled="pagination.current_page === 1"
                @click="fetchMedia(pagination.current_page - 1)"
              >
                Previous
              </button>
              <button
                type="button"
                class="btn btn-secondary disabled:opacity-50"
                :disabled="pagination.current_page === pagination.last_page"
                @click="fetchMedia(pagination.current_page + 1)"
              >
                Next
              </button>
            </div>
          </div>
        </div>

        <div v-else>
          <input
            ref="fileInput"
            type="file"
            accept="image/jpeg,image/png,image/webp,image/gif"
            multiple
            class="hidden"
            @change="onFilesSelected"
          />
          <button type="button" class="btn btn-primary" :disabled="uploading" @click="fileInput?.click()">
            {{ uploading ? 'Uploading...' : '⬆️ Choose images to upload' }}
          </button>
          <p v-if="uploadError" class="mt-2 text-sm text-red-600">{{ uploadError }}</p>

          <div v-if="uploaded.length" class="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5">
            <div
              v-for="item in uploaded"
              :key="item.id"
              class="relative overflow-hidden rounded-lg border-2 border-primary-600"
            >
              <img :src="item.url" :alt="item.alt || ''" class="h-24 w-full object-cover" />
              <span class="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded-full bg-primary-600 text-xs text-white">
                ✓
              </span>
            </div>
          </div>
        </div>
      </div>

      <div class="flex items-center justify-between border-t border-gray-200 px-6 py-4">
        <p class="text-sm text-gray-500">{{ totalSelectedCount }} selected</p>
        <div class="flex gap-2">
          <button type="button" class="btn btn-secondary" @click="close">Cancel</button>
          <button type="button" class="btn btn-primary" :disabled="totalSelectedCount === 0" @click="confirm">
            Add selected
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import api from '@/services/api'

// Reusable image picker: the Library tab searches and multi-selects existing media
// rows, the Upload tab uploads one or more new images (landing in the library) and
// selects them too. Emits 'select' with the combined list of MediaResource-shaped
// objects ({ id, url, alt, ... }) when the caller confirms.
const props = defineProps({
  open: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'select'])

const tab = ref('library')
const items = ref([])
const loading = ref(false)
const search = ref('')
const selected = ref([])
const uploaded = ref([])
const uploading = ref(false)
const uploadError = ref('')
const fileInput = ref(null)

const pagination = reactive({ current_page: 1, last_page: 1, per_page: 24, total: 0 })

const totalSelectedCount = computed(() => selected.value.length + uploaded.value.length)

let searchTimeout = null
const onSearch = () => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => fetchMedia(1), 300)
}

const fetchMedia = async (page = 1) => {
  loading.value = true
  try {
    const { data } = await api.get('/media', {
      params: { page, per_page: pagination.per_page, search: search.value || undefined },
    })
    items.value = data.data
    Object.assign(pagination, {
      current_page: data.meta.current_page,
      last_page: data.meta.last_page,
      total: data.meta.total,
    })
  } catch (error) {
    console.error('Failed to fetch media:', error)
  } finally {
    loading.value = false
  }
}

const isSelected = (item) => selected.value.some((i) => i.id === item.id)

const toggle = (item) => {
  if (isSelected(item)) {
    selected.value = selected.value.filter((i) => i.id !== item.id)
  } else {
    selected.value.push(item)
  }
}

const onFilesSelected = async (event) => {
  const files = Array.from(event.target.files || [])
  event.target.value = ''
  if (!files.length) return

  uploading.value = true
  uploadError.value = ''
  try {
    for (const file of files) {
      const formData = new FormData()
      formData.append('file', file)
      const { data } = await api.post('/media', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      uploaded.value.push(data.data)
    }
  } catch (error) {
    uploadError.value =
      error.response?.data?.errors?.file?.[0] || error.response?.data?.message || 'Upload failed.'
  } finally {
    uploading.value = false
  }
}

const reset = () => {
  selected.value = []
  uploaded.value = []
  uploadError.value = ''
  search.value = ''
  tab.value = 'library'
}

const close = () => {
  reset()
  emit('close')
}

const confirm = () => {
  const chosen = [...selected.value, ...uploaded.value]
  emit('select', chosen)
  reset()
  emit('close')
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) fetchMedia(1)
  }
)
</script>
