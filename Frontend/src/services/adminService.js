import adminApi from './adminApi'

export async function fetchAdminOverview() {
  const response = await adminApi.get('/admin/overview')

  return response.data?.data
}

export async function fetchAdminUsers() {
  const response = await adminApi.get('/admin/users')

  return response.data?.data || []
}

export async function fetchAdminPredictionRequests(type = 'past') {
  const response = await adminApi.get('/admin/prediction-requests', {
    params: { type },
  })

  return response.data?.data || []
}

export async function fetchAdminTempleRemedies() {
  const response = await adminApi.get('/admin/temple-remedies')

  return response.data?.data || []
}

export async function updateAdminTempleRemedy(remedyId, payload) {
  const response = await adminApi.patch(`/admin/temple-remedies/${remedyId}`, payload)

  return response.data
}

export async function loginAdmin(payload) {
  const response = await adminApi.post('/admin/auth/login', payload)

  return response.data
}

export async function getCurrentAdmin() {
  const response = await adminApi.get('/admin/auth/me')

  return response.data
}

export async function logoutAdmin() {
  const response = await adminApi.post('/admin/auth/logout')

  return response.data
}
