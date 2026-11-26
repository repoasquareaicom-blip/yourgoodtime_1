import { useEffect, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import HeaderAuthActions from '../components/common/HeaderAuthActions'
import PrimaryNav from '../components/common/PrimaryNav'
import TempleRemedyModal from '../components/common/TempleRemedyModal'
import TalkToAstrologerModal from '../components/common/TalkToAstrologerModal'
import ygtLogo from '../assets/yt-logo.png'
import futureIcon from '../assets/future.png'
import templeIcon from '../assets/temple.png'
import callIcon from '../assets/call.png'
import PastPredictionPage from './PastPredictionPage'
import { getUser, isLoggedIn } from '../utils/auth'
import { submitContactQuery } from '../services/contactQueryService'
import { scrollPageToTop } from '../utils/scrollPageToTop'
import './HomePage.css'

const TEMPLE_REMEDY_DRAFT_KEY = 'temple-remedy-draft'
const TALK_TO_ASTROLOGER_PENDING_KEY = 'talk-to-astrologer-pending'
const QUERY_ATTACHMENT_MAX_BYTES = 2 * 1024 * 1024

function HomePage() {
  const navigate = useNavigate()
  const location = useLocation()
  const [isPastPredictionOpen, setIsPastPredictionOpen] = useState(false)
  const [isFuturePurchaseOpen, setIsFuturePurchaseOpen] = useState(false)
  const [isTempleRemedyOpen, setIsTempleRemedyOpen] = useState(false)
  const [isTempleLoginPromptOpen, setIsTempleLoginPromptOpen] = useState(false)
  const [isTalkToAstrologerOpen, setIsTalkToAstrologerOpen] = useState(false)
  const [templeProblem, setTempleProblem] = useState('')
  const [shouldReturnToAccount, setShouldReturnToAccount] = useState(false)
  const [queryEmail, setQueryEmail] = useState(() => getUser()?.email || '')
  const [queryPhone, setQueryPhone] = useState('')
  const [queryMessage, setQueryMessage] = useState('')
  const [queryAttachment, setQueryAttachment] = useState(null)
  const [queryError, setQueryError] = useState('')
  const [queryProgress, setQueryProgress] = useState(0)
  const [isQuerySubmitting, setIsQuerySubmitting] = useState(false)
  const [isQueryThanks, setIsQueryThanks] = useState(false)
  const cameFromIntro = Boolean(location.state?.fromIntro)

  useEffect(() => {
    if (!isLoggedIn()) return

    try {
      const draft = window.localStorage.getItem(TEMPLE_REMEDY_DRAFT_KEY)

      if (draft) {
        setTempleProblem(draft)
        setIsTempleRemedyOpen(true)
        window.localStorage.removeItem(TEMPLE_REMEDY_DRAFT_KEY)
      }
      if (window.localStorage.getItem(TALK_TO_ASTROLOGER_PENDING_KEY)) {
        setIsTalkToAstrologerOpen(true)
        window.localStorage.removeItem(TALK_TO_ASTROLOGER_PENDING_KEY)
      }
    } catch {
      // Draft restore is useful but not required for submission.
    }
  }, [])

  useEffect(() => {
    const params = new URLSearchParams(location.search)
    const shouldOpenTempleRemedy = location.state?.openTempleRemedy || params.get('openTempleRemedy') === '1'
    const shouldOpenTalkToAstrologer = location.state?.openTalkToAstrologer || params.get('openTalkToAstrologer') === '1'
    const returnToAccount = location.state?.returnToAccount || params.get('returnToAccount') === '1'

    if (shouldOpenTempleRemedy) {
      setIsTempleRemedyOpen(true)
      setShouldReturnToAccount(Boolean(returnToAccount))
    }
    if (shouldOpenTalkToAstrologer) {
      setIsTalkToAstrologerOpen(true)
      setShouldReturnToAccount(Boolean(returnToAccount))
    }
  }, [location.search, location.state])
  const handleQuerySubmit = async (event) => {
    event.preventDefault()

    if (isQuerySubmitting) return

    const email = queryEmail.trim()
    const message = queryMessage.trim()

    if (!email) {
      setQueryError('Please enter your registered email ID.')
      return
    }

    if (!message || message.length < 8) {
      setQueryError('Please enter a little more detail in your query.')
      return
    }

    if (queryAttachment && queryAttachment.size > QUERY_ATTACHMENT_MAX_BYTES) {
      setQueryError('Attachment must be 2 MB or smaller.')
      return
    }

    setQueryError('')
    setIsQuerySubmitting(true)
    setQueryProgress(12)

    const payload = new FormData()
    payload.append('email', email)
    payload.append('phone', queryPhone.trim())
    payload.append('message', message)
    if (queryAttachment) {
      payload.append('attachment', queryAttachment)
    }

    const pulse = window.setInterval(() => {
      setQueryProgress((current) => (current >= 86 ? current : Math.min(86, current + 8)))
    }, 160)

    try {
      await submitContactQuery(payload, (event) => {
        if (!event.total) return
        setQueryProgress(Math.max(12, Math.min(88, Math.round((event.loaded / event.total) * 88))))
      })
      window.clearInterval(pulse)
      setQueryProgress(100)
      await new Promise((resolve) => window.setTimeout(resolve, 420))
      setIsQueryThanks(true)
      setQueryPhone('')
      setQueryMessage('')
      setQueryAttachment(null)
    } catch (error) {
      window.clearInterval(pulse)
      const firstError = error.response?.data?.errors
        ? Object.values(error.response.data.errors).flat()[0]
        : null
      setQueryError(firstError || error.response?.data?.message || 'Unable to send your query. Please try again.')
      setQueryProgress(0)
    } finally {
      window.clearInterval(pulse)
      setIsQuerySubmitting(false)
    }
  }

  const handleQueryAttachmentChange = (event) => {
    const file = event.target.files?.[0] || null

    if (file && file.size > QUERY_ATTACHMENT_MAX_BYTES) {
      setQueryAttachment(null)
      setQueryError('Attachment must be 2 MB or smaller.')
      event.target.value = ''
      return
    }

    setQueryError('')
    setQueryAttachment(file)
  }
  const openAccountLoading = () => {
    navigate('/account-loading?redirect=/account')
  }

  const handlePastClick = () => {
    if (isLoggedIn()) {
      openAccountLoading()
      return
    }

    setIsPastPredictionOpen(true)
  }

  const handleFutureClick = () => {
    if (isLoggedIn()) {
      openAccountLoading()
      return
    }

    setIsFuturePurchaseOpen(true)
  }

  const handleTempleRemedyClick = () => {
    if (!isLoggedIn()) {
      setIsTempleLoginPromptOpen(true)
      return
    }

    setIsTempleRemedyOpen(true)
  }

  const handleTalkToAstrologerClick = () => {
    setIsTalkToAstrologerOpen(true)
  }

  const handleTalkLoginProceed = () => {
    try {
      window.localStorage.setItem(TALK_TO_ASTROLOGER_PENDING_KEY, 'TALK_TO_ASTROLOGER')
    } catch {
      // Login redirect still resumes through the query string.
    }
    const redirectTo = '/home?openTalkToAstrologer=1&returnToAccount=1'
    navigate(`/login?redirect=${encodeURIComponent(redirectTo)}`, { state: { redirectTo } })
  }

  const handleTempleLoginProceed = () => {
    setIsTempleLoginPromptOpen(false)
    const redirectTo = '/home?openTempleRemedy=1&returnToAccount=1'
    navigate(`/login?redirect=${encodeURIComponent(redirectTo)}`, { state: { redirectTo } })
  }

  const closeTempleRemedy = () => {
    setIsTempleRemedyOpen(false)

    if (shouldReturnToAccount) {
      navigate('/account')
    }
  }

  const requireTempleLogin = (problemText) => {
    try {
      window.localStorage.setItem(TEMPLE_REMEDY_DRAFT_KEY, problemText)
    } catch {
      // Login can continue even when storage is unavailable.
    }
    setIsTempleRemedyOpen(false)
    setIsTempleLoginPromptOpen(true)
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

          <HeaderAuthActions />
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
              onClick={handlePastClick}
            >
              <span className="btn-shine"></span>
              Get Past Prediction
            </button>
          </div>
        </div>

        <div className="home-content-area">
          <div className="home-content-panel">
          <section className="home-quick-links" aria-label="Quick links">
            <article className="home-quick-link">
              <img src={futureIcon} alt="" />
              <span className="quick-link-copy">
                <strong>Know Your Future</strong>
                <small>Daily and yearly insights for your path</small>
              </span>
              <button type="button" className="quick-link-action" onClick={handleFutureClick}>Get In</button>
            </article>

            <article className="home-quick-link">
              <img src={templeIcon} alt="" />
              <span className="quick-link-copy">
                <strong>Temple Remedy</strong>
                <small>Explain your concern and request a guided remedy</small>
              </span>
              <button type="button" className="quick-link-action" onClick={handleTempleRemedyClick}>Get In</button>
            </article>

            <article className="home-quick-link">
              <img src={callIcon} alt="" />
              <span className="quick-link-copy">
                <strong>Talk to Astrologer</strong>
                <small>Connect directly for personal guidance</small>
              </span>
              <button type="button" className="quick-link-action" onClick={handleTalkToAstrologerClick}>Get In</button>
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
              <strong>Start with your birth details and follow a simple guided journey.</strong>
            </div>
            <div className="home-process-steps">
              <article>
                <span>01</span>
                <strong>Enter Details</strong>
                <p>Share your birth information securely.</p>
              </article>
              <article>
                <span>02</span>
                <strong>Receive Reading</strong>
                <p>Get your personalized past insight.</p>
              </article>
              <article>
                <span>03</span>
                <strong>Choose Next Step</strong>
                <p>Continue with future guidance, remedies, or consultation.</p>
              </article>
            </div>
          </section>

          <section className="home-info-section" aria-label="About Your Good Time">
            <article className="home-about-copy">
              <span>About Your Good Time</span>
              <strong>Guidance for people seeking clarity at important moments.</strong>
              <p>
                Your Good Time combines astrology-led insights, spiritual remedies, and personal consultation in one simple digital experience.
              </p>
            </article>

            <article className="home-do-list">
              <span>What we offer</span>
              <ul>
                <li>Personalized past and future prediction journeys</li>
                <li>Daily guidance for timing, focus and balance</li>
                <li>Temple remedies and astrologer consultation</li>
              </ul>
            </article>
          </section>

          <section className="home-faq-section" aria-label="Frequently asked questions">
            <div className="home-section-heading">
              <span>FAQ</span>
              <strong>Frequently Asked Questions</strong>
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
              {isQueryThanks ? (
                <div className="home-query-thanks" role="status">
                  <strong>Thank you</strong>
                  <p>We received your query and sent a confirmation email. Our team will get back to you soon.</p>
                  <button type="button" onClick={() => { setIsQueryThanks(false); setQueryProgress(0) }}>Send another query</button>
                </div>
              ) : (
                <form className="home-query-form" onSubmit={handleQuerySubmit}>
                  <input
                    type="email"
                    name="queryEmail"
                    value={queryEmail}
                    onChange={(event) => setQueryEmail(event.target.value)}
                    placeholder="Registered email ID"
                    aria-label="Registered email ID"
                    autoComplete="email"
                    required
                  />
                  <input
                    type="tel"
                    name="queryPhone"
                    value={queryPhone}
                    onChange={(event) => setQueryPhone(event.target.value)}
                    placeholder="Phone (optional)"
                    aria-label="Phone number, optional"
                    autoComplete="tel"
                  />
                  <textarea
                    name="queryMessage"
                    value={queryMessage}
                    onChange={(event) => setQueryMessage(event.target.value)}
                    placeholder="Your query"
                    aria-label="Your query"
                    required
                  />
                  <label className="home-query-file">
                    <input
                      type="file"
                      name="queryAttachment"
                      accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.txt,image/*,application/pdf"
                      onChange={handleQueryAttachmentChange}
                    />
                    <span>{queryAttachment ? queryAttachment.name : 'Attachment optional, max 2 MB'}</span>
                  </label>
                  {queryError ? <p className="home-query-error">{queryError}</p> : null}
                  {isQuerySubmitting ? (
                    <div className="home-query-progress" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={queryProgress} aria-label="Sending query">
                      <i style={{ width: `${queryProgress}%` }} />
                      <em>Sending your query...</em>
                    </div>
                  ) : null}
                  <button type="submit" disabled={isQuerySubmitting}>
                    {isQuerySubmitting ? 'Submitting...' : 'Submit Query'}
                  </button>
                </form>
              )}
            </section>

            <section className="home-contact-panel" aria-label="Contact Us">
              <div className="home-contact-heading">
                <strong>Contact Us</strong>
                <span>Sri Chakra Services</span>
              </div>
              <div className="home-contact-block">
                <span>Email</span>
                <a href="mailto:support@yourgoodtime.in">support@yourgoodtime.in</a>
              </div>
              <div className="home-contact-block">
                <span>Phone</span>
                <a href="tel:+917200222233">+91 7200 222233</a>
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

          </footer>
          </div>
        </div>
        <PrimaryNav />

        {isPastPredictionOpen ? (
          <PastPredictionPage forceModal onClose={() => setIsPastPredictionOpen(false)} />
        ) : null}

        {isFuturePurchaseOpen ? (
          <PastPredictionPage
            forceModal
            predictionIntent="future"
            onClose={() => setIsFuturePurchaseOpen(false)}
          />
        ) : null}

        {isTempleLoginPromptOpen ? (
          <section className="temple-remedy-overlay" role="dialog" aria-modal="true" aria-label="Temple Remedy login required">
            <div className="temple-remedy-panel temple-login-panel">
              <button
                type="button"
                className="temple-remedy-close"
                onClick={() => setIsTempleLoginPromptOpen(false)}
                aria-label="Close Temple Remedy login prompt"
              >
                x
              </button>
              <span className="temple-remedy-kicker">Login Required</span>
              <h2>Sign in to request a Temple Remedy</h2>
              <p>
                User login is required to continue. If you do not have an account, you can create one during login.
              </p>
              <p>
                Your birth details are required so we can understand your situation and prepare a suitable remedy solution.
              </p>
              <p>
                Depending on the review, you may need to pay a remedy preparation fee from your dashboard before the final remedy is prepared.
              </p>
              <div className="temple-remedy-actions">
                <button type="button" onClick={handleTempleLoginProceed}>OK Proceed</button>
              </div>
            </div>
          </section>
        ) : null}

        {isTempleRemedyOpen ? (
          <TempleRemedyModal
            initialProblem={templeProblem}
            isAuthenticated={isLoggedIn()}
            onClose={closeTempleRemedy}
            onRequireLogin={requireTempleLogin}
            onSubmitted={() => {
              setTempleProblem('')
              if (shouldReturnToAccount) {
                window.setTimeout(() => navigate('/account'), 900)
              }
            }}
          />
        ) : null}

        <TalkToAstrologerModal
          open={isTalkToAstrologerOpen}
          onClose={() => {
            setIsTalkToAstrologerOpen(false)
            if (shouldReturnToAccount) navigate('/account')
          }}
          onRequireLogin={handleTalkLoginProceed}
        />
      </section>
      <footer className="home-legal-footer" aria-label="Legal footer">
        <div className="home-legal-footer-inner">
          <nav className="home-legal-links" aria-label="Policies">
            <button type="button" onClick={() => { navigate('/privacy-policy'); scrollPageToTop() }}>Privacy Policy</button>
            <button type="button" onClick={() => { navigate('/terms-and-conditions'); scrollPageToTop() }}>Terms & Conditions</button>
            <button type="button" onClick={() => { navigate('/disclaimer'); scrollPageToTop() }}>Disclaimer</button>
            <button type="button" onClick={() => { navigate('/refund-policy'); scrollPageToTop() }}>Refund & Cancellation Policy</button>
          </nav>
          <p>Copyright {'\u00a9'} Your Good Time. All rights reserved.</p>
        </div>
      </footer>
    </main>
  )
}

export default HomePage

