import { useEffect, useRef, useState } from 'react'
import { sendChatbotMessage } from '../../services/chatbotService'
import './ChatbotWidget.css'

const quickActions = [
  { label: 'Past Prediction', message: 'Is Past Prediction free?' },
  { label: 'Future Prediction', message: 'How much is Future Prediction?' },
  { label: 'Temple Remedy', message: 'What is Temple Remedy?' },
  { label: 'Talk to Astrologer', message: 'How much does Talk to Astrologer cost?' },
  { label: 'Prediction Status', message: 'How can I check my prediction status?' },
  { label: 'Customer Support', message: 'How can I contact customer support?' },
]

const welcomeMessage = {
  id: 'welcome',
  role: 'bot',
  text: 'Welcome to Your Good Time. How can I help you?',
}

function ChatbotWidget() {
  const [isOpen, setIsOpen] = useState(false)
  const [shouldRenderPanel, setShouldRenderPanel] = useState(false)
  const [isClosing, setIsClosing] = useState(false)
  const [messages, setMessages] = useState([welcomeMessage])
  const [draft, setDraft] = useState('')
  const [isSending, setIsSending] = useState(false)
  const messageListRef = useRef(null)
  const inputRef = useRef(null)

  useEffect(() => {
    if (isOpen) {
      setShouldRenderPanel(true)
      setIsClosing(false)
      return undefined
    }

    if (!shouldRenderPanel) return undefined

    setIsClosing(true)
    const closeTimer = window.setTimeout(() => {
      setShouldRenderPanel(false)
      setIsClosing(false)
    }, 280)

    return () => window.clearTimeout(closeTimer)
  }, [isOpen, shouldRenderPanel])

  useEffect(() => {
    if (!isOpen) return

    window.setTimeout(() => inputRef.current?.focus(), 120)
  }, [isOpen])

  useEffect(() => {
    if (!isOpen) return

    messageListRef.current?.scrollTo({
      top: messageListRef.current.scrollHeight,
      behavior: 'smooth',
    })
  }, [isOpen, messages, isSending])

  const closePanel = () => {
    setIsOpen(false)
  }

  const sendMessage = async (messageText) => {
    const text = messageText.trim()

    if (!text || isSending) return

    setDraft('')
    setIsSending(true)
    setMessages((current) => [
      ...current,
      { id: `user-${Date.now()}`, role: 'user', text },
    ])

    try {
      const response = await sendChatbotMessage(text)
      setMessages((current) => [
        ...current,
        {
          id: `bot-${Date.now()}`,
          role: 'bot',
          text: response?.message || "I'm sorry, I couldn't find an answer for that yet.",
        },
      ])
    } catch {
      setMessages((current) => [
        ...current,
        {
          id: `bot-${Date.now()}`,
          role: 'bot',
          text: 'Sorry, I could not reach the assistant right now. Please try again in a moment.',
        },
      ])
    } finally {
      setIsSending(false)
    }
  }

  const handleSubmit = (event) => {
    event.preventDefault()
    sendMessage(draft)
  }

  return (
    <section className={`ygt-chatbot ${isOpen ? 'is-open' : ''}`} aria-label="YGT Assistant">
      {shouldRenderPanel ? (
        <div className={`ygt-chatbot-panel ${isClosing ? 'is-closing' : 'is-opening'}`} role="dialog" aria-label="YGT Assistant" aria-modal="false">
          <header className="ygt-chatbot-header">
            <div className="ygt-chatbot-title">
              <span className="ygt-chatbot-header-icon" aria-hidden="true">AI</span>
              <div>
                <span>Ask YGT</span>
                <h2>YGT Assistant</h2>
                <small>Online</small>
              </div>
            </div>
            <button type="button" onClick={closePanel} aria-label="Minimize YGT Assistant">x</button>
          </header>

          <div className="ygt-chatbot-messages" ref={messageListRef}>
            {messages.map((message) => (
              <div key={message.id} className={`ygt-chatbot-message is-${message.role}`}>
                <p>{message.text}</p>
              </div>
            ))}
            {isSending ? (
              <div className="ygt-chatbot-message is-bot is-thinking" role="status" aria-live="polite">
                <p className="ygt-chatbot-typing">
                  <span>YGT Assistant is thinking</span>
                  <span className="ygt-chatbot-dots" aria-hidden="true">
                    <i />
                    <i />
                    <i />
                  </span>
                </p>
              </div>
            ) : null}
          </div>

          <div className="ygt-chatbot-quick-actions" aria-label="Quick questions">
            {quickActions.map((action) => (
              <button
                key={action.label}
                type="button"
                disabled={isSending}
                onClick={() => sendMessage(action.message)}
              >
                {action.label}
              </button>
            ))}
          </div>

          <form className="ygt-chatbot-input-row" onSubmit={handleSubmit}>
            <input
              ref={inputRef}
              value={draft}
              disabled={isSending}
              maxLength="1000"
              placeholder="Type your question"
              aria-label="Type your question"
              onChange={(event) => setDraft(event.target.value)}
            />
            <button type="submit" disabled={isSending || !draft.trim()}>Send</button>
          </form>
        </div>
      ) : null}

      <button
        type="button"
        className={`ygt-chatbot-launcher ${isOpen ? 'is-active' : ''}`}
        aria-label={isOpen ? 'YGT Assistant is open' : 'Open YGT Assistant'}
        aria-expanded={isOpen}
        onClick={() => setIsOpen((current) => !current)}
      >
        <span className="ygt-chatbot-launcher-ring" aria-hidden="true" />
        <span className="ygt-chatbot-launcher-icon" aria-hidden="true">
          <span>AI</span>
          <i />
        </span>
        <span className="ygt-chatbot-launcher-label">Ask YGT</span>
      </button>
    </section>
  )
}

export default ChatbotWidget
