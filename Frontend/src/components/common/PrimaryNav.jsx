import { useLocation, useNavigate } from 'react-router-dom'
import { openYgtChatbot } from '../../utils/chatbotEvents'
import { scrollPageToTop } from '../../utils/scrollPageToTop'

const navItems = [
  {
    label: 'Home',
    path: '/home',
    activePaths: ['/home'],
    icon: <path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z" />,
  },
  {
    label: 'My Account',
    path: '/account',
    activePaths: ['/account', '/dashboard', '/profile', '/my-predictions', '/remedy', '/appointment', '/future', '/account-loading'],
    icon: <path d="M12 12a4.5 4.5 0 1 0-4.5-4.5A4.5 4.5 0 0 0 12 12m0 2c-4 0-7 2-7 4.5V20h14v-1.5C19 16 16 14 12 14" />,
  },
  {
    label: 'Gallery',
    path: '/videos',
    activePaths: ['/videos'],
    icon: <path d="M4 6h5v5H4zm0 7h5v5H4zm7-7h9v5h-9zm0 7h9v5h-9z" />,
  },
]

function PrimaryNav({ accountPreview = false }) {
  const navigate = useNavigate()
  const location = useLocation()

  return (
    <nav className="home-footer-nav" aria-label="Primary">
      {navItems.map((item) => {
        const isActive = accountPreview
          ? item.path === '/account'
          : item.activePaths.some((path) => location.pathname === path || location.pathname.startsWith(`${path}/`))

        return (
          <button
            key={item.path}
            type="button"
            disabled={accountPreview}
            className={`footer-nav-item ${isActive ? 'is-active' : ''}`}
            aria-current={isActive ? 'page' : undefined}
            onClick={() => {
              if (!isActive) navigate(item.path)
              scrollPageToTop()
            }}
          >
            <span className="footer-nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24">{item.icon}</svg>
            </span>
            <span className="footer-nav-label">{item.label}</span>
          </button>
        )
      })}
      <button
        type="button"
        disabled={accountPreview}
        className="footer-nav-item footer-nav-chat"
        onClick={openYgtChatbot}
      >
        <span className="footer-nav-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24">
            <path d="M5 4.75A1.75 1.75 0 0 1 6.75 3h10.5A1.75 1.75 0 0 1 19 4.75v8.5A1.75 1.75 0 0 1 17.25 15H9.12L5.7 18.08A.8.8 0 0 1 4.4 17.5V4.75z" />
          </svg>
        </span>
        <span className="footer-nav-label">Ask Me</span>
      </button>
    </nav>
  )
}

export default PrimaryNav
