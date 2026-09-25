<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">News &amp; Events</h1>
      <router-link to="/posts/create" class="btn btn-primary">
        ➕ Add Post
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
          @input="fetchPosts()"
        />
        <select v-model="filters.type" class="input" @change="fetchPosts()">
          <option value="">All Types</option>
          <option value="news">News</option>
          <option value="event">Event</option>
        </select>
      </div>
    </div>

    <div class="card">
      <div v-if="loading" class="text-center py-8">
        <p class="text-gray-500">Loading posts...</p>
      </div>

      <div v-else-if="posts.length === 0" class="text-center py-8">
        <p class="text-gray-500">No posts found</p>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Type</th>
              <th>Date</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="post in posts" :key="post.id" class="hover:bg-gray-50">
              <td class="font-medium">{{ post.title }}</td>
              <td class="capitalize">{{ post.type }}</td>
              <td>{{ formatDate(post.type === 'event' ? post.event_starts_at : post.published_at) }}</td>
              <td>
                <span :class="['badge', post.is_published ? 'badge-success' : 'badge-warning']">
                  {{ post.is_published ? 'Published' : 'Draft' }}
                </span>
              </td>
              <td>
                <div class="flex space-x-2">
                  <a
                    v-if="post.is_published"
                    :href="publicUrl(post)"
                    target="_blank"
                    rel="noopener"
                    class="text-primary-600 hover:text-primary-800"
                    title="View on website"
                  >
                    👁️
                  </a>
                  <router-link
                    :to="`/posts/${post.id}/edit`"
                    class="text-primary-600 hover:text-primary-800"
                    title="Edit"
                  >
                    ✏️
                  </router-link>
                  <button
                    @click="deletePost(post.id)"
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
            @click="fetchPosts(pagination.current_page - 1)"
            :disabled="pagination.current_page === 1"
            class="btn btn-secondary disabled:opacity-50"
          >
            Previous
          </button>
          <button
            @click="fetchPosts(pagination.current_page + 1)"
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

const posts = ref([])
const loading = ref(false)

const filters = reactive({
  search: '',
  type: '',
})

const pagination = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 15,
  total: 0,
  from: 0,
  to: 0,
})

const fetchPosts = async (page = 1) => {
  loading.value = true
  try {
    const response = await api.get('/posts', {
      params: { page, per_page: pagination.per_page, ...filters },
    })
    posts.value = response.data.data
    Object.assign(pagination, {
      current_page: response.data.meta.current_page,
      last_page: response.data.meta.last_page,
      total: response.data.meta.total,
      from: response.data.meta.from,
      to: response.data.meta.to,
    })
  } catch (error) {
    console.error('Failed to fetch posts:', error)
  } finally {
    loading.value = false
  }
}

const deletePost = async (id) => {
  if (!confirm('Are you sure you want to delete this post?')) return

  try {
    await api.delete(`/posts/${id}`)
    await fetchPosts(pagination.current_page)
  } catch (error) {
    console.error('Failed to delete post:', error)
    alert('Failed to delete post')
  }
}

const publicUrl = (post) => `/${post.type === 'event' ? 'events' : 'news'}/${post.slug}`

const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '-')

onMounted(() => {
  fetchPosts()
})
</script>
