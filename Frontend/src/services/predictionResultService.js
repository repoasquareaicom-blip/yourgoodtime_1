import demoIdentityVideo from '../assets/videos/Content/intro-1.mp4'
import demoPredictionVideoOne from '../assets/videos/Content/00001.mp4'
import demoPredictionVideoTwo from '../assets/videos/Content/00002.mp4'
import { getLanguageByCode, getStoredLanguage } from '../data/languageOptions'
import api from './api'

const localVideoFallbacks = [demoPredictionVideoOne, demoPredictionVideoTwo]

export async function fetchPredictionResult({ predictionRequestId, requestUuid, type = 'past', entitlementId, signal } = {}) {
  const response = await api.post('/predictions/result', {
    id: predictionRequestId,
    type,
    request_uuid: requestUuid,
    ...(entitlementId ? { entitlement_id: entitlementId } : {}),
  }, { signal, timeout: 120000 })

  return response.data?.data
}

export async function fetchPastPredictionResult(options = {}) {
  return fetchPredictionResult({ ...options, type: 'past' })
}

export async function fetchStoredPredictionResult({ predictionRequestId, requestUuid, type = 'past', entitlementId, signal } = {}) {
  const response = await api.get(`/predictions/${predictionRequestId}/result`, {
    params: {
      type,
      request_uuid: requestUuid,
      ...(entitlementId ? { entitlement_id: entitlementId } : {}),
    },
    signal,
    timeout: 30000,
  })

  return response.data?.data
}

export async function fetchPredictionSessionStatus({ predictionRequestId, requestUuid, signal } = {}) {
  const response = await api.get('/predictions/session-status', {
    params: {
      prediction_request_id: predictionRequestId,
      request_uuid: requestUuid,
      prediction_type: 'past',
    },
    signal,
    timeout: 30000,
  })

  return response.data?.data
}

export async function fetchProtectedVideoPlaybackUrl({
  predictionRequestId,
  requestUuid,
  predictionType = 'past',
  predictionResultId,
  entitlementId,
  videoId,
  videoRole,
  language,
  signal,
} = {}) {
  const selectedLanguage = getLanguageByCode(language)?.code
    || getStoredLanguage()?.code
    || 'ta'

  const response = await api.post('/video/playback-url', {
    prediction_request_id: predictionRequestId,
    request_uuid: requestUuid,
    prediction_type: predictionType,
    ...(predictionResultId ? { prediction_result_id: predictionResultId } : {}),
    ...(entitlementId ? { entitlement_id: entitlementId } : {}),
    video_id: videoId,
    ...(videoRole ? { video_role: videoRole } : {}),
    language: selectedLanguage,
  }, { signal, timeout: 30000 })

  return {
    ...response.data?.data,
    status: response.status,
  }
}

export function resolvePredictionVideoUrl(videoId, type = 'PREDICTION') {
  if (!videoId) return null

  const configuredBaseUrl = import.meta.env.VITE_PREDICTION_VIDEO_BASE_URL?.trim()

  if (configuredBaseUrl) {
    return `${configuredBaseUrl.replace(/\/$/, '')}/${encodeURIComponent(videoId)}.mp4`
  }

  if (type === 'IDENTITY') {
    return demoIdentityVideo
  }

  const numericId = Number.parseInt(String(videoId).replace(/\D/g, ''), 10)
  const fallbackIndex = Number.isFinite(numericId) ? numericId % localVideoFallbacks.length : 0

  return localVideoFallbacks[fallbackIndex]
}

function normalizePredictionVideoIds(group) {
  const rawVideoIds = group?.video_ids

  let videoIds = []

  if (Array.isArray(rawVideoIds)) {
    videoIds = rawVideoIds
  } else if (rawVideoIds && typeof rawVideoIds === 'object') {
    videoIds = Object.values(rawVideoIds)
  } else if (typeof rawVideoIds === 'string' || typeof rawVideoIds === 'number') {
    videoIds = String(rawVideoIds).split(/[\s,]+/)
  }

  if (!videoIds.length && group?.video_id) {
    videoIds = [group.video_id]
  }

  return Array.from(new Set(
    videoIds
      .map((videoId) => String(videoId).trim())
      .filter(Boolean)
  ))
}

function comparableVideoId(videoId) {
  const value = String(videoId || '').trim()
  const numeric = value.replace(/^0+/, '')

  return numeric || value
}

export function buildPredictionPlaylist(result, predictionType = 'past') {
  const identityVideoId = result?.identity_video?.video_id || result?.identity_video_id
  const identityVideoKey = identityVideoId ? comparableVideoId(identityVideoId) : null
  const groups = []

  if (identityVideoId) {
    groups.push({
      type: 'IDENTITY',
      title: 'My Astrological Identity',
      subtitle: 'Let us begin by understanding your astrological identity.',
      ageLabel: null,
      systemYearLabel: null,
      majorDasha: null,
      dashaLabel: null,
      videoIds: [String(identityVideoId)],
      videos: [{
        videoId: String(identityVideoId),
        url: resolvePredictionVideoUrl(identityVideoId, 'IDENTITY'),
      }],
    })
  }

  const sortedPredictionGroups = [...(result?.prediction_videos || [])].sort(
    (first, second) => Number(first?.system_year_from ?? first?.system_year ?? 0) - Number(second?.system_year_from ?? second?.system_year ?? 0)
  )

  sortedPredictionGroups.forEach((group) => {
    const videoIds = normalizePredictionVideoIds(group)
      .filter((videoId) => !identityVideoKey || comparableVideoId(videoId) !== identityVideoKey)

    if (!videoIds.length) return

    groups.push({
      type: 'PREDICTION',
      title: predictionType === 'future' ? 'Your Future Journey' : 'Your Past Journey',
      subtitle: group.system_year_label ? `Calendar period ${group.system_year_label}` : `Your personal ${predictionType} prediction`,
      ageLabel: group.age_label || null,
      systemYearLabel: group.system_year_label || null,
      majorDasha: group.major_dasha || null,
      dashaLabel: group.dasha_year_label || null,
      videoIds,
      videos: videoIds.map((videoId) => ({
        videoId,
        url: resolvePredictionVideoUrl(videoId, 'PREDICTION'),
      })),
    })
  })

  return groups
}

export function playlistVideoCount(groups) {
  return groups.reduce((total, group) => total + group.videos.length, 0)
}
