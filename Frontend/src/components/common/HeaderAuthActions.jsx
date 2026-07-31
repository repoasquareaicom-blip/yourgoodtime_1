import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useLocation, useNavigate } from 'react-router-dom'
import AuthenticationLoader from './AuthenticationLoader'
import { logout as logoutUser } from '../../services/authService'
import { clearMyAccountPredictionCache } from '../../services/myAccountService'
import { getUser, isLoggedIn, removeToken, removeUser } from '../../utils/auth'

const menuItems = [
  { label: 'Dashboard', path: '/dashboard' },
  { label: 'My Predictions', path: '/my-predictions' },
  { label: 'Temple Remedies', path: '/remedy' },
  { label: 'Appointments', path: '/appointment' },
  { label: 'Profile', path: '/profile' },
]

function HeaderAuthActions({ redirectTo = '/account' }) {
  const navigate = useNavigate()
  const location = useLocation()
  const menuRef = useRef(null)
  const triggerRef = useRef(null)
  const dialogRef = useRef(null)
  const firstMenuItemRef = useRef(null)
  const [loggedIn, setLoggedIn] = useState(isLoggedIn)
  const [user, setUser] = useState(getUser)
  const [isMenuOpen, setIsMenuOpen] = useState(false)
  const [isConfirmOpen, setIsConfirmOpen] = useState(false)
  const [isSigningOut, setIsSigningOut] = useState(false)
  const [isMobileMenu, setIsMobileMenu] = useState(false)
  const displayName = useMemo(() => {
    if (!user) return 'My Account'
    return user.name || user.email || user.phone_e164 || 'My Account'
  }, [user])
  const avatarLetter = displayName.trim().charAt(0).toUpperCase() || 'U'
  const avatarUrl = user?.profile_image_url || user?.avatar_url || user?.photo_url || ''

  useEffect(() => {
    const mediaQuery = window.matchMedia('(max-width: 560px)')
    const syncMobileMenu = () => setIsMobileMenu(mediaQuery.matches)

    syncMobileMenu()
    mediaQuery.addEventListener('change', syncMobileMenu)

    return () => mediaQuery.removeEventListener('change', syncMobileMenu)
  }, [])

  useEffect(() => {
    if (!isMenuOpen) return undefined

    const handlePointerDown = (event) => {
      if (menuRef.current?.contains(event.target)) return
      if (triggerRef.current?.contains(event.target)) return
      setIsMenuOpen(false)
    }

    const handleKeyDown = (event) => {
      if (event.key !== 'Escape') return
      setIsMenuOpen(false)
      window.setTimeout(() => triggerRef.current?.focus(), 0)
    }

    document.addEventListener('pointerdown', handlePointerDown)
    document.addEventListener('keydown', handleKeyDown)
    window.setTimeout(() => firstMenuItemRef.current?.focus(), 0)

    return () => {
      document.removeEventListener('pointerdown', handlePointerDown)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [isMenuOpen])

  useEffect(() => {
    if (!isConfirmOpen) return undefined

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    window.setTimeout(() => dialogRef.current?.focus(), 0)

    const handleKeyDown = (event) => {
      if (event.key !== 'Escape') return
      setIsConfirmOpen(false)
      window.setTimeout(() => triggerRef.current?.focus(), 0)
    }

    document.addEventListener('keydown', handleKeyDown)

    return () => {
      document.body.style.overflow = previousOverflow
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [isConfirmOpen])

  const clearAuthSession = () => {
    clearMyAccountPredictionCache()
    removeToken()
    removeUser()

    try {
      window.sessionStorage.clear()
    } catch {
      // Session storage can be unavailable in private browser contexts.
    }
  }

  const handleLogout = async () => {
    setIsMenuOpen(false)
    setIsSigningOut(true)

    try {
      await logoutUser()
    } catch {
      // Local logout should still complete if the token is already invalid.
    } finally {
      clearAuthSession()
      window.setTimeout(() => {
        setLoggedIn(false)
        setUser(null)
        setIsSigningOut(false)
        navigate('/home')
      }, 800)
    }
  }

  const openRoute = (path) => {
    setIsMenuOpen(false)
    navigate(path)
  }

  const openLogoutConfirm = () => {
    setIsMenuOpen(false)
    window.setTimeout(() => {
      setIsConfirmOpen(true)
    }, 0)
  }

  const closeLogoutConfirm = () => {
    setIsConfirmOpen(false)
    window.setTimeout(() => triggerRef.current?.focus(), 0)
  }

  const toggleMenu = (event) => {
    event.preventDefault()
    event.stopPropagation()
    setIsMenuOpen((open) => !open)
  }

  const menuMarkup = isMenuOpen ? (
    <div
      id="mobile-profile-menu"
      ref={menuRef}
      className={`home-profile-menu ${isMobileMenu ? 'is-mobile-sheet' : ''}`}
      role="menu"
    >
      {menuItems.map((item, index) => (
        <button
          key={item.path}
          ref={index === 0 ? firstMenuItemRef : null}
          type="button"
          role="menuitem"
          onClick={() => openRoute(item.path)}
        >
          {item.label}
        </button>
      ))}
      <button type="button" role="menuitem" className="is-logout" onClick={openLogoutConfirm}>
        Logout
      </button>
    </div>
  ) : null

  const mobileMenuMarkup = isMenuOpen && isMobileMenu ? createPortal(
    <>
      <div className="home-profile-menu-backdrop" aria-hidden="true"></div>
      {menuMarkup}
    </>,
    document.body,
  ) : null

  const dialogMarkup = isConfirmOpen ? createPortal(
    <div className="home-logout-dialog-backdrop" role="presentation" onPointerDown={closeLogoutConfirm}>
      <section
        ref={dialogRef}
        className="home-logout-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="logout-title"
        tabIndex={-1}
        onPointerDown={(event) => event.stopPropagation()}
      >
        <h2 id="logout-title">Logout</h2>
        <p>Are you sure you want to logout?</p>
        <div className="home-logout-dialog-actions">
          <button type="button" onClick={closeLogoutConfirm}>Cancel</button>
          <button type="button" className="is-danger" onClick={() => {
            setIsConfirmOpen(false)
            handleLogout()
          }}>
            Logout
          </button>
        </div>
      </section>
    </div>,
    document.body,
  ) : null

  const signingOutMarkup = isSigningOut ? createPortal(
    <AuthenticationLoader fullscreen title="Signing you out..." message="Please wait..." />,
    document.body,
  ) : null

  if (!loggedIn) {
    return (
      <button
        type="button"
        className="home-hero-login"
        onClick={() => navigate(`/login?mode=login&redirect=${encodeURIComponent(redirectTo)}`, { state: { backgroundLocation: location } })}
      >
        Login
      </button>
    )
  }

  return (
    <div className="home-hero-auth-actions" aria-label="Account actions">
      <button
        ref={triggerRef}
        type="button"
        className="home-profile-trigger"
        aria-haspopup="menu"
        aria-expanded={isMenuOpen}
        aria-controls="mobile-profile-menu"
        onClick={toggleMenu}
      >
        <span className="home-profile-avatar" aria-hidden="true">
          {avatarUrl ? <img src={avatarUrl} alt="" /> : avatarLetter}
        </span>
        <span className="home-profile-copy">
          <span>Welcome back,</span>
          <strong>{displayName}</strong>
        </span>
        <span className={`home-profile-caret ${isMenuOpen ? 'is-open' : ''}`} aria-hidden="true">▼</span>
      </button>

      {isMenuOpen && !isMobileMenu ? menuMarkup : null}
      {mobileMenuMarkup}

      {dialogMarkup}
      {signingOutMarkup}
    </div>
  )
}

export default HeaderAuthActions
