import { useEffect, useState } from 'react'
import HeaderAuthActions from '../components/common/HeaderAuthActions'
import PrimaryNav from '../components/common/PrimaryNav'
import ygtLogo from '../assets/yt-logo.png'
import {
  getLanguageByCode,
  getStoredLanguage,
  getStoredLanguageName,
  LANGUAGE_CHANGE_EVENT,
  languageOptions,
  savePreferredLanguage,
} from '../data/languageOptions'
import './HomePage.css'
import './SettingsPage.css'

function SettingsPage() {
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

        <PrimaryNav />
      </section>
    </main>
  )
}

export default SettingsPage
