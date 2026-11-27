import api from './api'

export async function fetchActiveFutureProduct() {
  const response = await api.get('/prediction-products/future/active')

  return response.data?.product
}

export async function fetchFutureAccess({ predictionRequestId, requestUuid }) {
  const response = await api.get('/predictions/future-access', {
    params: {
      prediction_request_id: predictionRequestId,
      request_uuid: requestUuid,
    },
  })

  return response.data
}

export async function fetchFuturePaymentSettings() {
  const response = await api.get('/payment-method-settings/future-video')

  return response.data?.data || {
    service_type: 'FUTURE_VIDEO',
    payment_gateway_enabled: true,
    gpay_qr_enabled: false,
    qr_image_url: null,
  }
}

export async function fetchFutureQrPaymentStatus({ predictionRequestId, requestUuid, productId }) {
  const response = await api.get('/payments/future-prediction/manual-qr/status', {
    params: {
      prediction_request_id: predictionRequestId,
      request_uuid: requestUuid,
      product_id: productId,
    },
  })

  return response.data?.data || null
}

export async function downloadFutureQrImage() {
  const { data: image } = await api.get('/payment-method-settings/future-video/qr-download', { responseType: 'blob' })
  const extensions = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp' }
  const extension = extensions[image.type]
  if (!extension) throw new Error('Invalid QR image')

  const url = URL.createObjectURL(image)
  const link = document.createElement('a')
  link.href = url
  link.download = `your-good-time-upi-qr.${extension}`
  document.body.append(link)
  link.click()
  link.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 1000)
}

export async function createFuturePredictionOrder({ predictionRequestId, requestUuid, productId }) {
  const response = await api.post('/payments/future-prediction/order', {
    prediction_request_id: predictionRequestId,
    request_uuid: requestUuid,
    product_id: productId,
  })

  return response.data?.data
}

export async function beginFutureQrPayment({ predictionRequestId, requestUuid, productId }) {
  const response = await api.post('/payments/future-prediction/manual-qr/begin', {
    prediction_request_id: predictionRequestId,
    request_uuid: requestUuid,
    product_id: productId,
  })

  return response.data?.data
}

export async function submitFutureQrPayment({ paymentId, predictionRequestId, requestUuid, productId, paymentProof }) {
  const payload = new FormData()
  if (paymentId) {
    payload.append('payment_id', paymentId)
  } else {
    payload.append('prediction_request_id', predictionRequestId)
    payload.append('request_uuid', requestUuid)
    payload.append('product_id', productId)
  }
  if (paymentProof) payload.append('payment_proof', paymentProof)

  const response = await api.post('/payments/future-prediction/manual-qr', payload)

  return response.data
}

export async function createTempleRemedyOrder(remedyId) {
  const response = await api.post('/payments/temple-remedy/order', {
    temple_remedy_request_id: remedyId,
  })

  return response.data?.data
}

export async function createAppointmentOrder(appointmentId) {
  const response = await api.post('/payments/astrologer-appointment/order', {
    appointment_id: appointmentId,
  })

  return response.data?.data
}

export async function verifyRazorpayPayment(payload) {
  const response = await api.post('/payments/razorpay/verify', payload)

  return response.data
}

export function loadRazorpayScript() {
  if (window.Razorpay) return Promise.resolve(true)

  return new Promise((resolve) => {
    const script = document.createElement('script')
    script.src = 'https://checkout.razorpay.com/v1/checkout.js'
    script.async = true
    script.onload = () => resolve(true)
    script.onerror = () => resolve(false)
    document.body.appendChild(script)
  })
}
