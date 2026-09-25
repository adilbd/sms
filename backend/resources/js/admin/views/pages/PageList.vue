<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">Pages</h1>
      <router-link to="/pages/create" class="btn btn-primary">
        ➕ Add Page
      </router-link>
    </div>

    <!-- Filters -->
    <div class="card">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <input
          v-model="filters.search"
          type="text"
          placeholder="Search by title..."
          class="input"
          @input="fetchPages()"
        />
        <select v-model="filters.is_published" class="input" @change="fetchPages()">
          <option value="">All statuses</option>
          <option value="1">Published</option>
          <option value="0">Draft</option>
        </select>
      </div>
    </div>

    <div class="card">
      <div v-if="loading" class="text-center py-8">
        <p class="text-gray-500">Loading pages...</p>
      </div>

      <div v-else-if="pages.length === 0" class="text-center py-8">
        <p class="text-gray-500">No pages found</p>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Slug</th>
              <th>Status</th>
              <th>Updated</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="page in pages" :key="page.id" class="hover:bg-gray-50">
              <td class="font-medium">{{ page.title }}</td>
              <td class="text-gray-500">{{ page.slug }}</td>
              <td>
                <span :class="['badge', page.is_published ? 'badge-success' : 'badge-warning']">
                  {{ page.is_published ? 'Published' : 'Draft' }}
                </span>
              </td>
              <td>{{ formatDate(page.updated_at) }}</td>
              <td>
                <div class="flex space-x-2">
                  <a
                    v-if="page.is_published"
                    :href="page.web_url"
                    target="_blank"
                    rel="noopener"
                    class="text-primary-600 hover:text-primary-800"
                    title="View on website"
                  >
                    👁️
                  </a>
                  <router-link
                    :to="`/pages/${page.id}/edit`"
                    class="text-primary-600 hover:text-primary-800"
                    title="Edit"
                  >
                    ✏️
                  </router-link>
                  <button
                    @click="deletePage(page.id)"
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

      <!-- Pagination -->
      <div v-if="pagination.total > 0" class="mt-4 flex justify-between items-center">
        <p class="text-sm text-gray-700">
          Showing {{ pagination.from }} to {{ pagination.to }} of {{ pagination.total }} results
        </p>
        <div class="flex space-x-2">
          <button
            @click="fetchPages(pagination.current_page - 1)"
            :disabled="pagination.current_page === 1"
            class="btn btn-secondary disabled:opacity-50"
          >
            Previous
          </button>
          <button
            @click="fetchPages(pagination.current_page + 1)"
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
import { ref, reactive, onMounted } from 'vue'
import api from '@/services/api'

const pages = ref([])
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

const fetchPages = async (page = 1) => {
  loading.value = true
  try {
    const response = await api.get('/pages', {
      params: { page, per_page: pagination.per_page, ...filters },
    })
    pages.value = response.data.data
    Object.assign(pagination, {
      current_page: response.data.meta.current_page,
      last_page: response.data.meta.last_page,
      total: response.data.meta.total,
      from: response.data.meta.from,
      to: response.data.meta.to,
    })
  } catch (error) {
    console.error('Failed to fetch pages:', error)
  } finally {
    loading.value = false
  }
}

const deletePage = async (id) => {
  if (!confirm('Are you sure you want to delete this page?')) return

  try {
    await api.delete(`/pages/${id}`)
    await fetchPages(pagination.current_page)
  } catch (error) {
    console.error('Failed to delete page:', error)
    alert('Failed to delete page')
  }
}

const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '-')

onMounted(() => {
  fetchPages()
})
</script>
