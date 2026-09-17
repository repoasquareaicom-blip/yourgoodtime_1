import { finalizeAdminGalleryVideoUpload, uploadAdminGalleryVideoChunk } from './adminService'

export const GALLERY_VIDEO_CHUNK_SIZE = 5 * 1024 * 1024
export const GALLERY_VIDEO_MAX_BYTES = 500 * 1024 * 1024
export const GALLERY_VIDEO_ALLOWED_EXTENSIONS = ['mp4', 'm4v', 'webm', 'mov']

const MAX_CHUNK_ATTEMPTS = 3

export function createGalleryUploadId() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }

  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
    const random = Math.floor(Math.random() * 16)
    const value = character === 'x' ? random : (random & 0x3) | 0x8
    return value.toString(16)
  })
}

export function assertGalleryVideoFile(file) {
  if (!file) {
    throw new Error('Choose a video file before saving.')
  }

  const extension = String(file.name || '').split('.').pop()?.toLowerCase()
  if (!GALLERY_VIDEO_ALLOWED_EXTENSIONS.includes(extension)) {
    throw new Error('Video must be MP4, M4V, WEBM, or MOV.')
  }

  if (file.size < 1) {
    throw new Error('Choose a valid video file.')
  }

  if (file.size > GALLERY_VIDEO_MAX_BYTES) {
    throw new Error('Video must be 500 MB or smaller.')
  }
}

export async function uploadGalleryVideoInChunks({ file, onProgress, signal }) {
  assertGalleryVideoFile(file)

  const uploadId = createGalleryUploadId()
  const totalChunks = Math.max(1, Math.ceil(file.size / GALLERY_VIDEO_CHUNK_SIZE))
  const report = (percent, phase) => {
    onProgress?.({
      percent: Math.max(0, Math.min(100, Math.round(percent))),
      phase,
    })
  }

  report(0, 'uploading')

  for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex += 1) {
    if (signal?.aborted) {
      throw createAbortError()
    }

    const start = chunkIndex * GALLERY_VIDEO_CHUNK_SIZE
    const end = Math.min(file.size, start + GALLERY_VIDEO_CHUNK_SIZE)
    const blob = file.slice(start, end)
    const chunkFile = new File([blob], `${chunkIndex}.part`, {
      type: file.type || 'application/octet-stream',
    })

    await uploadChunkWithRetry({
      uploadId,
      chunkIndex,
      totalChunks,
      file,
      chunkFile,
      signal,
      onProgress: (loaded) => {
        const completedBytes = start + Math.min(chunkFile.size, loaded)
        report((completedBytes / file.size) * 100, 'uploading')
      },
    })
  }

  report(100, 'processing')

  const response = await finalizeAdminGalleryVideoUpload({
    upload_id: uploadId,
    total_chunks: totalChunks,
    original_size: file.size,
    original_filename: file.name,
  }, { signal })

  return {
    uploadId,
    videoPath: response?.data?.video_path,
    size: response?.data?.size ?? file.size,
  }
}

async function uploadChunkWithRetry({
  uploadId,
  chunkIndex,
  totalChunks,
  file,
  chunkFile,
  signal,
  onProgress,
}) {
  let lastError = null

  for (let attempt = 1; attempt <= MAX_CHUNK_ATTEMPTS; attempt += 1) {
    if (signal?.aborted) {
      throw createAbortError()
    }

    try {
      const payload = new FormData()
      payload.append('upload_id', uploadId)
      payload.append('chunk_index', String(chunkIndex))
      payload.append('total_chunks', String(totalChunks))
      payload.append('original_filename', file.name)
      payload.append('original_size', String(file.size))
      payload.append('chunk', chunkFile)

      await uploadAdminGalleryVideoChunk(payload, {
        signal,
        onUploadProgress: (progressEvent) => {
          onProgress(progressEvent.loaded || 0)
        },
      })

      onProgress(chunkFile.size)
      return
    } catch (error) {
      if (isAbortError(error) || signal?.aborted) {
        throw createAbortError()
      }

      lastError = error
      if (!shouldRetryChunk(error) || attempt === MAX_CHUNK_ATTEMPTS) {
        throw error
      }

      await wait(400 * attempt)
    }
  }

  throw lastError
}

function shouldRetryChunk(error) {
  if (!error.response) return true

  const status = error.response.status
  return status === 408 || status === 425 || status === 429 || status >= 500
}

function wait(ms) {
  return new Promise((resolve) => {
    window.setTimeout(resolve, ms)
  })
}

function createAbortError() {
  const error = new Error('Video upload cancelled.')
  error.code = 'ERR_CANCELED'
  error.name = 'CanceledError'
  return error
}

export function isAbortError(error) {
  return error?.code === 'ERR_CANCELED'
    || error?.name === 'CanceledError'
    || error?.name === 'AbortError'
}
