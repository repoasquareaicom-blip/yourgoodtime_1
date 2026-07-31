import api from './api'

export async function submitTempleRemedy(payload) {
  const response = await api.post('/temple-remedies', payload)

  return response.data
}

export async function submitTempleRemedyFeedback(remedyId, payload) {
  const response = await api.post(`/temple-remedies/${remedyId}/feedback`, payload)

  return response.data
}
