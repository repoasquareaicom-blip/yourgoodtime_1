import api from './api'

const galleryVisitorStorageKey = 'ygt_gallery_visitor_key'

function galleryVisitorKey() {
  let visitorKey = window.localStorage.getItem(galleryVisitorStorageKey)
  if (!visitorKey) {
    visitorKey = `gv_${window.crypto?.randomUUID?.() || `${Date.now()}_${Math.random().toString(36).slice(2)}`}`.replace(/[^A-Za-z0-9_-]/g, '')
    window.localStorage.setItem(galleryVisitorStorageKey, visitorKey)
  }

  return visitorKey
}

function visitorHeaders() {
  return {
    'X-Gallery-Visitor': galleryVisitorKey(),
  }
}

function normalizeVideo(item) {
  return {
    id: item.id,
    title: item.title || '',
    description: item.description || '',
    category: item.category || 'General',
    videoType: item.video_type,
    thumbnail: item.thumbnail_url,
    videoSrc: item.video_url,
    displayOrder: item.display_order ?? 0,
    publishedAt: item.published_at || null,
    viewCount: item.view_count ?? 0,
    likeCount: item.like_count ?? 0,
    likedByMe: Boolean(item.liked_by_me),
  }
}

export async function getGalleryVideos(params = {}) {
  const response = await api.get('/gallery/videos', {
    params,
    headers: visitorHeaders(),
  })
  const data = response.data?.data || {}

  return {
    recent: Array.isArray(data.recent) ? data.recent.map(normalizeVideo) : [],
    home: Array.isArray(data.home) ? data.home.map(normalizeVideo) : [],
    shorts: Array.isArray(data.shorts) ? data.shorts.map(normalizeVideo) : [],
    videos: Array.isArray(data.videos) ? data.videos.map(normalizeVideo) : [],
  }
}

export async function recordGalleryVideoView(videoId) {
  const response = await api.post(`/gallery/videos/${videoId}/view`, null, {
    headers: visitorHeaders(),
  })

  return normalizeVideo(response.data?.data || {})
}

export async function toggleGalleryVideoLike(videoId) {
  const response = await api.post(`/gallery/videos/${videoId}/like`, null, {
    headers: visitorHeaders(),
  })

  return normalizeVideo(response.data?.data || {})
}

export function galleryVideoSlug(title) {
  const slug = String(title || '')
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 80)

  return slug || 'video'
}

export function galleryVideoSharePath(video) {
  if (!video?.id) return '/videos'

  return `/videos/${video.id}/${galleryVideoSlug(video.title)}`
}

export function galleryVideoShareUrl(video, origin = window.location.origin) {
  return `${origin}${galleryVideoSharePath(video)}`
}

export function galleryVideoIdFromSearch(search) {
  const params = new URLSearchParams(typeof search === 'string' && search.startsWith('?') ? search.slice(1) : search)
  return (params.get('v') || params.get('video') || '').trim()
}

export function galleryVideoIdFromLocation(pathname = '', search = '') {
  const pathMatch = String(pathname).match(/^\/videos\/(\d+)(?:\/|$)/)
  if (pathMatch) return pathMatch[1]

  return galleryVideoIdFromSearch(search)
}

export function galleryPathWithVideo(search, video) {
  const params = new URLSearchParams(typeof search === 'string' && search.startsWith('?') ? search.slice(1) : search)
  params.delete('video')
  params.delete('v')
  const query = params.toString()

  if (!video?.id) {
    return query ? `/videos?${query}` : '/videos'
  }

  const path = galleryVideoSharePath(video)
  return query ? `${path}?${query}` : path
}

export async function copyTextToClipboard(text) {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
      return true
    }
  } catch {
    // Use the fallback below when Clipboard API is blocked.
  }

  const input = document.createElement('textarea')
  input.value = text
  input.setAttribute('readonly', '')
  input.style.position = 'fixed'
  input.style.opacity = '0'
  document.body.appendChild(input)
  input.focus()
  input.select()
  input.setSelectionRange(0, text.length)
  const copied = document.execCommand('copy')
  document.body.removeChild(input)
  return copied
}
