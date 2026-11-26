import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useLocation, useNavigate } from 'react-router-dom'
import AuthenticationLoader from './AuthenticationLoader'
import {
  getLanguageByCode,
  getStoredLanguage,
  LANGUAGE_CHANGE_EVENT,
  languageOptions,
  savePreferredLanguage,
} from '../../data/languageOptions'
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
  const languageTriggerRef = useRef(null)
  const [loggedIn, setLoggedIn] = useState(isLoggedIn)
  const [user, setUser] = useState(getUser)
  const [isMenuOpen, setIsMenuOpen] = useState(false)
  const [isLanguageMenuOpen, setIsLanguageMenuOpen] = useState(false)
  const [languageMenuStyle, setLanguageMenuStyle] = useState({})
  const [isConfirmOpen, setIsConfirmOpen] = useState(false)
  const [isSigningOut, setIsSigningOut] = useState(false)
  const [isMobileMenu, setIsMobileMenu] = useState(() => (
    typeof window !== 'undefined' ? window.matchMedia('(max-width: 560px)').matches : false
  ))
  const [selectedLanguageCode, setSelectedLanguageCode] = useState(() => (
    getStoredLanguage()?.code || languageOptions[0].code
  ))
  const selectedLanguage = getLanguageByCode(selectedLanguageCode) || languageOptions[0]
  const displayName = useMemo(() => {
    if (!user) return 'My Account'
    return user.name || user.email || user.phone_e164 || 'My Account'
  }, [user])
  const avatarLetter = displayName.trim().charAt(0).toUpperCase() || 'U'
  const avatarUrl = user?.profile_image_url || user?.avatar_url || user?.photo_url || ''

  useEffect(() => {
    const mediaQuery = window.matchMedia('(max-width: 560px)')
    const syncMobileMenu = () => {
      const isMobile = mediaQuery.matches
      setIsMobileMenu(isMobile)
      if (!isMobile) setIsLanguageMenuOpen(false)
    }

    syncMobileMenu()
    mediaQuery.addEventListener('change', syncMobileMenu)

    return () => mediaQuery.removeEventListener('change', syncMobileMenu)
  }, [])

  useEffect(() => {
    const syncSelectedLanguage = (event) => {
      const changedLanguage = getLanguageByCode(event?.detail?.code)
      const storedLanguage = changedLanguage || getStoredLanguage()

      if (storedLanguage) {
        setSelectedLanguageCode(storedLanguage.code)
      }
    }

    const handleStorage = (event) => {
      if (event.key !== 'ygtPreferredLanguage') return
      syncSelectedLanguage()
    }

    window.addEventListener(LANGUAGE_CHANGE_EVENT, syncSelectedLanguage)
    window.addEventListener('storage', handleStorage)

    return () => {
      window.removeEventListener(LANGUAGE_CHANGE_EVENT, syncSelectedLanguage)
      window.removeEventListener('storage', handleStorage)
    }
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
    setIsLanguageMenuOpen(false)
    setIsMenuOpen((open) => !open)
  }

  const toggleLanguageMenu = (event) => {
    event.preventDefault()
    event.stopPropagation()
    setIsMenuOpen(false)
    setIsLanguageMenuOpen((open) => !open)
  }

  const handleLanguageChange = (event) => {
    const savedLanguage = savePreferredLanguage(event.target.value)

    if (savedLanguage) {
      setSelectedLanguageCode(savedLanguage.code)
    }
  }

  const selectLanguage = (code) => {
    const savedLanguage = savePreferredLanguage(code)

    if (savedLanguage) {
      setSelectedLanguageCode(savedLanguage.code)
    }

    setIsLanguageMenuOpen(false)
  }

  useEffect(() => {
    if (!isLanguageMenuOpen) return undefined

    const updatePosition = () => {
      const rect = languageTriggerRef.current?.getBoundingClientRect()
      if (!rect) return

      setLanguageMenuStyle({
        top: `${Math.round(rect.bottom + 8)}px`,
        right: `${Math.max(8, Math.round(window.innerWidth - rect.right))}px`,
      })
    }

    const handlePointerDown = (event) => {
      if (languageTriggerRef.current?.contains(event.target)) return
      setIsLanguageMenuOpen(false)
    }

    const handleKeyDown = (event) => {
      if (event.key !== 'Escape') return
      setIsLanguageMenuOpen(false)
    }

    updatePosition()
    document.addEventListener('pointerdown', handlePointerDown)
    document.addEventListener('keydown', handleKeyDown)
    window.addEventListener('resize', updatePosition)

    return () => {
      document.removeEventListener('pointerdown', handlePointerDown)
      document.removeEventListener('keydown', handleKeyDown)
      window.removeEventListener('resize', updatePosition)
    }
  }, [isLanguageMenuOpen])

  const languagePopoverMarkup = isLanguageMenuOpen ? createPortal(
    <>
      <div className="home-language-popover-backdrop" aria-hidden="true"></div>
      <div
        id="mobile-language-menu"
        className="home-language-popover"
        style={languageMenuStyle}
        role="listbox"
        aria-label="Select language"
      >
        {languageOptions.map((language) => (
          <button
            key={language.code}
            type="button"
            role="option"
            aria-selected={language.code === selectedLanguageCode}
            className={language.code === selectedLanguageCode ? 'is-selected' : ''}
            onClick={() => selectLanguage(language.code)}
          >
            {language.nativeName}
          </button>
        ))}
      </div>
    </>,
    document.body,
  ) : null

  const languageSelectorMarkup = isMobileMenu ? (
    <div className="home-language-compact">
      <button
        ref={languageTriggerRef}
        type="button"
        className="home-language-trigger"
        aria-haspopup="listbox"
        aria-expanded={isLanguageMenuOpen}
        aria-controls="mobile-language-menu"
        aria-label={`Language, ${selectedLanguage.nativeName}`}
        onClick={toggleLanguageMenu}
      >
        <span>{selectedLanguage.nativeName}</span>
        <span className="home-language-chevron" aria-hidden="true"></span>
      </button>
      {languagePopoverMarkup}
    </div>
  ) : (
    <label className="home-language-selector">
      <select
        value={selectedLanguageCode}
        onChange={handleLanguageChange}
        aria-label="Select language"
      >
        {languageOptions.map((language) => (
          <option key={language.code} value={language.code}>
            {language.nativeName}
          </option>
        ))}
      </select>
    </label>
  )

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
      <div className="home-hero-auth-actions" aria-label="Language and account actions">
        {languageSelectorMarkup}
        <button
          type="button"
          className={`home-hero-login${isMobileMenu ? ' is-icon' : ''}`}
          aria-label="Login"
          onClick={() => navigate(`/login?mode=login&redirect=${encodeURIComponent(redirectTo)}`, { state: { backgroundLocation: location } })}
        >
          {isMobileMenu ? (
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M12 12a4.2 4.2 0 1 0-4.2-4.2A4.2 4.2 0 0 0 12 12m0 2.2c-3.7 0-6.8 1.9-6.8 4.2V20h13.6v-1.6c0-2.3-3.1-4.2-6.8-4.2z" />
            </svg>
          ) : 'Login'}
        </button>
      </div>
    )
  }

  return (
    <div className="home-hero-auth-actions" aria-label="Language and account actions">
      {languageSelectorMarkup}
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
