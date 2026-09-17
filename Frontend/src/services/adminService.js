import adminApi from './adminApi'

export async function fetchAdminOverview() {
  const response = await adminApi.get('/admin/overview')

  return response.data?.data
}

export async function fetchAdminUsers() {
  const response = await adminApi.get('/admin/users')

  return response.data?.data || []
}

export async function fetchAdminGalleryVideos() {
  const response = await adminApi.get('/admin/gallery-videos')

  return response.data?.data || []
}

export async function fetchAdminGalleryVideo(videoId) {
  const response = await adminApi.get(`/admin/gallery-videos/${videoId}`)

  return response.data?.data
}

export async function uploadAdminGalleryVideoChunk(payload, options = {}) {
  const response = await adminApi.post('/admin/gallery-videos/upload-chunk', payload, {
    timeout: 30000,
    ...options,
  })

  return response.data
}

export async function finalizeAdminGalleryVideoUpload(payload, options = {}) {
  const response = await adminApi.post('/admin/gallery-videos/finalize-upload', payload, {
    timeout: 120000,
    ...options,
  })

  return response.data
}

export async function createAdminGalleryVideo(payload, options = {}) {
  const response = await adminApi.post('/admin/gallery-videos', payload, options)

  return response.data
}

export async function updateAdminGalleryVideo(videoId, payload, options = {}) {
  const response = await adminApi.post(`/admin/gallery-videos/${videoId}`, payload, options)

  return response.data
}

export async function updateAdminGalleryVideoVisibility(videoId, isActive) {
  const response = await adminApi.patch(`/admin/gallery-videos/${videoId}/visibility`, {
    is_active: isActive,
  })

  return response.data
}

export async function deleteAdminGalleryVideo(videoId) {
  const response = await adminApi.delete(`/admin/gallery-videos/${videoId}`)

  return response.data
}

export async function fetchAdminPredictionRequests(type = 'past') {
  const response = await adminApi.get('/admin/prediction-requests', {
    params: { type },
  })

  return response.data?.data || []
}

export async function fetchAdminPredictionRequestDetails(requestId, params = {}) {
  const response = await adminApi.get(`/admin/prediction-requests/${requestId}/details`, {
    params,
  })

  return response.data?.data
}

export async function regenerateAdminPastPrediction(requestId) {
  const response = await adminApi.post(`/admin/prediction-requests/${requestId}/regenerate-past`)

  return response.data
}

export async function fetchAdminPredictionRegenerationSettings() {
  const response = await adminApi.get('/admin/prediction-regeneration-settings')

  return response.data?.data || []
}

export async function markAdminPredictionRegeneration(predictionType) {
  const response = await adminApi.post(`/admin/prediction-regeneration-settings/${predictionType}/mark`)

  return response.data
}
export async function validateAdminPredictionExcel(validator = 'rule001_nakshatra_lagnam') {
  const response = await adminApi.post('/admin/prediction-excel-validator/validate', {
    validator,
  })

  return response.data
}
export async function fetchAdminFeedback() {
  const response = await adminApi.get('/admin/feedback')

  return {
    data: response.data?.data || [],
    counts: response.data?.counts || { all: 0, past: 0, future: 0, temple_remedy: 0 },
  }
}

export async function fetchAdminTempleRemedies(group = 'payment_pending') {
  const response = await adminApi.get('/admin/temple-remedies', {
    params: { group },
  })

  return {
    data: response.data?.data || [],
    counts: response.data?.counts || { payment_pending: 0, payment_received: 0, completed: 0 },
  }
}

export async function fetchAdminAppointments(group = 'waiting_payment', params = {}) {
  const response = await adminApi.get('/admin/appointments', {
    params: { group, ...params },
  })

  return {
    data: response.data?.data || [],
    counts: response.data?.counts || { waiting_payment: 0, payment_received: 0, upcoming: 0, completed: 0, cancelled: 0 },
    meta: response.data?.meta || {},
  }
}

export async function getAvailableAppointmentSlots({ astrologerId, slotDate, appointmentId }, options = {}) {
  const response = await adminApi.get('/admin/appointments/available-slots', {
    params: {
      astrologer_id: astrologerId,
      slot_date: slotDate,
      appointment_id: appointmentId,
    },
    signal: options.signal,
  })
  const payload = response.data?.data ?? response.data ?? {}
  const slots = Array.isArray(payload.slots) ? payload.slots : []

  return {
    astrologerId: payload.astrologer_id ?? null,
    slotDate: payload.slot_date ?? null,
    timezone: payload.timezone ?? null,
    workingHours: payload.working_hours ?? null,
    slotDurationMinutes: payload.slot_duration_minutes ?? null,
    breakDurationMinutes: payload.break_duration_minutes ?? null,
    summary: payload.summary ?? { available: 0, reserved: 0, blocked: 0 },
    slots: slots.map((slot) => ({
      ...slot,
      id: slot.id || slot.key || slot.start || slot.start_at,
      key: slot.key || slot.id || slot.start || slot.start_at,
      start_at: slot.start_at || slot.start,
      end_at: slot.end_at || slot.end,
      label: slot.label || slot.start_label,
      start_label: slot.start_label || slot.label,
      end_label: slot.end_label,
      range_label: slot.range_label || [slot.start_label || slot.label, slot.end_label].filter(Boolean).join(' - '),
      duration_minutes: slot.duration_minutes ?? payload.slot_duration_minutes,
      break_minutes: slot.break_minutes ?? payload.break_duration_minutes,
      available: slot.available ?? ['available', 'selected'].includes(slot.status),
      blocked: slot.blocked ?? ['reserved', 'blocked'].includes(slot.status),
      is_user_preferred: Boolean(slot.is_user_preferred),
      is_current_appointment_slot: Boolean(slot.is_current_appointment_slot),
    })),
  }
}

export const fetchAdminAppointmentSlots = getAvailableAppointmentSlots

export async function fetchAdminAppointmentMonthSlots({ astrologerId, month, appointmentId }, options = {}) {
  const response = await adminApi.get('/admin/appointments/month-slots', {
    params: {
      astrologer_id: astrologerId,
      month,
      appointment_id: appointmentId,
    },
    signal: options.signal,
  })

  return response.data?.data ?? response.data ?? {}
}

export async function fetchAdminPreferredSlotAvailability(appointmentId, params, options = {}) {
  const response = await adminApi.get(`/admin/appointments/${appointmentId}/preferred-slot-availability`, {
    params,
    signal: options.signal,
  })

  return response.data?.data ?? response.data ?? {}
}

export async function fetchAdminAppointmentManualPaymentFile(appointmentId) {
  const response = await adminApi.get(`/admin/appointments/${appointmentId}/manual-payment-file`, {
    responseType: 'blob',
  })

  return response.data
}

export async function updateAdminAppointment(appointmentId, payload) {
  const response = await adminApi.patch(`/admin/appointments/${appointmentId}`, payload)

  return response.data
}

export async function confirmAdminAppointmentSlot(appointmentId, payload) {
  const response = await adminApi.post(`/admin/appointments/${appointmentId}/confirm-slot`, payload)

  return response.data
}

export async function updateAdminTempleRemedy(remedyId, payload) {
  const hasFilePayload = payload instanceof FormData
  const response = hasFilePayload
    ? await adminApi.post(`/admin/temple-remedies/${remedyId}`, payload)
    : await adminApi.patch(`/admin/temple-remedies/${remedyId}`, payload)

  return response.data
}

export async function fetchAdminTempleRemedyAstrology(remedyId) {
  const response = await adminApi.get(`/admin/temple-remedies/${remedyId}/astrology`)

  return response.data
}

export async function fetchAdminTempleRemedyFile(remedyId) {
  const response = await adminApi.get(`/temple-remedies/${remedyId}/file`, {
    responseType: 'blob',
  })

  return response.data
}

export async function fetchAdminTempleRemedyManualPaymentFile(remedyId) {
  const response = await adminApi.get(`/admin/temple-remedies/${remedyId}/manual-payment-file`, {
    responseType: 'blob',
  })

  return response.data
}

export async function fetchAdminChatbotFaqs() {
  const response = await adminApi.get('/admin/chatbot/faqs')

  return {
    data: response.data?.data || [],
    topics: response.data?.topics || [],
    intents: response.data?.intents || [],
  }
}

export async function createAdminChatbotFaq(payload) {
  const response = await adminApi.post('/admin/chatbot/faqs', payload)

  return response.data
}

export async function updateAdminChatbotFaq(faqId, payload) {
  const response = await adminApi.put(`/admin/chatbot/faqs/${faqId}`, payload)

  return response.data
}

export async function deactivateAdminChatbotFaq(faqId) {
  const response = await adminApi.delete(`/admin/chatbot/faqs/${faqId}`)

  return response.data
}

export async function fetchAdminChatbotTopics() {
  const response = await adminApi.get('/admin/chatbot/topics')

  return response.data?.data || []
}

export async function createAdminChatbotTopic(payload) {
  const response = await adminApi.post('/admin/chatbot/topics', payload)

  return response.data
}

export async function updateAdminChatbotTopic(topicId, payload) {
  const response = await adminApi.put(`/admin/chatbot/topics/${topicId}`, payload)

  return response.data
}

export async function deactivateAdminChatbotTopic(topicId) {
  const response = await adminApi.delete(`/admin/chatbot/topics/${topicId}`)

  return response.data
}

export async function fetchAdminChatbotIntents() {
  const response = await adminApi.get('/admin/chatbot/intents')

  return response.data?.data || []
}

export async function createAdminChatbotIntent(payload) {
  const response = await adminApi.post('/admin/chatbot/intents', payload)

  return response.data
}

export async function updateAdminChatbotIntent(intentId, payload) {
  const response = await adminApi.put(`/admin/chatbot/intents/${intentId}`, payload)

  return response.data
}

export async function deactivateAdminChatbotIntent(intentId) {
  const response = await adminApi.delete(`/admin/chatbot/intents/${intentId}`)

  return response.data
}

export async function fetchAdminChatbotSettings() {
  const response = await adminApi.get('/admin/chatbot/settings')

  return {
    data: response.data?.data || {},
    cacheCount: response.data?.cache_count || 0,
  }
}

export async function fetchAdminPaymentMethodSettings() {
  const response = await adminApi.get('/admin/payment-method-settings')

  return response.data?.data || []
}

export async function updateAdminPaymentMethodSetting(serviceType, payload) {
  const response = await adminApi.post(`/admin/payment-method-settings/${serviceType}`, payload)

  return response.data
}

export async function fetchAdminFutureVideoManualPayments(status = 'PENDING', search = '') {
  const response = await adminApi.get('/admin/future-video-manual-payments', {
    params: { status, search },
  })

  return {
    data: response.data?.data || [],
    counts: response.data?.counts || { PENDING: 0, VERIFIED: 0, REJECTED: 0, ALL: 0 },
  }
}

export async function fetchAdminFutureVideoManualPaymentProof(paymentId) {
  const response = await adminApi.get(`/admin/future-video-manual-payments/${paymentId}/proof`, {
    responseType: 'blob',
  })

  return response.data
}

export async function verifyAdminFutureVideoManualPayment(paymentId) {
  const response = await adminApi.post(`/admin/future-video-manual-payments/${paymentId}/verify`)

  return response.data
}

export async function rejectAdminFutureVideoManualPayment(paymentId, rejectionReason = '') {
  const response = await adminApi.post(`/admin/future-video-manual-payments/${paymentId}/reject`, {
    rejection_reason: rejectionReason,
  })

  return response.data
}

export async function updateAdminChatbotSettings(payload) {
  const response = await adminApi.put('/admin/chatbot/settings', payload)

  return {
    data: response.data?.data || {},
    cacheCount: response.data?.cache_count || 0,
    message: response.data?.message,
  }
}

export async function clearAdminChatbotOpenAiCache() {
  const response = await adminApi.delete('/admin/chatbot/openai-cache')

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
