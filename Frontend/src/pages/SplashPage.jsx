import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import templeImage from '../assets/temple-transparent.png'
import ytLogo from '../assets/yt-logo.png'
import { getStoredLanguage } from '../data/languageOptions'
import './SplashPage.css'

function SplashPage() {
  const navigate = useNavigate()

  useEffect(() => {
    const nextRoute = getStoredLanguage() ? '/intro' : '/language'

    const timer = window.setTimeout(() => {
      navigate(nextRoute, { replace: true })
    }, 5000)

    const handleKeyDown = (event) => {
      if (event.key === 'Enter') {
        window.clearTimeout(timer)
        navigate(nextRoute, { replace: true })
      }
    }

    window.addEventListener('keydown', handleKeyDown)

    return () => {
      window.clearTimeout(timer)
      window.removeEventListener('keydown', handleKeyDown)
    }
  }, [navigate])

  return (
    <main className="page splash-page">
      <div className="splash-sky" aria-hidden="true">
        <span className="splash-light-rays"></span>
        <img src={templeImage} alt="" className="splash-temple" />
        <span className="splash-star star-one"></span>
        <span className="splash-star star-two"></span>
        <span className="splash-star star-three"></span>
        <span className="splash-arc arc-one"></span>
        <span className="splash-arc arc-two"></span>
      </div>

      <div className="splash-content">
        <div className="logo-wrap">
          <img src={ytLogo} alt="YGT logo" className="splash-logo" />
        </div>
        <p className="splash-eyebrow">Welcome to</p>
        <h1 className="splash-title" aria-label="Your Good Time">
          <span className="title-line">Your Good Time</span>
          <span className="title-trace" aria-hidden="true"></span>
        </h1>
        <p className="splash-tagline">Astrology Guidance for Your Good Time</p>
        <p className="platform-line">Your Journey Written in the Stars</p>
        <div className="splash-loading" aria-hidden="true">
          <span></span>
        </div>
        <p className="splash-cta">Press Enter to Get In</p>
      </div>
    </main>
  )
}

export default SplashPage
