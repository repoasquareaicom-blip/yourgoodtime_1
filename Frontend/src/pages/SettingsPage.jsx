import { useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import ygtLogo from '../assets/yt-logo.png'
import { getStoredLanguageName, languageOptions, savePreferredLanguage } from '../data/languageOptions'
import './HomePage.css'
import './SettingsPage.css'

function SettingsPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const [selectedLanguage, setSelectedLanguage] = useState(() => (
    window.localStorage.getItem('ygtPreferredLanguage') || ''
  ))
  const [selectedLanguageName, setSelectedLanguageName] = useState(getStoredLanguageName)

  const handleLanguageSelect = (language) => {
    savePreferredLanguage(language)
    setSelectedLanguage(language.code)
    setSelectedLanguageName(language.name)
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

          <button
            type="button"
            className="home-hero-login"
            onClick={() => navigate('/login?mode=login&redirect=/account', { state: { backgroundLocation: location } })}
          >
            Login
          </button>
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
          <button type="button" className="footer-nav-item" onClick={() => navigate('/home')}>
            <span className="footer-nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z" /></svg>
            </span>
            <span className="footer-nav-label">Home</span>
          </button>

          <button type="button" className="footer-nav-item" onClick={() => navigate('/account')}>
            <span className="footer-nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M12 12a4.5 4.5 0 1 0-4.5-4.5A4.5 4.5 0 0 0 12 12m0 2c-4 0-7 2-7 4.5V20h14v-1.5C19 16 16 14 12 14" /></svg>
            </span>
            <span className="footer-nav-label">My Account</span>
          </button>

          <button type="button" className="footer-nav-item" onClick={() => navigate('/daily')}>
            <span className="footer-nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M12 3a9 9 0 1 0 9 9 9 9 0 0 0-9-9m1 9.4V7h-2v6.2l4.7 2.8 1-1.7z" /></svg>
            </span>
            <span className="footer-nav-label">Daily</span>
          </button>

          <button type="button" className="footer-nav-item" onClick={() => navigate('/videos')}>
            <span className="footer-nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M4 6h5v5H4zm0 7h5v5H4zm7-7h9v5h-9zm0 7h9v5h-9z" /></svg>
            </span>
            <span className="footer-nav-label">Gallery</span>
          </button>

          <button type="button" className="footer-nav-item is-active" aria-current="page">
            <span className="footer-nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="m19.4 13.5 1.3-1.5-1.3-1.5-1.9.2a6 6 0 0 0-.8-.5l-.3-1.9-2-.3-.8 1.7a5 5 0 0 0-.9 0l-.8-1.7-2 .3-.3 1.9c-.3.1-.6.3-.8.5l-1.9-.2L3.3 12l1.3 1.5-.2 1.9 1.9.8.8 1.7 2-.3.8-1.7h.9l.8 1.7 2-.3.3-1.9.8-.5 1.9.2.9-1.9zM12 14.8a2.8 2.8 0 1 1 2.8-2.8 2.8 2.8 0 0 1-2.8 2.8" /></svg>
            </span>
            <span className="footer-nav-label">Settings</span>
          </button>
        </nav>
      </section>
    </main>
  )
}

export default SettingsPage
