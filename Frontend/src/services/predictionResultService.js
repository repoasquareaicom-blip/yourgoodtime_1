import demoIdentityVideo from '../assets/videos/Content/intro-1.mp4'
import demoPredictionVideoOne from '../assets/videos/Content/00001.mp4'
import demoPredictionVideoTwo from '../assets/videos/Content/00002.mp4'
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

export function buildPredictionPlaylist(result, predictionType = 'past') {
  const identityVideoId = result?.identity_video?.video_id
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
    const videoIds = Array.isArray(group.video_ids)
      ? group.video_ids.map((videoId) => String(videoId)).filter(Boolean)
      : []

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
