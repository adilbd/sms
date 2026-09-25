import { Node, mergeAttributes } from '@tiptap/core'

// Matches youtube.com/watch?v=ID, youtu.be/ID, youtube.com/shorts/ID and youtube.com/embed/ID.
const YOUTUBE_PATTERN = /(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{6,})/i

// Matches vimeo.com/ID and vimeo.com/video/ID.
const VIMEO_PATTERN = /vimeo\.com\/(?:video\/)?(\d+)/i

// Only these two embed hosts are allowed. Keep in sync with the URI.SafeIframeRegexp
// in the post_body purifier profile (backend/config/purifier.php), so saved posts
// round-trip on reopen.
const ALLOWED_SRC = /^https:\/\/(www\.youtube-nocookie\.com\/embed\/|player\.vimeo\.com\/video\/)/

/**
 * Turn a pasted YouTube or Vimeo URL into a privacy-friendly embed src, or return null
 * if the URL isn't from one of those two hosts.
 */
export function parseVideoUrl(url) {
  if (!url) return null
  const trimmed = url.trim()

  const youtube = trimmed.match(YOUTUBE_PATTERN)
  if (youtube) {
    return `https://www.youtube-nocookie.com/embed/${youtube[1]}`
  }

  const vimeo = trimmed.match(VIMEO_PATTERN)
  if (vimeo) {
    return `https://player.vimeo.com/video/${vimeo[1]}`
  }

  return null
}

/**
 * A block, atomic node rendering a responsive video iframe. Only youtube-nocookie and
 * Vimeo embeds are accepted, matching the post_body purifier profile so saved posts
 * survive the round trip through the backend.
 */
const VideoEmbed = Node.create({
  name: 'videoEmbed',
  group: 'block',
  atom: true,
  draggable: true,

  addAttributes() {
    return {
      src: { default: null },
      width: { default: 640 },
      height: { default: 360 },
    }
  },

  parseHTML() {
    return [
      {
        tag: 'iframe[src]',
        getAttrs: (element) => {
          const src = element.getAttribute('src')
          if (!src || !ALLOWED_SRC.test(src)) return false

          return {
            src,
            width: element.getAttribute('width') || 640,
            height: element.getAttribute('height') || 360,
          }
        },
      },
    ]
  },

  renderHTML({ HTMLAttributes }) {
    return [
      'iframe',
      mergeAttributes(HTMLAttributes, {
        frameborder: '0',
        allowfullscreen: 'true',
      }),
    ]
  },

  addCommands() {
    return {
      setVideoEmbed:
        (url) =>
        ({ commands }) => {
          const src = parseVideoUrl(url)
          if (!src) return false

          return commands.insertContent({
            type: this.name,
            attrs: { src, width: 640, height: 360 },
          })
        },
    }
  },
})

export default VideoEmbed
