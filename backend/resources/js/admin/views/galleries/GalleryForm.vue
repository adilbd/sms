<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">{{ isEdit ? 'Edit Gallery' : 'Add Gallery' }}</h1>

    <div v-if="itemErrors.length" class="rounded-lg bg-red-50 px-4 py-3 text-red-800">
      <p class="font-medium">There were problems with the items:</p>
      <ul class="mt-1 list-disc pl-5 text-sm">
        <li v-for="(message, index) in itemErrors" :key="index">{{ message }}</li>
      </ul>
    </div>

    <form class="card space-y-6" @submit.prevent="save">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
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
          <label class="block text-sm font-medium text-gray-700 mb-1">Description (optional)</label>
          <textarea v-model="form.description" rows="3" class="input"></textarea>
          <p v-if="errors.description" class="text-sm text-red-600 mt-1">{{ errors.description[0] }}</p>
        </div>
      </div>

      <div class="border-t border-gray-200 pt-6">
        <h2 class="mb-3 text-lg font-semibold text-gray-900">Cover</h2>
        <div class="flex items-center gap-4">
          <img v-if="coverUrl" :src="coverUrl" alt="" class="h-20 w-28 rounded border border-gray-200 object-cover" />
          <span v-else class="flex h-20 w-28 items-center justify-center rounded border border-dashed border-gray-300 text-3xl text-gray-300">🖼️</span>
          <div class="flex flex-col gap-2">
            <button type="button" class="btn btn-secondary" @click="coverPickerOpen = true">Choose from library</button>
            <button v-if="form.cover_media_id" type="button" class="text-sm text-red-600 hover:underline" @click="clearCover">
              Use automatic cover
            </button>
          </div>
        </div>
        <p class="mt-2 text-xs text-gray-500">
          If no cover is chosen, the first photo (or first video's thumbnail) is used automatically.
        </p>
        <p v-if="errors.cover_media_id" class="text-sm text-red-600 mt-1">{{ errors.cover_media_id[0] }}</p>
      </div>

      <div class="border-t border-gray-200 pt-6">
        <div class="mb-3 flex items-center justify-between">
          <h2 class="text-lg font-semibold text-gray-900">Items</h2>
          <div class="flex gap-2">
            <button type="button" class="btn btn-secondary" @click="pickerOpen = true">➕ Add image</button>
            <button type="button" class="btn btn-secondary" @click="addVideo">▶️ Add video</button>
          </div>
        </div>

        <p v-if="items.length === 0" class="text-sm text-gray-500">No items yet. Add a photo or a YouTube video.</p>

        <ul v-else class="space-y-3">
          <li
            v-for="(item, index) in items"
            :key="item.key"
            class="flex items-start gap-3 rounded-lg border border-gray-200 p-3"
          >
            <img
              v-if="item.thumbnail_url"
              :src="item.thumbnail_url"
              alt=""
              class="h-16 w-24 flex-shrink-0 rounded object-cover"
            />
            <span v-else class="flex h-16 w-24 flex-shrink-0 items-center justify-center rounded bg-gray-100 text-2xl">
              {{ item.type === 'video' ? '▶️' : '🖼️' }}
            </span>
            <div class="flex-1">
              <p class="text-xs font-medium uppercase text-gray-400">{{ item.type }}</p>
              <input v-model="item.caption" type="text" placeholder="Caption (optional)" class="input mt-1 text-sm" />
            </div>
            <div class="flex flex-col gap-1">
              <button type="button" class="text-gray-500 hover:text-gray-800 disabled:opacity-30" :disabled="index === 0" @click="moveItem(index, -1)" title="Move up">↑</button>
              <button type="button" class="text-gray-500 hover:text-gray-800 disabled:opacity-30" :disabled="index === items.length - 1" @click="moveItem(index, 1)" title="Move down">↓</button>
              <button type="button" class="text-red-600 hover:text-red-800" @click="removeItem(index)" title="Remove">🗑️</button>
            </div>
          </li>
        </ul>
      </div>

      <div class="flex items-center justify-between">
        <label class="flex items-center space-x-2">
          <input v-model="form.is_published" type="checkbox" />
          <span class="text-sm text-gray-700">Published</span>
        </label>
        <div class="flex space-x-2">
          <router-link to="/galleries" class="btn btn-secondary">Cancel</router-link>
          <button type="submit" class="btn btn-primary" :disabled="saving">
            {{ saving ? 'Saving...' : 'Save' }}
          </button>
        </div>
      </div>
    </form>

    <media-picker-modal :open="pickerOpen" @close="pickerOpen = false" @select="onItemsPicked" />
    <media-picker-modal :open="coverPickerOpen" @close="coverPickerOpen = false" @select="onCoverPicked" />
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import MediaPickerModal from '@/components/MediaPickerModal.vue'

const route = useRoute()
const router = useRouter()

const isEdit = computed(() => !!route.params.id)
const saving = ref(false)
const errors = ref({})
const pickerOpen = ref(false)
const coverPickerOpen = ref(false)
const coverUrl = ref(null)

const form = reactive({
  title: '',
  slug: '',
  description: '',
  is_published: false,
  cover_media_id: null,
})

// Local id for Vue's :key; unrelated to the server-side gallery item id.
let nextKey = 0
const items = ref([])

// Nested "items.N.field" validation errors are surfaced as a flat list rather than
// re-deriving which of the (reorderable) rows they belong to.
const itemErrors = computed(() =>
  Object.entries(errors.value)
    .filter(([key]) => key.startsWith('items.'))
    .flatMap(([, messages]) => messages)
)

const onItemsPicked = (media) => {
  media.forEach((item) => {
    items.value.push({
      key: nextKey++,
      id: null,
      type: 'image',
      media_id: item.id,
      thumbnail_url: item.url,
      youtube_url: null,
      caption: '',
    })
  })
}

const onCoverPicked = (media) => {
  if (!media.length) return
  form.cover_media_id = media[0].id
  coverUrl.value = media[0].url
}

const clearCover = () => {
  form.cover_media_id = null
  coverUrl.value = null
}

const addVideo = () => {
  const url = window.prompt('Paste a YouTube video URL:')
  if (!url) return
  const caption = window.prompt('Caption (optional):', '') || ''

  items.value.push({
    key: nextKey++,
    id: null,
    type: 'video',
    media_id: null,
    youtube_url: url,
    thumbnail_url: null,
    caption,
  })
}

const moveItem = (index, delta) => {
  const target = index + delta
  if (target < 0 || target >= items.value.length) return

  const copy = [...items.value]
  ;[copy[index], copy[target]] = [copy[target], copy[index]]
  items.value = copy
}

const removeItem = (index) => {
  items.value.splice(index, 1)
}

const fetchGallery = async () => {
  try {
    const { data } = await api.get(`/galleries/${route.params.id}`)
    const gallery = data.data
    form.title = gallery.title
    form.slug = gallery.slug
    form.description = gallery.description || ''
    form.is_published = gallery.is_published
    form.cover_media_id = gallery.cover_media_id
    coverUrl.value = gallery.cover_url

    items.value = (gallery.items || []).map((item) => ({
      key: nextKey++,
      id: item.id,
      type: item.type,
      media_id: item.media_id,
      thumbnail_url: item.thumbnail_url,
      youtube_url: item.youtube_url,
      caption: item.caption || '',
    }))
  } catch (error) {
    console.error('Failed to fetch gallery:', error)
  }
}

const save = async () => {
  errors.value = {}
  saving.value = true

  const payload = {
    title: form.title,
    slug: form.slug || null,
    description: form.description || null,
    is_published: form.is_published,
    cover_media_id: form.cover_media_id,
    items: items.value.map((item) => ({
      id: item.id,
      type: item.type,
      media_id: item.type === 'image' ? item.media_id : null,
      youtube_url: item.type === 'video' ? item.youtube_url : null,
      caption: item.caption || null,
    })),
  }

  try {
    if (isEdit.value) {
      await api.put(`/galleries/${route.params.id}`, payload)
    } else {
      await api.post('/galleries', payload)
    }
    router.push('/galleries')
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors
    } else {
      console.error('Failed to save gallery:', error)
      alert(error.response?.data?.message || 'Failed to save gallery')
    }
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  if (isEdit.value) fetchGallery()
})
</script>
