<template>
  <div class="rich-text-editor">
    <div class="toolbar" role="toolbar" aria-label="Formatting">
      <button
        type="button"
        class="toolbar-btn"
        :class="{ 'is-active': isActive('bold') }"
        title="Bold"
        aria-label="Bold"
        :disabled="!editor"
        @click="editor.chain().focus().toggleBold().run()"
      >
        <strong>B</strong>
      </button>
      <button
        type="button"
        class="toolbar-btn toolbar-btn-italic"
        :class="{ 'is-active': isActive('italic') }"
        title="Italic"
        aria-label="Italic"
        :disabled="!editor"
        @click="editor.chain().focus().toggleItalic().run()"
      >
        I
      </button>
      <button
        type="button"
        class="toolbar-btn toolbar-btn-underline"
        :class="{ 'is-active': isActive('underline') }"
        title="Underline"
        aria-label="Underline"
        :disabled="!editor"
        @click="editor.chain().focus().toggleUnderline().run()"
      >
        U
      </button>
      <button
        type="button"
        class="toolbar-btn toolbar-btn-strike"
        :class="{ 'is-active': isActive('strike') }"
        title="Strikethrough"
        aria-label="Strikethrough"
        :disabled="!editor"
        @click="editor.chain().focus().toggleStrike().run()"
      >
        S
      </button>

      <span class="toolbar-separator" aria-hidden="true"></span>

      <button
        v-for="level in [2, 3, 4]"
        :key="level"
        type="button"
        class="toolbar-btn"
        :class="{ 'is-active': isActive('heading', { level }) }"
        :title="`Heading ${level}`"
        :aria-label="`Heading ${level}`"
        :disabled="!editor"
        @click="editor.chain().focus().toggleHeading({ level }).run()"
      >
        H{{ level }}
      </button>

      <span class="toolbar-separator" aria-hidden="true"></span>

      <button
        type="button"
        class="toolbar-btn"
        :class="{ 'is-active': isActive('bulletList') }"
        title="Bullet list"
        aria-label="Bullet list"
        :disabled="!editor"
        @click="editor.chain().focus().toggleBulletList().run()"
      >
        ••
      </button>
      <button
        type="button"
        class="toolbar-btn"
        :class="{ 'is-active': isActive('orderedList') }"
        title="Numbered list"
        aria-label="Numbered list"
        :disabled="!editor"
        @click="editor.chain().focus().toggleOrderedList().run()"
      >
        1.
      </button>
      <button
        type="button"
        class="toolbar-btn"
        :class="{ 'is-active': isActive('blockquote') }"
        title="Blockquote"
        aria-label="Blockquote"
        :disabled="!editor"
        @click="editor.chain().focus().toggleBlockquote().run()"
      >
        &ldquo;
      </button>

      <span class="toolbar-separator" aria-hidden="true"></span>

      <button
        type="button"
        class="toolbar-btn"
        :class="{ 'is-active': isActive('link') }"
        title="Link"
        aria-label="Link"
        :disabled="!editor"
        @click="setLink"
      >
        🔗
      </button>
      <button
        type="button"
        class="toolbar-btn"
        title="Insert image"
        aria-label="Insert image"
        :disabled="!editor || uploading"
        @click="triggerFilePicker"
      >
        🖼️
      </button>
      <button
        type="button"
        class="toolbar-btn"
        title="Embed YouTube or Vimeo video"
        aria-label="Embed video"
        :disabled="!editor"
        @click="insertVideo"
      >
        ▶️
      </button>

      <span class="toolbar-separator" aria-hidden="true"></span>

      <button
        type="button"
        class="toolbar-btn"
        title="Undo"
        aria-label="Undo"
        :disabled="!editor || !editor.can().undo()"
        @click="editor.chain().focus().undo().run()"
      >
        ↶
      </button>
      <button
        type="button"
        class="toolbar-btn"
        title="Redo"
        aria-label="Redo"
        :disabled="!editor || !editor.can().redo()"
        @click="editor.chain().focus().redo().run()"
      >
        ↷
      </button>

      <span v-if="uploading" class="text-sm text-gray-500 ml-2">Uploading image...</span>
    </div>

    <input
      ref="fileInput"
      type="file"
      accept="image/jpeg,image/png,image/webp,image/gif"
      class="hidden"
      @change="onFileSelected"
    />

    <editor-content :editor="editor" class="editor-content" />

    <p v-if="uploadError" class="text-sm text-red-600 mt-1">{{ uploadError }}</p>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { Editor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Image from '@tiptap/extension-image'
import Link from '@tiptap/extension-link'
import Placeholder from '@tiptap/extension-placeholder'
import VideoEmbed from '@/extensions/VideoEmbed'
import api from '@/services/api'

const props = defineProps({
  modelValue: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
const MAX_SIZE = 5 * 1024 * 1024

const editor = shallowRef(null)
const fileInput = ref(null)
const uploading = ref(false)
const uploadError = ref('')
// Bumped on every editor transaction so `isActive()` calls made during render stay
// reactive: the Tiptap Editor instance itself isn't a reactive object.
const renderTick = ref(0)

const isActive = (name, attrs) => {
  void renderTick.value
  return editor.value ? editor.value.isActive(name, attrs) : false
}

const triggerFilePicker = () => fileInput.value?.click()

const onFileSelected = (event) => {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (file) uploadImage(file)
}

const validateImage = (file) => {
  if (!ALLOWED_TYPES.includes(file.type)) {
    return 'Only JPG, PNG, WEBP or GIF images are allowed.'
  }
  if (file.size > MAX_SIZE) {
    return 'Images must be 5MB or smaller.'
  }
  return null
}

const uploadImage = async (file) => {
  uploadError.value = ''
  const validationError = validateImage(file)
  if (validationError) {
    uploadError.value = validationError
    return
  }

  uploading.value = true
  try {
    const formData = new FormData()
    formData.append('file', file)
    const { data } = await api.post('/posts/media', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })

    const alt = window.prompt('Alt text for this image (optional):', '') || ''
    editor.value?.chain().focus().setImage({ src: data.data.url, alt }).run()
  } catch (error) {
    uploadError.value =
      error.response?.data?.errors?.file?.[0] || error.response?.data?.message || 'Image upload failed.'
  } finally {
    uploading.value = false
  }
}

const insertVideo = () => {
  const url = window.prompt('Paste a YouTube or Vimeo URL:')
  if (!url) return

  const inserted = editor.value?.chain().focus().setVideoEmbed(url).run()
  uploadError.value = inserted ? '' : 'Only YouTube or Vimeo URLs are supported.'
}

const setLink = () => {
  const previousUrl = editor.value?.getAttributes('link').href
  const url = window.prompt('URL', previousUrl || '')
  if (url === null) return

  if (url === '') {
    editor.value?.chain().focus().extendMarkRange('link').unsetLink().run()
    return
  }

  editor.value?.chain().focus().extendMarkRange('link').setLink({ href: url }).run()
}

onMounted(() => {
  editor.value = new Editor({
    content: props.modelValue || '',
    extensions: [
      StarterKit.configure({
        heading: { levels: [2, 3, 4] },
        link: false,
      }),
      Link.configure({
        openOnClick: false,
        autolink: true,
        HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
      }),
      Image.configure({ HTMLAttributes: { loading: 'lazy' } }),
      Placeholder.configure({ placeholder: 'Write the post body…' }),
      VideoEmbed,
    ],
    editorProps: {
      attributes: {
        class: 'prose-editor',
      },
      handleDrop: (view, event) => {
        const file = event.dataTransfer?.files?.[0]
        if (file && file.type.startsWith('image/')) {
          event.preventDefault()
          uploadImage(file)
          return true
        }
        return false
      },
      handlePaste: (view, event) => {
        const item = Array.from(event.clipboardData?.items || []).find((entry) =>
          entry.type.startsWith('image/')
        )
        if (item) {
          const file = item.getAsFile()
          if (file) {
            event.preventDefault()
            uploadImage(file)
            return true
          }
        }
        return false
      },
    },
    onUpdate: ({ editor: instance }) => {
      emit('update:modelValue', instance.isEmpty ? '' : instance.getHTML())
    },
    onTransaction: () => {
      renderTick.value += 1
    },
  })
})

onBeforeUnmount(() => {
  editor.value?.destroy()
})

// Sync external modelValue changes (e.g. after fetching a post) without fighting the
// user's cursor: only replace the content when it actually differs from what the
// editor already holds.
watch(
  () => props.modelValue,
  (value) => {
    if (!editor.value) return
    const current = editor.value.isEmpty ? '' : editor.value.getHTML()
    if ((value || '') !== current) {
      editor.value.commands.setContent(value || '', { emitUpdate: false })
    }
  }
)

defineExpose({ editor })
</script>

<style scoped>
.rich-text-editor {
  @apply w-full rounded-lg border border-gray-300 focus-within:ring-2 focus-within:ring-primary-500 focus-within:border-transparent;
}

.toolbar {
  @apply flex flex-wrap items-center gap-1 border-b border-gray-200 bg-gray-50 p-2 rounded-t-lg;
}

.toolbar-btn {
  @apply inline-flex h-8 min-w-8 items-center justify-center rounded px-2 text-sm font-medium text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:opacity-40 disabled:cursor-not-allowed;
}

.toolbar-btn.is-active {
  @apply bg-primary-600 text-white hover:bg-primary-700;
}

.toolbar-btn-italic {
  @apply italic;
}

.toolbar-btn-underline {
  @apply underline;
}

.toolbar-btn-strike {
  @apply line-through;
}

.toolbar-separator {
  @apply mx-1 h-6 w-px bg-gray-300;
}

.editor-content {
  @apply px-3 py-2;
}
</style>
