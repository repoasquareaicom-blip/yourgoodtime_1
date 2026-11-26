export const OPEN_YGT_CHATBOT_EVENT = 'ygt-open-chatbot'

export function openYgtChatbot() {
  window.dispatchEvent(new CustomEvent(OPEN_YGT_CHATBOT_EVENT))
}
