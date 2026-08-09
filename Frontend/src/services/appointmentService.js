import api from './api'

export async function fetchAppointmentConfig() {
  const response = await api.get('/astrologer-appointments/config')

  return response.data?.data
}

export async function fetchAppointments() {
  const response = await api.get('/astrologer-appointments')

  return response.data?.data || []
}

export async function submitAppointmentRequest(payload) {
  const response = await api.post('/astrologer-appointments', payload)

  return response.data
}

export async function uploadAppointmentManualPayment(appointmentId, file) {
  const formData = new FormData()
  formData.append('payment_screenshot', file)

  const response = await api.post(`/astrologer-appointments/${appointmentId}/manual-payment`, formData)

  return response.data
}

export async function cancelAppointment(appointmentId) {
  const response = await api.post(`/astrologer-appointments/${appointmentId}/cancel`)

  return response.data
}
