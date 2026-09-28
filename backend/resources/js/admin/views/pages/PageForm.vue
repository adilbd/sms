<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">{{ isEdit ? 'Edit Page' : 'Add Page' }}</h1>

    <form class="card space-y-6" @submit.prevent="save">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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
          <label class="block text-sm font-medium text-gray-700 mb-1">Body</label>
          <rich-text-editor v-model="form.body" placeholder="Write the page content…" />
          <p v-if="errors.body" class="text-sm text-red-600 mt-1">{{ errors.body[0] }}</p>
        </div>
      </div>

      <div class="border-t border-gray-200 pt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
        <h2 class="md:col-span-2 text-lg font-semibold text-gray-900">SEO</h2>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Meta title (optional)</label>
          <input v-model="form.meta_title" type="text" class="input" maxlength="255" placeholder="defaults to title" />
          <p v-if="errors.meta_title" class="text-sm text-red-600 mt-1">{{ errors.meta_title[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Meta description (optional)</label>
          <input v-model="form.meta_description" type="text" class="input" maxlength="300" placeholder="defaults to body text" />
          <p v-if="errors.meta_description" class="text-sm text-red-600 mt-1">{{ errors.meta_description[0] }}</p>
        </div>
      </div>

      <div class="flex items-center justify-between">
        <label class="flex items-center space-x-2">
          <input v-model="form.is_published" type="checkbox" />
          <span class="text-sm text-gray-700">Published</span>
        </label>
        <div class="flex space-x-2">
          <router-link to="/pages" class="btn btn-secondary">Cancel</router-link>
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
  title: '',
  slug: '',
  body: '',
  meta_title: '',
  meta_description: '',
  is_published: false,
})

const fetchPage = async () => {
  try {
    const { data } = await api.get(`/pages/${route.params.id}`)
    const page = data.data
    Object.keys(form).forEach((key) => {
      form[key] = page[key] ?? form[key]
    })
  } catch (error) {
    console.error('Failed to fetch page:', error)
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

  try {
    if (isEdit.value) {
      await api.put(`/pages/${route.params.id}`, payload)
    } else {
      await api.post('/pages', payload)
    }
    router.push('/pages')
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors
    } else {
      console.error('Failed to save page:', error)
      alert('Failed to save page')
    }
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  if (isEdit.value) fetchPage()
})
</script>
