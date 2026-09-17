import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import introBg from '../assets/intro-bg.png'
import ytLogo from '../assets/yt-logo.png'
import introVideo from '../assets/videos/intro-2.mp4'
import VideoModalPlayer from '../components/common/VideoModalPlayer'
import { getGalleryVideos } from '../services/galleryVideoService'
import './IntroPage.css'
import './VideoLibraryPage.css'

const INTRO_DAILY_VIDEO_LIMIT = 4

function IntroPage() {
  const navigate = useNavigate()
  const galleryRef = useRef(null)
  const modalVideoRef = useRef(null)
  const loaderTimersRef = useRef([])
  const [isIntroLoading, setIsIntroLoading] = useState(true)
  const [introProgress, setIntroProgress] = useState(6)
  const [isVideoModalOpen, setIsVideoModalOpen] = useState(false)
  const [selectedGalleryVideo, setSelectedGalleryVideo] = useState(null)
  const [homeVideos, setHomeVideos] = useState([])
  const [galleryPage, setGalleryPage] = useState(0)
  const [isLeaving, setIsLeaving] = useState(false)
  const pageCount = useMemo(() => Math.ceil(homeVideos.length / INTRO_DAILY_VIDEO_LIMIT), [homeVideos.length])

  useEffect(() => {
    let isMounted = true

    const wait = (delay) =>
      new Promise((resolve) => {
        const timerId = window.setTimeout(resolve, delay)
        loaderTimersRef.current.push(timerId)
      })

    const preloadImage = (src) =>
      new Promise((resolve) => {
        const image = new Image()
        image.onload = resolve
        image.onerror = resolve
        image.src = src
      })

    const progressSteps = [
      [80, 22],
      [180, 48],
      [320, 74],
      [460, 92],
    ]

    progressSteps.forEach(([delay, progress]) => {
      const timerId = window.setTimeout(() => {
        if (isMounted) setIntroProgress(progress)
      }, delay)
      loaderTimersRef.current.push(timerId)
    })

    Promise.allSettled([preloadImage(introBg), wait(520)]).then(async () => {
      if (!isMounted) return
      setIntroProgress(100)
      await wait(120)
      if (isMounted) setIsIntroLoading(false)
    })

    return () => {
      isMounted = false
      loaderTimersRef.current.forEach((timerId) => window.clearTimeout(timerId))
      loaderTimersRef.current = []
    }
  }, [])

  useEffect(() => {
    let isMounted = true

    getGalleryVideos({ recent_limit: 2, home_limit: INTRO_DAILY_VIDEO_LIMIT })
      .then((data) => {
        if (isMounted) setHomeVideos(data.home)
      })
      .catch(() => {
        if (isMounted) setHomeVideos([])
      })

    return () => {
      isMounted = false
    }
  }, [])

  useEffect(() => {
    const gallery = galleryRef.current
    if (!gallery || pageCount <= 1) return undefined

    const updatePage = () => {
      const currentPage = Math.round(gallery.scrollLeft / gallery.clientWidth)
      setGalleryPage(Math.min(currentPage, pageCount - 1))
    }

    const interval = window.setInterval(() => {
      const currentPageWidth = gallery.clientWidth
      const nextLeft = gallery.scrollLeft + currentPageWidth
      const maxLeft = gallery.scrollWidth - gallery.clientWidth

      if (nextLeft >= maxLeft - 2) {
        gallery.scrollTo({ left: 0, behavior: 'smooth' })
        setGalleryPage(0)
      } else {
        gallery.scrollTo({ left: nextLeft, behavior: 'smooth' })
      }
    }, 2500)

    gallery.addEventListener('scroll', updatePage, { passive: true })

    return () => {
      window.clearInterval(interval)
      gallery.removeEventListener('scroll', updatePage)
    }
  }, [pageCount])

  useEffect(() => {
    if (!isVideoModalOpen) return undefined

    const handleKeyDown = (event) => {
      if (event.key === 'Escape') {
        setIsVideoModalOpen(false)
      }
    }

    window.addEventListener('keydown', handleKeyDown)

    return () => {
      window.removeEventListener('keydown', handleKeyDown)
    }
  }, [isVideoModalOpen])

  useEffect(() => {
    if (isVideoModalOpen) {
      modalVideoRef.current?.play().catch(() => {})
      return undefined
    }

    const modalVideo = modalVideoRef.current
    if (modalVideo) {
      modalVideo.pause()
      modalVideo.currentTime = 0
    }

    return undefined
  }, [isVideoModalOpen])

  const handleGoHome = () => {
    if (isLeaving) return

    setIsLeaving(true)
    window.setTimeout(() => {
      navigate('/home', { replace: true, state: { fromIntro: true } })
    }, 360)
  }

  const handlePlayVideo = () => {
    setIsVideoModalOpen(true)
  }

  const handleViewAllVideos = () => {
    navigate('/videos')
  }

  return (
    <main className={`home-page ${isLeaving ? 'is-leaving' : ''}`}>
      <section className="hero-video-wrap">
        <button type="button" className="skip-intro" onClick={handleGoHome} disabled={isLeaving}>
          Skip Intro
        </button>

        <div className="hero-video-stage">
          <video
            className="hero-video"
            src={introVideo}
            preload="metadata"
            muted
            playsInline
          />

          <>
            <button type="button" className="video-play-button" onClick={handlePlayVideo}>
              <span className="video-play-icon" aria-hidden="true">
                ▶
              </span>
              <span className="video-play-text">Play</span>
            </button>
          </>
        </div>
      </section>

      {homeVideos.length > 0 ? (
        <section className="gallery-strip" aria-label="Video gallery">
          <header className="video-library-top">
            <h2>Daily Videos</h2>
            <button type="button" className="video-library-view-all" onClick={handleViewAllVideos}>
              View all
            </button>
          </header>

          <div className="gallery-track" ref={galleryRef} role="list">
            {homeVideos.map((item, index) => (
              <button
                key={item.id}
                type="button"
                className="gallery-item"
                role="listitem"
                onClick={() => setSelectedGalleryVideo({ ...item, variant: item.videoType === 'SHORT' ? 'portrait' : 'landscape' })}
              >
                <img src={item.thumbnail} alt={`${item.title || `Gallery item ${index + 1}`} thumbnail`} className="gallery-image" />
              </button>
            ))}
          </div>

          {pageCount > 1 ? (
            <div className="gallery-pagination" aria-label="Gallery pagination">
              {Array.from({ length: pageCount }, (_, index) => (
                <span key={index} className={`gallery-page ${index === galleryPage ? 'is-active' : ''}`} aria-hidden="true" />
              ))}
            </div>
          ) : null}
        </section>
      ) : null}

      <section className="home-bottom">
        <section className="past-text-wrap" aria-label="Past prediction text">
          <div className="past-text-copy">
            <h3>FREE Past Prediction</h3>
            <p>உங்கள் பிறந்த விபரங்களின் அடிப்படையில் துல்லியமான வீடியோ பதிவு</p>
            <p className="bold">உங்கள் கடந்த காலத்தை அறிந்து கொள்ளுங்கள்.</p>
          </div>
        </section>

        <button className="journey-button" type="button" onClick={handleGoHome} disabled={isLeaving}>
          <span className="btn-icon">▶</span>
          <span>Begin My Journey</span>
          <span className="btn-arrow">›</span>
        </button>

        <section className="trust-row" aria-label="Trust points">
          <div className="trust-item">
            <span className="trust-icon" aria-hidden="true">🛡</span>
            <p>100% Confidential</p>
          </div>
          <div className="trust-item">
            <span className="trust-icon" aria-hidden="true">🔒</span>
            <p>Secure &amp; Safe</p>
          </div>
          <div className="trust-item">
            <span className="trust-icon" aria-hidden="true">🔔</span>
            <p>Daily Updates</p>
          </div>
        </section>
      </section>

      {isVideoModalOpen ? (
        <div className="intro-video-modal" role="dialog" aria-modal="true" aria-label="Intro video">
          <button
            type="button"
            className="intro-video-backdrop"
            aria-label="Close video"
            onClick={() => setIsVideoModalOpen(false)}
          />

          <section className="intro-video-dialog">
            <button
              type="button"
              className="intro-video-close"
              aria-label="Close video"
              onClick={() => setIsVideoModalOpen(false)}
            >
              x
            </button>
            <video
              ref={modalVideoRef}
              className="intro-modal-video"
              src={introVideo}
              preload="metadata"
              controls
              autoPlay
              playsInline
            />
          </section>
        </div>
      ) : null}

      <VideoModalPlayer
        video={selectedGalleryVideo}
        variant={selectedGalleryVideo?.variant}
        onClose={() => setSelectedGalleryVideo(null)}
      />

      {isIntroLoading ? (
        <div className="intro-loading-overlay" role="status" aria-live="polite">
          <div className="intro-loading-card">
            <div className="intro-loading-mark">
              <span className="intro-loading-ring"></span>
              <img src={ytLogo} alt="" />
            </div>
            <p className="intro-loading-kicker">Loading Your Good Time</p>
            <div className="intro-progress-track" aria-label="Loading intro page">
              <span style={{ width: `${introProgress}%` }}></span>
            </div>
            <strong>{introProgress}%</strong>
          </div>
        </div>
      ) : null}
    </main>
  )
}

export default IntroPage
