<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">Galleries</h1>
      <router-link to="/galleries/create" class="btn btn-primary">
        ➕ Add Gallery
      </router-link>
    </div>

    <div class="card">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <input
          v-model="filters.search"
          type="text"
          placeholder="Search by title..."
          class="input"
          @input="fetchGalleries()"
        />
        <select v-model="filters.is_published" class="input" @change="fetchGalleries()">
          <option value="">All statuses</option>
          <option value="1">Published</option>
          <option value="0">Draft</option>
        </select>
      </div>
    </div>

    <div class="card">
      <div v-if="loading" class="text-center py-8">
        <p class="text-gray-500">Loading galleries...</p>
      </div>

      <div v-else-if="galleries.length === 0" class="text-center py-8">
        <p class="text-gray-500">No galleries found</p>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Cover</th>
              <th>Title</th>
              <th>Items</th>
              <th>Status</th>
              <th>Updated</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="gallery in galleries" :key="gallery.id" class="hover:bg-gray-50">
              <td>
                <img
                  v-if="gallery.cover_url"
                  :src="gallery.cover_url"
                  alt=""
                  class="h-12 w-16 rounded object-cover"
                />
                <span v-else class="flex h-12 w-16 items-center justify-center rounded bg-gray-100 text-xl">🖼️</span>
              </td>
              <td class="font-medium">{{ gallery.title }}</td>
              <td class="text-gray-500">{{ gallery.item_count }}</td>
              <td>
                <span :class="['badge', gallery.is_published ? 'badge-success' : 'badge-warning']">
                  {{ gallery.is_published ? 'Published' : 'Draft' }}
                </span>
              </td>
              <td>{{ formatDate(gallery.updated_at) }}</td>
              <td>
                <div class="flex space-x-2">
                  <a
                    v-if="gallery.is_published"
                    :href="gallery.web_url"
                    target="_blank"
                    rel="noopener"
                    class="text-primary-600 hover:text-primary-800"
                    title="View on website"
                  >
                    👁️
                  </a>
                  <router-link
                    :to="`/galleries/${gallery.id}/edit`"
                    class="text-primary-600 hover:text-primary-800"
                    title="Edit"
                  >
                    ✏️
                  </router-link>
                  <button
                    @click="deleteGallery(gallery.id)"
                    class="text-red-600 hover:text-red-800"
                    title="Delete"
                  >
                    🗑️
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="pagination.total > 0" class="mt-4 flex justify-between items-center">
        <p class="text-sm text-gray-700">
          Showing {{ pagination.from }} to {{ pagination.to }} of {{ pagination.total }} results
        </p>
        <div class="flex space-x-2">
          <button
            @click="fetchGalleries(pagination.current_page - 1)"
            :disabled="pagination.current_page === 1"
            class="btn btn-secondary disabled:opacity-50"
          >
            Previous
          </button>
          <button
            @click="fetchGalleries(pagination.current_page + 1)"
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

const galleries = ref([])
const loading = ref(false)

const filters = reactive({
  search: '',
  is_published: '',
})

const pagination = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 15,
  total: 0,
  from: 0,
  to: 0,
})

const fetchGalleries = async (page = 1) => {
  loading.value = true
  try {
    const response = await api.get('/galleries', {
      params: { page, per_page: pagination.per_page, ...filters },
    })
    galleries.value = response.data.data
    Object.assign(pagination, {
      current_page: response.data.meta.current_page,
      last_page: response.data.meta.last_page,
      total: response.data.meta.total,
      from: response.data.meta.from,
      to: response.data.meta.to,
    })
  } catch (error) {
    console.error('Failed to fetch galleries:', error)
  } finally {
    loading.value = false
  }
}

const deleteGallery = async (id) => {
  if (!confirm('Are you sure you want to delete this gallery? Its items will be removed, but the library images will stay.')) return

  try {
    await api.delete(`/galleries/${id}`)
    await fetchGalleries(pagination.current_page)
  } catch (error) {
    console.error('Failed to delete gallery:', error)
    alert('Failed to delete gallery')
  }
}

const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '-')

onMounted(() => {
  fetchGalleries()
})
</script>
