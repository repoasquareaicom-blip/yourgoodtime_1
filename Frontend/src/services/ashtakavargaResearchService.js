import api from './api'

export async function fetchAshtakavargaResearch(payload, options = {}) {
  const timeoutMs = options.timeoutMs ?? 60000

  try {
    const response = await api.post('/research/ashtakavarga', payload, {
      signal: options.signal,
      timeout: timeoutMs,
    })

    return response.data
  } catch (requestError) {
    const responseData = requestError.response?.data || {}
    const error = new Error(responseData.message || requestError.message || 'Unable to load Ashtakavarga data.')

    error.name = requestError.name
    error.status = requestError.response?.status
    error.errors = responseData.errors || {}
    error.responseBody = responseData

    throw error
  }
}
