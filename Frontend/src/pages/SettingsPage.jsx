import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import HeaderAuthActions from '../components/common/HeaderAuthActions'
import ygtLogo from '../assets/yt-logo.png'
import {
  getLanguageByCode,
  getStoredLanguage,
  getStoredLanguageName,
  LANGUAGE_CHANGE_EVENT,
  languageOptions,
  savePreferredLanguage,
} from '../data/languageOptions'
import { scrollPageToTop } from '../utils/scrollPageToTop'
import './HomePage.css'
import './SettingsPage.css'

function SettingsPage() {
  const navigate = useNavigate()
  const [selectedLanguage, setSelectedLanguage] = useState(() => (
    getStoredLanguage()?.code || ''
  ))
  const [selectedLanguageName, setSelectedLanguageName] = useState(getStoredLanguageName)

  useEffect(() => {
    const syncSelectedLanguage = (event) => {
      const changedLanguage = getLanguageByCode(event?.detail?.code)
      const storedLanguage = changedLanguage || getStoredLanguage()

      setSelectedLanguage(storedLanguage?.code || '')
      setSelectedLanguageName(storedLanguage?.name || 'Not selected')
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

  const handleLanguageSelect = (language) => {
    const savedLanguage = savePreferredLanguage(language)

    if (savedLanguage) {
      setSelectedLanguage(savedLanguage.code)
      setSelectedLanguageName(savedLanguage.name)
    }
  }

  return (
    <main className="settings-page">
      <section className="home-hero-shell settings-shell">
        <header className="home-hero-header" aria-label="Settings page header">
          <div className="home-hero-brand">
            <img src={ygtLogo} alt="YGT" className="home-hero-logo" />
            <div className="home-hero-brand-copy">
              <h1>Your Good Time</h1>
              <p>Your Journey Written in the Stars</p>
            </div>
          </div>

          <HeaderAuthActions />
        </header>

        <section className="settings-content" aria-label="Settings">
          <div className="settings-title">
            <span>Settings</span>
            <h2>App Preferences</h2>
          </div>

          <article className="settings-card">
            <div className="settings-card-head">
              <div>
                <p>Language</p>
                <h3>{selectedLanguageName}</h3>
              </div>
              <span className="settings-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M4 5h9v2H8.7a12 12 0 0 0 1.8 3.1 11 11 0 0 0 1.1-2.1h2.1a13 13 0 0 1-1.8 3.7l2.1 2.1-1.4 1.4-2-2a13 13 0 0 1-3.5 2.4L6 13.9a11 11 0 0 0 3.2-2.1A14 14 0 0 1 6.5 7H4zm12.6 4h2L22 19h-2.1l-.7-2h-3.3l-.7 2H13zm-.1 6.2h2.1l-1-3.1z" /></svg>
              </span>
            </div>

            <div className="settings-language-grid">
              {languageOptions.map((language) => (
                <button
                  key={language.code}
                  type="button"
                  className={`settings-language-option ${selectedLanguage === language.code ? 'is-selected' : ''}`}
                  onClick={() => handleLanguageSelect(language)}
                >
                  <span>{language.name}</span>
                  {selectedLanguage === language.code ? <strong>Selected</strong> : null}
                </button>
              ))}
            </div>
          </article>
        </section>

        <nav className="home-footer-nav" aria-label="Primary">
          <button type="button" className="footer-nav-item" onClick={() => { navigate('/home'); scrollPageToTop() }}>
            <span className="footer-nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z" /></svg>
            </span>
            <span className="footer-nav-label">Home</span>
          </button>

          <button type="button" className="footer-nav-item" onClick={() => { navigate('/account'); scrollPageToTop() }}>
            <span className="footer-nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M12 12a4.5 4.5 0 1 0-4.5-4.5A4.5 4.5 0 0 0 12 12m0 2c-4 0-7 2-7 4.5V20h14v-1.5C19 16 16 14 12 14" /></svg>
            </span>
            <span className="footer-nav-label">My Account</span>
          </button>

          <button type="button" className="footer-nav-item" onClick={() => { navigate('/videos'); scrollPageToTop() }}>
            <span className="footer-nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M4 6h5v5H4zm0 7h5v5H4zm7-7h9v5h-9zm0 7h9v5h-9z" /></svg>
            </span>
            <span className="footer-nav-label">Gallery</span>
          </button>

        </nav>
      </section>
    </main>
  )
}

export default SettingsPage
