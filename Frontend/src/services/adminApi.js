import axios from 'axios'
import config from '@/config/config'
import { getAdminToken, removeAdminToken, removeAdminUser } from '@/utils/auth'

const adminApi = axios.create({
  baseURL: import.meta.env.VITE_API_URL?.trim() || config.apiUrl,
  timeout: 30000,
  headers: {
    Accept: 'application/json',
  },
})

adminApi.interceptors.request.use((requestConfig) => {
  const token = getAdminToken()

  if (token) {
    requestConfig.headers.Authorization = `Bearer ${token}`
  }

  return requestConfig
})

adminApi.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      removeAdminToken()
      removeAdminUser()
    }

    return Promise.reject(error)
  },
)

export default adminApi
