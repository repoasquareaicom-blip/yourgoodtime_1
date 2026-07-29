import { useEffect, useMemo, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import ygtLogo from '../assets/yt-logo.png'
import predictionBirthVideo from '../assets/videos/Content/intro-1.mp4'
import predictionVideo2016 from '../assets/videos/Content/00001.mp4'
import predictionVideo2020 from '../assets/videos/Content/00002.mp4'
import './HomePage.css'
import './AccountPage.css'

const mainActions = [
  {
    title: 'Past Prediction',
    copy: 'Start or view your free past prediction video.',
    icon: '🎥',
    tone: 'orange',
    action: 'Open',
    path: '/past-prediction',
  },
  {
    title: 'Future Prediction',
    copy: 'Paid guidance for coming years and important decisions.',
    icon: '🔮',
    tone: 'purple',
    action: 'Paid',
    path: '/login?redirect=/account',
  },
  {
    title: 'Book Appointment',
    copy: 'Talk directly with the astrologer for personal guidance.',
    icon: '📅',
    tone: 'blue',
    action: 'Book',
    path: '/login?redirect=/account',
  },
  {
    title: 'Parikaram',
    copy: 'Get remedy and spiritual solutions based on your reading.',
    icon: '🪔',
    tone: 'green',
    action: 'Paid',
    path: '/login?redirect=/account',
  },
  {
    title: 'Temple Guidance',
    copy: 'Know the right temple, day, and worship guidance.',
    icon: '🛕',
    tone: 'gold',
    action: 'View',
    path: '/login?redirect=/account',
  },
  {
    title: 'Video Library',
    copy: 'Watch astrology videos, daily updates, and guidance.',
    icon: '🎞️',
    tone: 'rose',
    action: 'Open',
    path: '/videos',
  },
]

const predictionHistory = [
  {
    title: 'Past Prediction Video',
    yearRange: '2016 - 2025',
    status: 'Ready to Play',
    generatedOn: '03 Jul 2026',
    sortDate: '2025-07-03',
  },
  {
    title: 'Future Prediction Video',
    yearRange: '2026 - 2027',
    status: 'Paid Video',
    generatedOn: '03 Jul 2026',
    sortDate: '2026-01-01',
  },
].sort((a, b) => new Date(b.sortDate) - new Date(a.sortDate))

const predictionDemoSequence = [
  {
    type: 'slide',
    label: 'Your Birth Detail',
    caption: 'Preparing your personalized chart',
  },
  {
    type: 'video',
    src: predictionBirthVideo,
    label: 'Birth Detail Reading',
  },
  {
    type: 'slide',
    label: 'Year 2016 - 2019',
    caption: 'Your past phase is loading',
  },
  {
    type: 'video',
    src: predictionVideo2016,
    label: '2016 - 2019 Reading',
  },
  {
    type: 'slide',
    label: 'Year 2020 to 2025',
    caption: 'Next life phase is loading',
  },
  {
    type: 'video',
    src: predictionVideo2020,
    label: '2020 - 2025 Reading',
  },
]

const PREDICTION_SLIDE_SECONDS = 5

const upcomingAppointment = {
  title: 'Astrologer Video Call',
  date: '06 Jul 2026',
  time: '07:30 PM',
  bookedOn: '03 Jul 2026',
  status: 'Upcoming',
}

function getStoredPrediction() {
  try {
    return JSON.parse(window.localStorage.getItem('ygt-past-prediction') || 'null')
  } catch {
    return null
  }
}

function formatRequestedDate(value) {
  if (!value) return 'Not requested yet'

  return new Intl.DateTimeFormat('en-IN', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  }).format(new Date(value))
}

function formatProfileDate(value) {
  if (!value) return 'Not added'

  return new Intl.DateTimeFormat('en-IN', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  }).format(new Date(value))
}

function AccountPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const playerVideoRef = useRef(null)
  const [isLoggedIn, setIsLoggedIn] = useState(false)
  const [userEmail, setUserEmail] = useState('')
  const [userName, setUserName] = useState('')
  const [prediction, setPrediction] = useState(null)
  const [isPredictionPlayerOpen, setIsPredictionPlayerOpen] = useState(false)
  const [sequenceIndex, setSequenceIndex] = useState(0)
  const [slideSecondsLeft, setSlideSecondsLeft] = useState(PREDICTION_SLIDE_SECONDS)

  useEffect(() => {
    setIsLoggedIn(window.localStorage.getItem('ygt-auth') === '1')
    setUserEmail(window.localStorage.getItem('ygt-user-email') || '')
    setUserName(window.localStorage.getItem('ygt-user-name') || '')
    setPrediction(getStoredPrediction())
  }, [])

  const displayName = useMemo(() => {
    if (userName) return userName
    if (prediction?.fullName) return prediction.fullName
    if (userEmail) return userEmail.split('@')[0]
    return 'Guest'
  }, [prediction, userEmail, userName])

  const activeSegment = predictionDemoSequence[sequenceIndex]

  useEffect(() => {
    if (!isPredictionPlayerOpen || activeSegment?.type !== 'slide') return undefined

    setSlideSecondsLeft(PREDICTION_SLIDE_SECONDS)

    const countdownTimer = window.setInterval(() => {
      setSlideSecondsLeft((secondsLeft) => Math.max(secondsLeft - 1, 1))
    }, 1000)

    const slideTimer = window.setTimeout(() => {
      setSequenceIndex((currentIndex) => Math.min(currentIndex + 1, predictionDemoSequence.length - 1))
    }, PREDICTION_SLIDE_SECONDS * 1000)

    return () => {
      window.clearInterval(countdownTimer)
      window.clearTimeout(slideTimer)
    }
  }, [activeSegment, isPredictionPlayerOpen])

  useEffect(() => {
    if (!isPredictionPlayerOpen || activeSegment?.type !== 'video') return

    const video = playerVideoRef.current
    if (!video) return

    video.muted = false
    video.volume = 1
    video.load()
    video.play().catch(() => {})
  }, [activeSegment, isPredictionPlayerOpen])

  const handleAuthAction = () => {
    if (isLoggedIn) {
      window.localStorage.removeItem('ygt-auth')
      window.localStorage.removeItem('ygt-user-email')
      window.localStorage.removeItem('ygt-user-name')
      setIsLoggedIn(false)
      setUserEmail('')
      setUserName('')
      return
    }

    navigate('/login?redirect=/account', { state: { backgroundLocation: location } })
  }

  const handleOpenPredictionPlayer = () => {
    setSequenceIndex(0)
    setIsPredictionPlayerOpen(true)
  }

  const handleClosePredictionPlayer = () => {
    const video = playerVideoRef.current
    if (video) video.pause()
    setIsPredictionPlayerOpen(false)
  }

  const handleSegmentEnded = () => {
    setSequenceIndex((currentIndex) => {
      if (currentIndex >= predictionDemoSequence.length - 1) return 0
      return currentIndex + 1
    })
  }

  return (
    <main className="account-page">
      <section className="account-shell">
        <header className="home-hero-header account-header" aria-label="Account page header">
          <div className="home-hero-brand">
            <img src={ygtLogo} alt="YGT" className="home-hero-logo" />
            <div className="home-hero-brand-copy">
              <h1>Your Good Time</h1>
              <p>Your Journey Written in the Stars</p>
            </div>
          </div>

          <button type="button" className="home-hero-login account-login-btn" onClick={handleAuthAction}>
            {isLoggedIn ? 'Logout' : 'Login'}
          </button>
        </header>

        <section className="account-dashboard" aria-label="My Account dashboard">
          <article className="account-hero-card">
            <div className="account-orbit account-orbit-one"></div>
            <div className="account-orbit account-orbit-two"></div>

            <div className="account-hero-top">
              <div>
                <span className="account-eyebrow">My Account</span>
                <h2>Hi, {displayName}</h2>
                <p>{isLoggedIn ? userEmail || 'Logged in with OTP' : 'Login to sync your predictions and updates.'}</p>
              </div>
              <div className="account-avatar" aria-hidden="true">
                {displayName.charAt(0).toUpperCase()}
              </div>
            </div>

            <div className="account-profile-grid">
              <div>
                <span>DOB</span>
                <strong>{formatProfileDate(prediction?.dateOfBirth)}</strong>
              </div>
              <div>
                <span>Time</span>
                <strong>{prediction?.timeOfBirth || 'Not added'}</strong>
              </div>
              <div>
                <span>Place</span>
                <strong>{prediction?.placeOfBirth || 'Not added'}</strong>
              </div>
            </div>
          </article>

          <section className="account-section-card" aria-label="Prediction history">
            <div className="account-section-title inline-title">
              <div>
                <span className="account-eyebrow">History</span>
                <h3>Prediction History</h3>
              </div>
              <button type="button" onClick={() => navigate('/videos')}>Library</button>
            </div>

            <div className="prediction-history-grid">
              {predictionHistory.map((item) => (
                <button
                  type="button"
                  className="prediction-video-card"
                  key={`${item.title}-${item.yearRange}`}
                  onClick={handleOpenPredictionPlayer}
                >
                  <div className="prediction-thumb">
                    {item.thumbnail ? (
                      <img src={item.thumbnail} alt="" className="prediction-thumb-image" />
                    ) : null}
                    <span className="prediction-year-range">{item.yearRange}</span>
                    <span className="prediction-play" aria-hidden="true">â–¶</span>
                  </div>
                  <div className="prediction-video-copy">
                    <strong>{item.title}</strong>
                    <p>Generated on {item.generatedOn}</p>
                    <em>{item.status}</em>
                  </div>
                </button>
              ))}
            </div>
          </section>

          <section className="account-section-card main-actions-section" aria-label="Main actions">
            <div className="account-section-title">
              <div>
                <span className="account-eyebrow">Main Actions</span>
                <h3>Choose Your Guidance</h3>
              </div>
            </div>

            <div className="quick-action-grid">
              {mainActions.map((item) => (
                <button
                  type="button"
                  className={`quick-action-card is-${item.tone}`}
                  key={item.title}
                  onClick={() => navigate(item.path)}
                >
                  <span className="quick-action-icon">{item.icon}</span>
                  <strong>{item.title}</strong>
                  <p>{item.copy}</p>
                  <em>{item.action}</em>
                </button>
              ))}
            </div>
          </section>

          {false ? (
          <section className="account-section-card" aria-label="Prediction history">
            <div className="account-section-title inline-title">
              <div>
                <span className="account-eyebrow">History</span>
                <h3>Prediction History</h3>
              </div>
              <button type="button" onClick={() => navigate('/videos')}>Library</button>
            </div>

            <div className="prediction-history-grid">
              {predictionHistory.map((item) => (
                <button
                  type="button"
                  className="prediction-video-card"
                  key={`${item.title}-${item.yearRange}`}
                  onClick={handleOpenPredictionPlayer}
                >
                  <div className="prediction-thumb">
                    {item.thumbnail ? (
                      <img src={item.thumbnail} alt="" className="prediction-thumb-image" />
                    ) : null}
                    <span className="prediction-year-range">{item.yearRange}</span>
                    <span className="prediction-play" aria-hidden="true">▶</span>
                  </div>
                  <div className="prediction-video-copy">
                    <strong>{item.title}</strong>
                    <p>Generated on {item.generatedOn}</p>
                    <em>{item.status}</em>
                  </div>
                </button>
              ))}
            </div>
          </section>
          ) : null}

          {isPredictionPlayerOpen ? (
            <section
              className="prediction-player-overlay"
              role="dialog"
              aria-modal="true"
              aria-label="Prediction video player"
              onClick={handleClosePredictionPlayer}
            >
              <div className="prediction-player-modal" onClick={(event) => event.stopPropagation()}>
                <img src={ygtLogo} alt="" className="prediction-player-watermark" />

                <button
                  type="button"
                  className="prediction-player-close"
                  aria-label="Close prediction player"
                  onClick={handleClosePredictionPlayer}
                >
                  x
                </button>

                <div className="prediction-player-header">
                  <span>Past Prediction</span>
                  <strong>{activeSegment?.label}</strong>
                </div>

                <div className="prediction-player-stage">
                  {activeSegment?.type === 'slide' ? (
                    <article className="prediction-animated-slide">
                      <span className="prediction-slide-orbit"></span>
                      <span className="prediction-slide-orbit orbit-two"></span>
                      <div
                        className="prediction-slide-countdown"
                        style={{ '--count-progress': `${(slideSecondsLeft / PREDICTION_SLIDE_SECONDS) * 100}%` }}
                        aria-label={`Video starts in ${slideSecondsLeft} seconds`}
                      >
                        <strong>{slideSecondsLeft}</strong>
                        <span>sec</span>
                      </div>
                      <p>Personalized Reading</p>
                      <h2>{activeSegment.label}</h2>
                      <small>{activeSegment.caption}</small>
                    </article>
                  ) : (
                    <video
                      ref={playerVideoRef}
                      className="prediction-sequence-video"
                      src={activeSegment?.src}
                      controls
                      autoPlay
                      playsInline
                      onEnded={handleSegmentEnded}
                    />
                  )}
                </div>

                <div className="prediction-player-progress" aria-label="Prediction video progress">
                  {predictionDemoSequence.map((segment, index) => (
                    <span
                      key={`${segment.type}-${segment.label}-${index}`}
                      className={index <= sequenceIndex ? 'is-active' : ''}
                    />
                  ))}
                </div>
              </div>
            </section>
          ) : null}

          <section className="account-section-card appointment-detail-card" aria-label="Upcoming appointment details">
            <div className="account-section-title">
              <div>
                <span className="account-eyebrow">Appointment</span>
                <h3>Upcoming Appointment</h3>
              </div>
            </div>

            <div className="appointment-detail-row">
              <div className="appointment-call-thumb" aria-hidden="true">
                <svg viewBox="0 0 24 24">
                  <path d="M7.2 5.5c.35-.35.9-.42 1.34-.17l2.04 1.16c.5.28.72.88.53 1.42l-.62 1.76a10.2 10.2 0 0 0 3.84 3.84l1.76-.62c.54-.19 1.14.03 1.42.53l1.16 2.04c.25.44.18.99-.17 1.34l-.93.93c-.57.57-1.4.8-2.2.58C10.3 16.98 7.02 13.7 5.69 8.64c-.22-.8.01-1.63.58-2.2z" />
                </svg>
              </div>

              <div className="appointment-main-copy">
                <strong>{upcomingAppointment.title}</strong>
                <p>{upcomingAppointment.date} • {upcomingAppointment.time}</p>
                <small>Booked on {upcomingAppointment.bookedOn}</small>
              </div>

              <em>{upcomingAppointment.status}</em>
            </div>

            <button type="button" className="account-primary-btn appointment-action-btn" onClick={() => navigate('/login?redirect=/account')}>
              View Appointment
            </button>
          </section>

          <section className="account-mini-grid">
            <article className="mini-info-card temple-card">
              <span>🛕</span>
              <h3>Temple Guidance</h3>
              <p>Parikaram and temple details will appear here after purchase.</p>
              <button type="button" onClick={() => navigate('/daily')}>Daily Updates</button>
            </article>

            <article className="mini-info-card appointment-card">
              <span>📅</span>
              <h3>Book New Appointment</h3>
              <p>Choose a preferred date and time to speak with the astrologer.</p>
              <button type="button" onClick={() => navigate('/login?redirect=/account')}>Book Now</button>
            </article>
          </section>
        </section>

        <nav className="home-footer-nav" aria-label="Primary">
          <button type="button" className="footer-nav-item" onClick={() => navigate('/home')}>
            <span className="footer-nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z" /></svg>
            </span>
            <span className="footer-nav-label">Home</span>
          </button>

          <button type="button" className="footer-nav-item is-active" aria-current="page">
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

          <button type="button" className="footer-nav-item" onClick={() => navigate('/settings')}>
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

export default AccountPage
