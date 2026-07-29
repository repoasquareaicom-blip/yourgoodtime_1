const STORAGE_KEY = 'ygt-past-prediction'
const LEGACY_SESSION_KEY = 'yourGoodTimePastPredictionRequestId'

const emptySession = {
  predictionRequestId: null,
  requestUuid: null,
  predictionType: 'past',
  otpVerified: false,
  resultReady: false,
}

function normalizeSession(rawSession = {}) {
  if (!rawSession || typeof rawSession !== 'object') return null

  const predictionRequestId = rawSession.predictionRequestId ?? rawSession.prediction_request_id
  const requestUuid = rawSession.requestUuid ?? rawSession.request_uuid

  if (!predictionRequestId || !requestUuid) return null

  return {
    ...emptySession,
    ...rawSession,
    predictionRequestId,
    requestUuid,
    predictionType: rawSession.predictionType ?? rawSession.prediction_type ?? 'past',
    otpVerified: Boolean(rawSession.otpVerified ?? rawSession.otp_verified ?? ['verified', 'ready'].includes(rawSession.status)),
    resultReady: Boolean(rawSession.resultReady ?? rawSession.result_ready ?? rawSession.status === 'ready'),
  }
}

export function loadPredictionSession() {
  try {
    return normalizeSession(JSON.parse(window.localStorage.getItem(STORAGE_KEY) || 'null'))
  } catch {
    return null
  }
}

export function savePredictionSession(session) {
  const normalized = normalizeSession(session)

  if (!normalized) return

  window.localStorage.setItem(STORAGE_KEY, JSON.stringify({
    ...normalized,
    requestedAt: session.requestedAt || new Date().toISOString(),
  }))

  window.sessionStorage.setItem(LEGACY_SESSION_KEY, normalized.requestUuid)
}

export function clearPredictionSession() {
  window.localStorage.removeItem(STORAGE_KEY)
  window.sessionStorage.removeItem(LEGACY_SESSION_KEY)
}

export const predictionSessionKeys = {
  storage: STORAGE_KEY,
  legacySession: LEGACY_SESSION_KEY,
}
