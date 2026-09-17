import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import templeImage from '../assets/temple-transparent.png'
import ytLogo from '../assets/yt-logo.png'
import { getStoredLanguage, languageOptions, savePreferredLanguage } from '../data/languageOptions'
import './LanguageSelectionPage.css'

function LanguageSelectionPage() {
  const navigate = useNavigate()

  useEffect(() => {
    if (getStoredLanguage()) {
      navigate('/intro', { replace: true })
    }
  }, [navigate])

  const handleLanguageSelect = (language) => {
    savePreferredLanguage(language)
    navigate('/intro', { replace: true })
  }

  return (
    <main className="language-page">
      <div className="language-sky" aria-hidden="true">
        <img src={templeImage} alt="" className="language-temple" />
        <span className="language-star star-a"></span>
        <span className="language-star star-b"></span>
        <span className="language-star star-c"></span>
      </div>

      <section className="language-panel" aria-labelledby="language-title">
        <img src={ytLogo} alt="YGT logo" className="language-logo" />
        <p className="language-eyebrow">Choose Language</p>
        <div className="language-grid">
          {languageOptions.map((language) => (
            <button
              key={language.code}
              type="button"
              className="language-option"
              onClick={() => handleLanguageSelect(language)}
            >
              <span>{language.name}</span>
            </button>
          ))}
        </div>
      </section>
    </main>
  )
}

export default LanguageSelectionPage
