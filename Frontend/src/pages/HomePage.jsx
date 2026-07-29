import { useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import ygtLogo from '../assets/yt-logo.png'
import futureIcon from '../assets/future.png'
import templeIcon from '../assets/temple.png'
import callIcon from '../assets/call.png'
import PastPredictionPage from './PastPredictionPage'
import './HomePage.css'

function HomePage() {
  const navigate = useNavigate()
  const location = useLocation()
  const [isPastPredictionOpen, setIsPastPredictionOpen] = useState(false)
  const cameFromIntro = Boolean(location.state?.fromIntro)
  const handleQuerySubmit = (event) => {
    event.preventDefault()
  }

  return (
    <main className={`main-home-page ${cameFromIntro ? 'is-intro-arrival' : ''}`}>
      <section className="home-hero-shell">
        <header className="home-hero-header">
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

        <div className="home-hero-title">
          <div className="hero-text-slider">
             <div className="hero-text-group group-1">
              <span>100% FREE</span>
              <strong>Know Your Past Journey</strong>
            </div>

            <div className="hero-text-group group-2">
              <span>Why Did Life Happen This Way?</span>
              <strong>Discover the Answers</strong>
            </div>

            <div className="hero-text-group group-3">
              <span>Your Birth Details Reveal</span>
              <strong>Your Hidden Life Story</strong>
            </div>

            <div className="hero-text-group group-4">
              <span>Experience the Accuracy</span>
              <strong>Before Seeing Your Future</strong>
            </div>

          </div>

          <p className="hero-subtitle">
            Start with your free personalized past prediction.
          </p>
        </div>

        <div className="hero-insight-card">
          <div className="hero-reading-core">
            <strong>Know Your Past Clearly</strong>

            <div className="hero-trust-glass" aria-label="Trust highlights">
              <p className="hero-trust-free">Absolutely Free</p>
              <p>100% Confidential</p>
              <p>Accurate Prediction</p>
            </div>

            <button
              type="button"
              className="past-prediction-btn"
              onClick={() => setIsPastPredictionOpen(true)}
            >
              <span className="btn-shine"></span>
              Get Past Prediction
            </button>
          </div>
        </div>

        <section className="home-quick-links" aria-label="Quick links">
          <article className="home-quick-link">
            <img src={futureIcon} alt="" />
            <span className="quick-link-copy">
              <strong>Know Your Future</strong>
              <small>Daily and yearly insights for your path</small>
            </span>
            <button type="button" className="quick-link-action">Get In</button>
          </article>

          <article className="home-quick-link">
            <img src={templeIcon} alt="" />
            <span className="quick-link-copy">
              <strong>Temple Remidy</strong>
              <small>Simple spiritual remedies for balance</small>
            </span>
            <button type="button" className="quick-link-action">Get In</button>
          </article>

          <article className="home-quick-link">
            <img src={callIcon} alt="" />
            <span className="quick-link-copy">
              <strong>Talk to Astrologer</strong>
              <small>Connect directly for personal guidance</small>
            </span>
            <button type="button" className="quick-link-action">Get In</button>
          </article>
        </section>

        <section className="home-concept-sections" aria-label="Astrology guidance">
          <article className="home-concept-card">
            <span>01</span>
            <strong>Personal Birth Insights</strong>
            <p>Understand the timing patterns behind your past, present, and upcoming decisions.</p>
          </article>
          <article className="home-concept-card">
            <span>02</span>
            <strong>Daily Direction</strong>
            <p>Receive simple guidance for focus, relationships, work, and spiritual balance.</p>
          </article>
          <article className="home-concept-card">
            <span>03</span>
            <strong>Remedies and Support</strong>
            <p>Explore practical temple remedies and connect with astrologers when you need clarity.</p>
          </article>
        </section>

        <section className="home-process-section" aria-label="How it works">
          <div className="home-process-copy">
            <span>How it works</span>
            <strong>Start with your birth details, then follow clear guidance.</strong>
          </div>
          <div className="home-process-steps">
            <article>
              <span>1</span>
              <strong>Enter details</strong>
              <p>Share your birth information securely.</p>
            </article>
            <article>
              <span>2</span>
              <strong>Receive reading</strong>
              <p>Get your free personalized past insight.</p>
            </article>
            <article>
              <span>3</span>
              <strong>Choose next step</strong>
              <p>Continue with future guidance, remedies, or consultation.</p>
            </article>
          </div>
        </section>

        <section className="home-info-section" aria-label="About Your Good Time">
          <article className="home-about-copy">
            <span>About us</span>
            <strong>Guidance for people who want clarity before they take the next step.</strong>
            <p>
              Your Good Time brings astrology-led insights, spiritual remedies, and personal consultation into a simple digital experience.
            </p>
          </article>

          <article className="home-do-list">
            <span>What we do</span>
            <ul>
              <li>Personalized past and future prediction journeys</li>
              <li>Daily guidance for timing, focus, and balance</li>
              <li>Temple remedy suggestions and astrologer support</li>
            </ul>
          </article>
        </section>

        <section className="home-faq-section" aria-label="Frequently asked questions">
          <div className="home-section-heading">
            <span>FAQ</span>
            <strong>Common questions</strong>
          </div>

          <div className="home-faq-list">
            <details>
              <summary>Is the past prediction free?</summary>
              <p>Yes, you can start with a free personalized past prediction.</p>
            </details>
            <details>
              <summary>Are my birth details private?</summary>
              <p>Your details are handled confidentially and used only to prepare your guidance.</p>
            </details>
            <details>
              <summary>Can I speak with an astrologer?</summary>
              <p>Yes, you can choose consultation options when you need deeper personal guidance.</p>
            </details>
          </div>
        </section>

        <footer className="home-content-footer" aria-label="Contact and support">
          <section className="home-query-panel">
            <div>
              <strong>Have a question?</strong>
              <span>Submit your query and our team will guide you.</span>
            </div>
            <form className="home-query-form" onSubmit={handleQuerySubmit}>
              <input type="text" name="queryName" placeholder="Name" aria-label="Name" />
              <input type="tel" name="queryPhone" placeholder="Phone" aria-label="Phone" />
              <textarea name="queryMessage" placeholder="Your query" aria-label="Your query"></textarea>
              <button type="submit">Submit Query</button>
            </form>
          </section>

          <section className="home-contact-panel">
            <div className="home-contact-block">
              <span>Email</span>
              <a href="mailto:support@yourgoodtime.com">support@yourgoodtime.com</a>
            </div>
            <div className="home-contact-block">
              <span>Phone</span>
              <a href="tel:+919043979797">+91 90439 79797</a>
            </div>
          </section>

          <section className="home-social-panel" aria-label="Social and support links">
            <a href="https://www.facebook.com" target="_blank" rel="noreferrer" aria-label="Facebook">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 8.5h2V5.2c-.4-.1-1.6-.2-3-.2-3 0-5 1.8-5 5.1V13H5v3.7h3V24h3.8v-7.3H15l.5-3.7h-3.7v-2.5c0-1.1.3-2 2.2-2" /></svg>
            </a>
            <a href="https://www.instagram.com" target="_blank" rel="noreferrer" aria-label="Instagram">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.5 2h9A5.5 5.5 0 0 1 22 7.5v9a5.5 5.5 0 0 1-5.5 5.5h-9A5.5 5.5 0 0 1 2 16.5v-9A5.5 5.5 0 0 1 7.5 2m0 2A3.5 3.5 0 0 0 4 7.5v9A3.5 3.5 0 0 0 7.5 20h9a3.5 3.5 0 0 0 3.5-3.5v-9A3.5 3.5 0 0 0 16.5 4zm4.5 3.5a4.5 4.5 0 1 1 0 9 4.5 4.5 0 0 1 0-9m0 2a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5m5-2.2a1.2 1.2 0 1 1 0 2.4 1.2 1.2 0 0 1 0-2.4" /></svg>
            </a>
            <a href="https://www.youtube.com" target="_blank" rel="noreferrer" aria-label="YouTube">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21.6 7.2s-.2-1.7-.9-2.4c-.9-.9-1.9-.9-2.4-1C15 3.5 12 3.5 12 3.5s-3 0-6.3.3c-.5.1-1.5.1-2.4 1-.7.7-.9 2.4-.9 2.4S2 9.2 2 11.2v1.7c0 2 .4 4 .4 4s.2 1.7.9 2.4c.9.9 2.1.9 2.6 1 1.9.2 6.1.3 6.1.3s3 0 6.3-.3c.5-.1 1.5-.1 2.4-1 .7-.7.9-2.4.9-2.4s.4-2 .4-4v-1.7c0-2-.4-4-.4-4M10 15.2V8.8l5.8 3.2z" /></svg>
            </a>
            <button type="button" aria-label="Open chat bot">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A3.5 3.5 0 0 1 7.5 2h9A3.5 3.5 0 0 1 20 5.5v7A3.5 3.5 0 0 1 16.5 16H10l-4.8 4.2A.7.7 0 0 1 4 19.7V16.2A3.5 3.5 0 0 1 1 12.8V5.5zM7.5 4A1.5 1.5 0 0 0 6 5.5v7A1.5 1.5 0 0 0 7.5 14h9a1.5 1.5 0 0 0 1.5-1.5v-7A1.5 1.5 0 0 0 16.5 4zm1.2 5.1a1.1 1.1 0 1 1 2.2 0 1.1 1.1 0 0 1-2.2 0m4.4 0a1.1 1.1 0 1 1 2.2 0 1.1 1.1 0 0 1-2.2 0" /></svg>
            </button>
          </section>

          <section className="home-legal-links" aria-label="Policies">
            <button type="button">Terms and Conditions</button>
            <button type="button">Privacy Policy</button>
            <button type="button">Refund Policy</button>
            <button type="button">Disclaimer</button>
          </section>
        </footer>

        <nav className="home-footer-nav" aria-label="Primary">
          <button type="button" className="footer-nav-item is-active" aria-current="page">
            <span className="footer-nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z" /></svg>
            </span>
            <span className="footer-nav-label">Home</span>
          </button>
          <button
            type="button"
            className="footer-nav-item"
            onClick={() => navigate('/account')}
          >
            <span className="footer-nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M12 12a4.5 4.5 0 1 0-4.5-4.5A4.5 4.5 0 0 0 12 12m0 2c-4 0-7 2-7 4.5V20h14v-1.5C19 16 16 14 12 14" /></svg>
            </span>
            <span className="footer-nav-label">My Account</span>
          </button>
          <button
            type="button"
            className="footer-nav-item"
            onClick={() => navigate('/daily')}
          >
            <span className="footer-nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M12 3a9 9 0 1 0 9 9 9 9 0 0 0-9-9m1 9.4V7h-2v6.2l4.7 2.8 1-1.7z" /></svg>
            </span>
            <span className="footer-nav-label">Daily</span>
          </button>
          <button
            type="button"
            className="footer-nav-item"
            onClick={() => navigate('/videos')}
          >
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

        {isPastPredictionOpen ? (
          <PastPredictionPage forceModal onClose={() => setIsPastPredictionOpen(false)} />
        ) : null}
      </section>
    </main>
  )
}

export default HomePage
