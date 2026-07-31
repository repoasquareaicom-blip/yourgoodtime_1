import { useLocation, useNavigate } from 'react-router-dom'

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
    label: 'Daily',
    path: '/daily',
    activePaths: ['/daily'],
    icon: <path d="M12 3a9 9 0 1 0 9 9 9 9 0 0 0-9-9m1 9.4V7h-2v6.2l4.7 2.8 1-1.7z" />,
  },
  {
    label: 'Gallery',
    path: '/videos',
    activePaths: ['/videos'],
    icon: <path d="M4 6h5v5H4zm0 7h5v5H4zm7-7h9v5h-9zm0 7h9v5h-9z" />,
  },
  {
    label: 'Settings',
    path: '/settings',
    activePaths: ['/settings'],
    icon: <path d="m19.4 13.5 1.3-1.5-1.3-1.5-1.9.2a6 6 0 0 0-.8-.5l-.3-1.9-2-.3-.8 1.7a5 5 0 0 0-.9 0l-.8-1.7-2 .3-.3 1.9c-.3.1-.6.3-.8.5l-1.9-.2L3.3 12l1.3 1.5-.2 1.9 1.9.8.8 1.7 2-.3.8-1.7h.9l.8 1.7 2-.3.3-1.9.8-.5 1.9.2.9-1.9zM12 14.8a2.8 2.8 0 1 1 2.8-2.8 2.8 2.8 0 0 1-2.8 2.8" />,
  },
]

function PrimaryNav() {
  const navigate = useNavigate()
  const location = useLocation()

  return (
    <nav className="home-footer-nav" aria-label="Primary">
      {navItems.map((item) => {
        const isActive = item.activePaths.some((path) => location.pathname === path || location.pathname.startsWith(`${path}/`))

        return (
          <button
            key={item.path}
            type="button"
            className={`footer-nav-item ${isActive ? 'is-active' : ''}`}
            aria-current={isActive ? 'page' : undefined}
            onClick={() => {
              if (!isActive) navigate(item.path)
            }}
          >
            <span className="footer-nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24">{item.icon}</svg>
            </span>
            <span className="footer-nav-label">{item.label}</span>
          </button>
        )
      })}
    </nav>
  )
}

export default PrimaryNav
