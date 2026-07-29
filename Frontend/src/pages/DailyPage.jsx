import { useLocation, useNavigate } from 'react-router-dom'
import ygtLogo from '../assets/yt-logo.png'
import thumb2 from '../assets/videos/2.jpg'
import thumb3 from '../assets/videos/3.jpg'
import thumb4 from '../assets/videos/4.jpg'
import { videoLibrary } from '../data/videoLibrary'
import './HomePage.css'
import './DailyPage.css'

function DailyPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const featuredVideo = videoLibrary[0]

  return (
    <main className="daily-page">
      <section className="home-hero-shell daily-shell">
        <header className="home-hero-header" aria-label="Daily page header">
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

        <section className="daily-content" aria-label="Daily content">
          <article className="daily-featured-story">
            <div>
              <h3>{featuredVideo.title}</h3>
              <p>{featuredVideo.description}</p>
            </div>
            <div className="daily-featured-thumb">
              <img src={featuredVideo.thumbnail} alt={`${featuredVideo.title} thumbnail`} />
              <span>Uploaded {featuredVideo.uploadedOn}</span>
            </div>
            <div className="daily-featured-stats" aria-label="views likes and shares">
              <span>
                <strong>{featuredVideo.views}</strong>
                Views
              </span>
              <span>
                <strong>{featuredVideo.likes}</strong>
                Likes
              </span>
              <span>
                <strong>{featuredVideo.shares}</strong>
                Shares
              </span>
            </div>
          </article>

          <article className="daily-newsletter">
            <p className="daily-tag">Daily Newsletter</p>
            <h2>Today's State: Calm Focus, Strong Decisions</h2>
            <p>
              A supportive day for clear communication, practical planning, and spiritual grounding.
              Avoid rushed decisions after sunset.
            </p>
          </article>

          <section className="daily-blog-grid" aria-label="Daily blogs">
            <article className="daily-blog-card">
              <h4>Daily Insights</h4>
              <p>Best time for key work: 10:30 AM - 12:15 PM</p>
            </article>
            <article className="daily-blog-card">
              <h4>Talks Updates</h4>
              <p>Consultation slots opened for tonight and tomorrow morning.</p>
            </article>
            <article className="daily-blog-card">
              <h4>Quick Remedy</h4>
              <p>Light a ghee lamp in the evening and chant for 3 minutes.</p>
            </article>
          </section>

          <section className="daily-gallery" aria-label="Daily gallery">
            <h3>Daily Gallery</h3>
            <div className="daily-gallery-grid">
              <img src={thumb2} alt="Daily gallery item 1" />
              <img src={thumb3} alt="Daily gallery item 2" />
              <img src={thumb4} alt="Daily gallery item 3" />
            </div>
          </section>
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

          <button type="button" className="footer-nav-item is-active" aria-current="page">
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

export default DailyPage
