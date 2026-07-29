import { useEffect, useMemo, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import ygtLogo from '../assets/yt-logo.png'
import { videoLibrary } from '../data/videoLibrary'
import './HomePage.css'
import './VideoLibraryPage.css'

function truncateText(text, maxLength = 90) {
  if (text.length <= maxLength) return text
  return `${text.slice(0, maxLength).trimEnd()}...`
}

function VideoLibraryPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const fullscreenVideoRef = useRef(null)
  const [searchText, setSearchText] = useState('')
  const isIntroGallery = new URLSearchParams(location.search).get('source') === 'intro'

  const filteredVideos = useMemo(() => {
    const query = searchText.trim().toLowerCase()
    if (!query) return videoLibrary

    return videoLibrary.filter((item) => {
      const titleMatch = item.title.toLowerCase().includes(query)
      const descriptionMatch = item.description.toLowerCase().includes(query)
      return titleMatch || descriptionMatch
    })
  }, [searchText])

  const shortVideos = filteredVideos.slice(0, 6)

  useEffect(() => {
    const handleFullscreenChange = () => {
      if (document.fullscreenElement) return

      const video = fullscreenVideoRef.current
      if (!video) return

      video.pause()
      video.removeAttribute('src')
      video.load()
    }

    document.addEventListener('fullscreenchange', handleFullscreenChange)

    return () => {
      document.removeEventListener('fullscreenchange', handleFullscreenChange)
    }
  }, [])

  const playFullscreen = async (videoSrc) => {
    const video = fullscreenVideoRef.current
    if (!video) return

    video.src = videoSrc

    try {
      await video.play()
    } catch {
      return
    }

    const fullscreenContainer = video.parentElement
    if (!fullscreenContainer?.requestFullscreen) return

    try {
      await fullscreenContainer.requestFullscreen()
    } catch {
      // Fullscreen can be blocked by browser/device settings.
    }
  }

  return (
    <main className={`video-library-page ${isIntroGallery ? 'is-intro-gallery' : 'is-app-gallery'}`}>
      {isIntroGallery ? (
        <section className="video-library-header">
          <button type="button" className="video-library-back" onClick={() => navigate('/intro')}>
            Back
          </button>
          <h1>Video Library</h1>
        </section>
      ) : (
        <header className="home-hero-header" aria-label="Gallery page header">
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
      )}

      {!isIntroGallery ? (
        <section className="video-library-title">
          <h2>Video Gallery</h2>
          <p>Watch guidance videos, temple insights, and daily spiritual updates.</p>
        </section>
      ) : null}

      <section className="video-library-search-wrap" aria-label="Search videos">
        <input
          type="search"
          className="video-library-search"
          placeholder="Search videos"
          value={searchText}
          onChange={(event) => setSearchText(event.target.value)}
        />
      </section>

      {!isIntroGallery && shortVideos.length > 0 ? (
        <section className="video-shorts-section" aria-label="Short videos">
          <div className="video-section-heading">
            <h3>Shorts</h3>
            <span>Swipe</span>
          </div>

          <div className="video-shorts-row">
            {shortVideos.map((item) => (
              <button
                type="button"
                key={`short-${item.id}`}
                className="video-short-card"
                onClick={() => playFullscreen(item.videoSrc)}
              >
                <img src={item.thumbnail} alt={`${item.title} short thumbnail`} />
                <span className="video-short-play" aria-hidden="true"></span>
                <span className="video-short-copy">
                  <strong>{item.title}</strong>
                  <small>{item.views} views</small>
                </span>
              </button>
            ))}
          </div>
        </section>
      ) : null}

      {!isIntroGallery ? (
        <section className="video-section-heading video-all-heading" aria-label="All videos heading">
          <h3>All Videos</h3>
        </section>
      ) : null}

      <section className="video-library-grid" aria-label="Video library list">
        {filteredVideos.map((item) => (
          <button
            type="button"
            key={item.id}
            className="video-library-card"
            onClick={() => playFullscreen(item.videoSrc)}
          >
            <img src={item.thumbnail} alt={`${item.title} thumbnail`} className="video-card-thumb" />

            <span className="video-card-title">{item.title}</span>
            <span className="video-card-description">{truncateText(item.description)}</span>

            <span className="video-card-stats" aria-label="views likes and shares">
              <span className="video-stat-item">
                <span className="video-stat-icon" aria-hidden="true">👁</span>
                <span>{item.views}</span>
              </span>
              <span className="video-stat-item">
                <span className="video-stat-icon" aria-hidden="true">❤</span>
                <span>{item.likes}</span>
              </span>
              <span className="video-stat-item">
                <span className="video-stat-icon" aria-hidden="true">↗</span>
                <span>{item.shares}</span>
              </span>
            </span>

            <span className="video-card-uploaded" aria-label="upload date">
              <span className="video-stat-icon" aria-hidden="true">🗓</span>
              <span>Uploaded {item.uploadedOn}</span>
            </span>
          </button>
        ))}

        {filteredVideos.length === 0 ? (
          <p className="video-library-empty">No videos found.</p>
        ) : null}
      </section>

      <div className="fullscreen-video-host" aria-hidden="true">
        <video ref={fullscreenVideoRef} controls playsInline />
      </div>

      {!isIntroGallery ? (
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

          <button type="button" className="footer-nav-item is-active" aria-current="page">
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
      ) : null}
    </main>
  )
}

export default VideoLibraryPage
