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

export async function createFuturePredictionOrder({ predictionRequestId, requestUuid, productId }) {
  const response = await api.post('/payments/future-prediction/order', {
    prediction_request_id: predictionRequestId,
    request_uuid: requestUuid,
    product_id: productId,
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
