<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">{{ isEdit ? 'Edit Post' : 'Add Post' }}</h1>

    <form class="card space-y-6" @submit.prevent="save">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
          <select v-model="form.type" class="input">
            <option value="news">News</option>
            <option value="event">Event</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Slug (optional)</label>
          <input v-model="form.slug" type="text" class="input" placeholder="auto-generated from title" />
          <p v-if="errors.slug" class="text-sm text-red-600 mt-1">{{ errors.slug[0] }}</p>
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
          <input v-model="form.title" type="text" class="input" required />
          <p v-if="errors.title" class="text-sm text-red-600 mt-1">{{ errors.title[0] }}</p>
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Excerpt</label>
          <textarea v-model="form.excerpt" rows="2" class="input" maxlength="500"></textarea>
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Body</label>
          <rich-text-editor v-model="form.body" />
          <p v-if="errors.body" class="text-sm text-red-600 mt-1">{{ errors.body[0] }}</p>
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Cover image URL (optional)</label>
          <input v-model="form.cover_image" type="text" class="input" placeholder="https://... or images/cover.jpg" />
        </div>

        <template v-if="form.type === 'event'">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Starts at</label>
            <input v-model="form.event_starts_at" type="datetime-local" class="input" />
            <p v-if="errors.event_starts_at" class="text-sm text-red-600 mt-1">{{ errors.event_starts_at[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ends at</label>
            <input v-model="form.event_ends_at" type="datetime-local" class="input" />
            <p v-if="errors.event_ends_at" class="text-sm text-red-600 mt-1">{{ errors.event_ends_at[0] }}</p>
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Location</label>
            <input v-model="form.location" type="text" class="input" />
          </div>
        </template>
      </div>

      <div class="border-t border-gray-200 pt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
        <h2 class="md:col-span-2 text-lg font-semibold text-gray-900">SEO</h2>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Meta title (optional)</label>
          <input v-model="form.meta_title" type="text" class="input" maxlength="255" placeholder="defaults to title" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Meta description (optional)</label>
          <input v-model="form.meta_description" type="text" class="input" maxlength="300" placeholder="defaults to excerpt" />
        </div>
      </div>

      <div class="flex items-center justify-between">
        <label class="flex items-center space-x-2">
          <input v-model="form.is_published" type="checkbox" />
          <span class="text-sm text-gray-700">Published</span>
        </label>
        <div class="flex space-x-2">
          <router-link to="/posts" class="btn btn-secondary">Cancel</router-link>
          <button type="submit" class="btn btn-primary" :disabled="saving">
            {{ saving ? 'Saving...' : 'Save' }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import RichTextEditor from '@/components/RichTextEditor.vue'

const route = useRoute()
const router = useRouter()

const isEdit = computed(() => !!route.params.id)
const saving = ref(false)
const errors = ref({})

const form = reactive({
  type: 'news',
  title: '',
  slug: '',
  excerpt: '',
  body: '',
  cover_image: '',
  event_starts_at: '',
  event_ends_at: '',
  location: '',
  meta_title: '',
  meta_description: '',
  is_published: false,
})

// "2026-09-25T10:00:00.000000Z" -> "2026-09-25T10:00" for datetime-local inputs
const toLocalInput = (value) => {
  if (!value) return ''
  const date = new Date(value)
  const offset = date.getTimezoneOffset() * 60000
  return new Date(date - offset).toISOString().slice(0, 16)
}

const fetchPost = async () => {
  try {
    const { data } = await api.get(`/posts/${route.params.id}`)
    const post = data.data
    Object.keys(form).forEach((key) => {
      form[key] = post[key] ?? form[key]
    })
    form.event_starts_at = toLocalInput(post.event_starts_at)
    form.event_ends_at = toLocalInput(post.event_ends_at)
  } catch (error) {
    console.error('Failed to fetch post:', error)
  }
}

// Mirrors App\Support\PostBody::isEmpty(): no text and no img/iframe means the body
// is effectively empty, even though the editor always emits at least an empty <p>.
const isBodyEmpty = (html) => {
  const text = (html || '').replace(/<[^>]*>/g, '').trim()
  if (text) return false
  return !/<(img|iframe)\b/i.test(html || '')
}

const save = async () => {
  errors.value = {}
  if (isBodyEmpty(form.body)) {
    errors.value = { body: ['The body field is required.'] }
    return
  }

  saving.value = true
  const payload = Object.fromEntries(
    Object.entries(form).map(([key, value]) => [key, value === '' ? null : value])
  )
  if (payload.type !== 'event') {
    payload.event_starts_at = payload.event_ends_at = payload.location = null
  } else {
    // Send local datetimes as ISO strings so the server stores the intended instant
    ;['event_starts_at', 'event_ends_at'].forEach((key) => {
      if (payload[key]) payload[key] = new Date(payload[key]).toISOString()
    })
  }

  try {
    if (isEdit.value) {
      await api.put(`/posts/${route.params.id}`, payload)
    } else {
      await api.post('/posts', payload)
    }
    router.push('/posts')
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors
    } else {
      console.error('Failed to save post:', error)
      alert('Failed to save post')
    }
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  if (isEdit.value) fetchPost()
})
</script>
