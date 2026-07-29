import axios from 'axios'
import config from '@/config/config'
import { getToken, removeToken, removeUser } from '@/utils/auth'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL?.trim() || config.apiUrl,
  timeout: 30000,
  headers: {
    Accept: 'application/json',
  },
})

api.interceptors.request.use((requestConfig) => {
  const token = getToken()

  if (token) {
    requestConfig.headers.Authorization = `Bearer ${token}`
  }

  return requestConfig
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      removeToken()
      removeUser()
    }

    return Promise.reject(error)
  },
)

export default api
