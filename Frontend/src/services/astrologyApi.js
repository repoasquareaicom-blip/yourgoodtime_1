import api from './api'

export async function submitPastPrediction(payload, options = {}) {
  const timeoutMs = options.timeoutMs ?? 120000

  try {
    const response = await api.post('/predictions/past', payload, {
      signal: options.signal,
      timeout: timeoutMs,
    })

    return response.data
  } catch (submitError) {
    const responseData = submitError.response?.data || {}
    const error = new Error(responseData.message || submitError.message || 'Unable to submit prediction request.')

    error.name = submitError.name
    error.status = submitError.response?.status
    error.code = responseData.code || responseData.error_code || submitError.code
    error.errors = responseData.errors || {}
    error.responseBody = responseData

    throw error
  }
}
