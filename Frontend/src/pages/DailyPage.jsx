import HeaderAuthActions from '../components/common/HeaderAuthActions'
import PrimaryNav from '../components/common/PrimaryNav'
import ygtLogo from '../assets/yt-logo.png'
import thumb2 from '../assets/videos/2.jpg'
import thumb3 from '../assets/videos/3.jpg'
import thumb4 from '../assets/videos/4.jpg'
import { videoLibrary } from '../data/videoLibrary'
import './HomePage.css'
import './DailyPage.css'

function DailyPage() {
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

          <HeaderAuthActions />
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

        <PrimaryNav />
      </section>
    </main>
  )
}

export default DailyPage
