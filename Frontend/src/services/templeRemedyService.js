import api from './api'

export async function fetchTempleRemedyConfig() {
  const response = await api.get('/temple-remedies/config')

  return response.data?.data
}

export async function submitTempleRemedy(payload) {
  const response = await api.post('/temple-remedies', payload)

  return response.data
}

export async function cancelTempleRemedyPayment(remedyId) {
  const response = await api.post(`/temple-remedies/${remedyId}/cancel-payment`)

  return response.data
}

export async function submitTempleRemedyFeedback(remedyId, payload) {
  const response = await api.post(`/temple-remedies/${remedyId}/feedback`, payload)

  return response.data
}

export async function fetchTempleRemedyFile(remedyId, { download = false } = {}) {
  const response = await api.get(`/temple-remedies/${remedyId}/file`, {
    params: download ? { download: 1 } : undefined,
    responseType: 'blob',
  })

  return response.data
}
