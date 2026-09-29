<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">Media Library</h1>
      <div>
        <input
          ref="fileInput"
          type="file"
          accept="image/jpeg,image/png,image/webp,image/gif"
          multiple
          class="hidden"
          @change="onFilesSelected"
        />
        <button type="button" class="btn btn-primary" :disabled="uploading" @click="fileInput?.click()">
          {{ uploading ? 'Uploading...' : '⬆️ Upload images' }}
        </button>
      </div>
    </div>

    <p v-if="uploadError" class="rounded-lg bg-red-50 px-4 py-3 text-red-800">{{ uploadError }}</p>

    <div class="card">
      <input
        v-model="search"
        type="text"
        placeholder="Search by filename or alt text..."
        class="input"
        @input="onSearch"
      />
    </div>

    <div class="card">
      <div v-if="loading" class="text-center py-8">
        <p class="text-gray-500">Loading images...</p>
      </div>

      <div v-else-if="items.length === 0" class="text-center py-8">
        <p class="text-gray-500">No images yet</p>
      </div>

      <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
        <div v-for="item in items" :key="item.id" class="overflow-hidden rounded-lg border border-gray-200">
          <img :src="item.url" :alt="item.alt || ''" class="h-32 w-full object-cover" />
          <div class="space-y-1 p-2">
            <p class="truncate text-xs text-gray-500" :title="item.original_name">{{ item.original_name }}</p>
            <input
              :value="item.alt || ''"
              type="text"
              placeholder="Alt text"
              class="input py-1 text-xs"
              @change="updateAlt(item, $event.target.value)"
            />
            <button type="button" class="text-xs text-red-600 hover:underline" @click="remove(item)">Delete</button>
          </div>
        </div>
      </div>

      <div v-if="pagination.total > 0" class="mt-4 flex items-center justify-between">
        <p class="text-sm text-gray-700">
          Showing {{ pagination.from }} to {{ pagination.to }} of {{ pagination.total }} results
        </p>
        <div class="flex space-x-2">
          <button
            @click="fetchMedia(pagination.current_page - 1)"
            :disabled="pagination.current_page === 1"
            class="btn btn-secondary disabled:opacity-50"
          >
            Previous
          </button>
          <button
            @click="fetchMedia(pagination.current_page + 1)"
            :disabled="pagination.current_page === pagination.last_page"
            class="btn btn-secondary disabled:opacity-50"
          >
            Next
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '@/services/api'

const items = ref([])
const loading = ref(false)
const uploading = ref(false)
const uploadError = ref('')
const search = ref('')
const fileInput = ref(null)

const pagination = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 24,
  total: 0,
  from: 0,
  to: 0,
})

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
      from: data.meta.from,
      to: data.meta.to,
    })
  } catch (error) {
    console.error('Failed to fetch media:', error)
  } finally {
    loading.value = false
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
      await api.post('/media', formData, { headers: { 'Content-Type': 'multipart/form-data' } })
    }
    await fetchMedia(1)
  } catch (error) {
    uploadError.value =
      error.response?.data?.errors?.file?.[0] || error.response?.data?.message || 'Upload failed.'
  } finally {
    uploading.value = false
  }
}

const updateAlt = async (item, alt) => {
  try {
    const { data } = await api.put(`/media/${item.id}`, { alt: alt || null })
    item.alt = data.data.alt
  } catch (error) {
    console.error('Failed to update alt text:', error)
  }
}

const remove = async (item) => {
  if (!confirm('Delete this image?')) return

  try {
    await api.delete(`/media/${item.id}`)
    await fetchMedia(pagination.current_page)
  } catch (error) {
    alert(error.response?.data?.message || 'Failed to delete image')
  }
}

onMounted(() => fetchMedia())
</script>
