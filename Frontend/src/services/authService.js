import api from './api'

export async function sendOtp(payload) {
  const response = await api.post('/auth/send-otp', payload)

  return response.data
}

export async function verifyOtp(payload) {
  const response = await api.post('/auth/verify-otp', payload)

  return response.data
}

export async function getCurrentUser() {
  const response = await api.get('/auth/me')

  return response.data
}

export async function logout() {
  const response = await api.post('/auth/logout')

  return response.data
}
