import axios from 'axios'
import config from '@/config/config'

const publicChatbotApi = axios.create({
  baseURL: import.meta.env.VITE_API_URL?.trim() || config.apiUrl,
  timeout: 30000,
  headers: {
    Accept: 'application/json',
  },
})

export async function sendChatbotMessage(message) {
  const response = await publicChatbotApi.post('/chatbot/message', { message })

  return response.data
}
