import api from './api'

export async function submitContactQuery(payload, onUploadProgress) {
  const response = await api.post('/contact-queries', payload, {
    headers: {
      'Content-Type': 'multipart/form-data',
    },
    onUploadProgress,
  })

  return response.data
}
