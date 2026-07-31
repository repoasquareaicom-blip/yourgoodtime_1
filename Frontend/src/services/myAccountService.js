import api from './api'
import { getUser } from '../utils/auth'

const CACHE_VERSION = 'v3'
let cachedPredictionSummary = null

function cacheKey() {
  const user = getUser()
  const userIdentity = user?.id || user?.email || 'anonymous'

  return `my-account-summary:${userIdentity}:${CACHE_VERSION}`
}

export async function fetchMyAccountPredictions({ signal, force = false } = {}) {
  const key = cacheKey()

  if (!force && cachedPredictionSummary?.key === key) {
    return cachedPredictionSummary.data
  }

  const response = await api.get('/my-account/predictions', {
    timeout: 60000,
    signal,
  })

  cachedPredictionSummary = {
    key,
    data: response.data?.data,
  }

  return cachedPredictionSummary.data
}

export function clearMyAccountPredictionCache() {
  cachedPredictionSummary = null
}
