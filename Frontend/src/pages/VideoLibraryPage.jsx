import { useEffect, useMemo, useRef, useState } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import HeaderAuthActions from '../components/common/HeaderAuthActions'
import VideoModalPlayer from '../components/common/VideoModalPlayer'
import ygtLogo from '../assets/yt-logo.png'
import {
  copyTextToClipboard,
  galleryPathWithVideo,
  galleryVideoIdFromLocation,
  galleryVideoShareUrl,
  getGalleryVideos,
  recordGalleryVideoView,
  toggleGalleryVideoLike,
} from '../services/galleryVideoService'
import { scrollPageToTop } from '../utils/scrollPageToTop'
import './HomePage.css'
import './VideoLibraryPage.css'

function groupByCategory(items) {
  const groups = new Map()

  items.forEach((item) => {
    const category = item.category || 'General'
    if (!groups.has(category)) groups.set(category, [])
    groups.get(category).push(item)
  })

  return Array.from(groups, ([category, videos]) => ({ category, videos }))
}

function videoModalVariant(item) {
  return item?.videoType === 'SHORT' ? 'portrait' : 'landscape'
}

function categorySectionId(category) {
  return `gallery-category-${String(category || 'general').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')}`
}

function shortShareText(item) {
  const text = String(item.description || item.title || '').replace(/\s+/g, ' ').trim()
  if (text.length <= 160) return text
  return `${text.slice(0, 157).trimEnd()}...`
}

const trackedGalleryViews = new Set()

function VideoLibraryPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const { videoId: routeVideoId } = useParams()
  const [searchText, setSearchText] = useState('')
  const [selectedVideo, setSelectedVideo] = useState(null)
  const [galleryData, setGalleryData] = useState({ recent: [], home: [], shorts: [], videos: [] })
  const [galleryStatus, setGalleryStatus] = useState('loading')
  const [shareMessage, setShareMessage] = useState('')
  const [unavailableMessage, setUnavailableMessage] = useState('')
  const trackedOpenRef = useRef('')
  const pendingLikesRef = useRef(new Set())
  const isIntroGallery = new URLSearchParams(location.search).get('source') === 'intro'

  useEffect(() => {
    let isMounted = true

    setGalleryStatus('loading')
    getGalleryVideos({ recent_limit: 2, home_limit: 3 })
      .then((data) => {
        if (!isMounted) return
        setGalleryData(data)
        setGalleryStatus('ready')
      })
      .catch(() => {
        if (!isMounted) return
        setGalleryData({ recent: [], home: [], shorts: [], videos: [] })
        setGalleryStatus('error')
      })

    return () => {
      isMounted = false
    }
  }, [])

  const matchesSearch = (item, query) => {
    if (!query) return true

    return item.title.toLowerCase().includes(query)
      || item.description.toLowerCase().includes(query)
      || (item.category || '').toLowerCase().includes(query)
  }

  const filteredCollections = useMemo(() => {
    const query = searchText.trim().toLowerCase()

    return {
      recent: galleryData.recent.filter((item) => matchesSearch(item, query)),
      shorts: galleryData.shorts.filter((item) => matchesSearch(item, query)),
      videos: galleryData.videos.filter((item) => matchesSearch(item, query)),
    }
  }, [galleryData, searchText])

  const recentVideos = filteredCollections.recent
  const shortVideos = filteredCollections.shorts
  const normalVideos = filteredCollections.videos
  const introVideos = [...filteredCollections.shorts, ...filteredCollections.videos]
  const shortGroups = useMemo(() => groupByCategory(shortVideos), [shortVideos])
  const normalGroups = useMemo(() => groupByCategory(normalVideos), [normalVideos])
  const allLoadedVideos = useMemo(() => [...galleryData.shorts, ...galleryData.videos], [galleryData])

  const mergeVideoUpdate = (updatedVideo) => {
    if (!updatedVideo?.id) return

    const updateList = (items) => items.map((item) => (
      String(item.id) === String(updatedVideo.id) ? { ...item, ...updatedVideo } : item
    ))

    setGalleryData((current) => ({
      recent: updateList(current.recent),
      home: updateList(current.home),
      shorts: updateList(current.shorts),
      videos: updateList(current.videos),
    }))
    setSelectedVideo((current) => (
      current && String(current.id) === String(updatedVideo.id) ? { ...current, ...updatedVideo } : current
    ))
  }

  const showStatusMessage = (message) => {
    setShareMessage(message)
    window.setTimeout(() => setShareMessage(''), 1800)
  }

  const openVideo = (item) => {
    const nextPath = galleryPathWithVideo(location.search, item)
    if (`${location.pathname}${location.search}` === nextPath) return
    navigate(nextPath)
  }

  const closeVideoModal = () => {
    const nextPath = galleryPathWithVideo(location.search, null)
    if (`${location.pathname}${location.search}` === nextPath) {
      setSelectedVideo(null)
      return
    }
    navigate(nextPath, { replace: true })
  }

  useEffect(() => {
    const sharedVideoId = routeVideoId || galleryVideoIdFromLocation(location.pathname, location.search)
    if (!sharedVideoId) {
      setSelectedVideo(null)
      setUnavailableMessage('')
      trackedOpenRef.current = ''
      return
    }

    if (galleryStatus === 'loading') return

    const sharedVideo = allLoadedVideos.find((item) => String(item.id) === String(sharedVideoId))
    if (!sharedVideo) {
      setSelectedVideo(null)
      if (galleryStatus === 'ready') {
        setUnavailableMessage('This video is no longer available.')
      }
      return
    }

    setUnavailableMessage('')
    const variant = videoModalVariant(sharedVideo)
    setSelectedVideo((current) => (
      current && String(current.id) === String(sharedVideo.id)
        ? current
        : { ...sharedVideo, variant }
    ))

    const canonicalPath = galleryPathWithVideo(location.search, sharedVideo)
    if (`${location.pathname}${location.search}` !== canonicalPath) {
      navigate(canonicalPath, { replace: true })
      return
    }

    if (trackedOpenRef.current === String(sharedVideo.id) || trackedGalleryViews.has(String(sharedVideo.id))) {
      trackedOpenRef.current = String(sharedVideo.id)
      return
    }

    trackedOpenRef.current = String(sharedVideo.id)
    trackedGalleryViews.add(String(sharedVideo.id))
    recordGalleryVideoView(sharedVideo.id)
      .then(mergeVideoUpdate)
      .catch(() => {})
  }, [allLoadedVideos, galleryStatus, location.pathname, location.search, navigate, routeVideoId])

  const likeVideo = (item, event) => {
    event?.preventDefault()
    event?.stopPropagation()
    if (pendingLikesRef.current.has(item.id)) return

    pendingLikesRef.current.add(item.id)
    toggleGalleryVideoLike(item.id)
      .then(mergeVideoUpdate)
      .catch(() => {
        showStatusMessage('Unable to update like.')
      })
      .finally(() => {
        pendingLikesRef.current.delete(item.id)
      })
  }

  const shareVideo = async (item, event) => {
    event?.preventDefault()
    event?.stopPropagation()

    const shareData = {
      title: item.title,
      text: shortShareText(item),
      url: galleryVideoShareUrl(item),
    }

    try {
      if (navigator.share) {
        await navigator.share(shareData)
        return
      }

      const copied = await copyTextToClipboard(shareData.url)
      if (!copied) throw new Error('copy-failed')
      showStatusMessage('Link copied')
    } catch (error) {
      if (error?.name === 'AbortError') return
      showStatusMessage('Unable to share this video.')
    }
  }

  const scrollToSection = (sectionId) => {
    document.getElementById(sectionId)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  const renderVideoActions = (item, isShort = false) => (
    <div className={isShort ? 'video-engagement is-short' : 'video-engagement'}>
      <span className="video-engagement-stat" aria-label={`${item.viewCount} views`}>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c5 0 8.7 4.2 10 7-1.3 2.8-5 7-10 7s-8.7-4.2-10-7c1.3-2.8 5-7 10-7Zm0 10.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" /></svg>
        {item.viewCount}
      </span>
      <button
        type="button"
        className={`video-engagement-stat video-like-button ${item.likedByMe ? 'is-liked' : ''}`}
        onClick={(event) => likeVideo(item, event)}
        aria-label={item.likedByMe ? 'Unlike video' : 'Like video'}
        aria-pressed={item.likedByMe}
      >
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7.5-4.6-9.7-9A5.7 5.7 0 0 1 12 5.8 5.7 5.7 0 0 1 21.7 12C19.5 16.4 12 21 12 21Z" /></svg>
        {item.likeCount}
      </button>
      <button type="button" className="video-share-button" onClick={(event) => shareVideo(item, event)}>
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M15 8.5a2.5 2.5 0 1 0-2.3-3.5L8.2 7.4a2.5 2.5 0 1 0 0 9.2l4.5 2.4a2.5 2.5 0 1 0 .9-1.8L9.1 14.8a2.6 2.6 0 0 0 0-5.6l4.5-2.4c.4.2.9.3 1.4.3Z" />
        </svg>
        Share
      </button>
    </div>
  )

  const renderWideCard = (item, keyPrefix = 'video') => (
    <article key={`${keyPrefix}-${item.id}`} className="video-library-card">
      <button type="button" className="video-card-media" onClick={() => openVideo(item)}>
        <img src={item.thumbnail} alt={`${item.title} thumbnail`} className="video-card-thumb" />
        <span className="video-card-play" aria-hidden="true"></span>
      </button>
      <div className="video-card-body">
        <button type="button" className="video-card-copy" onClick={() => openVideo(item)}>
          <span className="video-card-title">{item.title}</span>
          {item.description ? <span className="video-card-description">{item.description}</span> : null}
        </button>
        {item.category ? <span className="video-card-category">{item.category}</span> : null}
        {renderVideoActions(item)}
      </div>
    </article>
  )

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

          <HeaderAuthActions />
        </header>
      )}

      {!isIntroGallery ? (
        <section className="video-library-title">
          <h2>Video Gallery</h2>
          <p>Watch guidance videos, temple insights, and daily spiritual updates.</p>
        </section>
      ) : null}

      <section className="video-library-search-wrap" aria-label="Search videos">
        <label className="video-library-search-field">
          <svg className="video-library-search-icon" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M10.5 4.5a6 6 0 1 1 0 12 6 6 0 0 1 0-12Zm9 15-4.2-4.2" />
          </svg>
          <input
            type="search"
            className="video-library-search"
            placeholder="Search videos..."
            value={searchText}
            onChange={(event) => setSearchText(event.target.value)}
          />
        </label>
      </section>

      {galleryStatus === 'loading' ? (
        <p className="video-library-empty">Loading videos...</p>
      ) : null}

      {galleryStatus === 'error' ? (
        <p className="video-library-empty">Unable to load videos right now.</p>
      ) : null}

      {unavailableMessage ? (
        <p className="video-library-unavailable" role="status">{unavailableMessage}</p>
      ) : null}

      {!isIntroGallery && recentVideos.length > 0 ? (
        <section className="video-recent-section" aria-label="Recent videos">
          <div className="video-section-heading video-recent-heading">
            <h3>Recent Videos</h3>
            <button type="button" className="video-section-view-all" onClick={() => scrollToSection('gallery-all-videos')}>
              View All
            </button>
          </div>

          <div className="video-recent-grid">
            {recentVideos.map((item) => renderWideCard(item, 'recent'))}
          </div>
        </section>
      ) : null}

      {!isIntroGallery && shortVideos.length > 0 ? (
        <section className="video-shorts-section" aria-label="Short videos">
          {shortGroups.map((group) => (
            <div className="video-category-group" key={`short-category-${group.category}`}>
              <div className="video-section-heading">
                <h3>{group.category}</h3>
              </div>
              <div className="video-shorts-row">
                {group.videos.map((item) => (
                  <article className="video-short-item" key={`short-${item.id}`}>
                    <button
                      type="button"
                      className="video-short-card"
                      onClick={() => openVideo(item)}
                    >
                      <img src={item.thumbnail} alt={`${item.title} short thumbnail`} />
                      <span className="video-short-play" aria-hidden="true"></span>
                      <span className="video-short-copy">
                        <strong>{item.title}</strong>
                      </span>
                    </button>
                    {renderVideoActions(item, true)}
                  </article>
                ))}
              </div>
            </div>
          ))}
        </section>
      ) : null}

      <section className="video-library-wide-wrap" id="gallery-all-videos" aria-label="Video library list">
        {isIntroGallery ? (
          <div className="video-library-grid">
            {introVideos.map((item) => renderWideCard(item, 'intro'))}
          </div>
        ) : (
          normalGroups.map((group) => (
            <div className="video-category-group" key={`wide-category-${group.category}`} id={categorySectionId(group.category)}>
              <div className="video-section-heading">
                <h3>{group.category}</h3>
                <button
                  type="button"
                  className="video-section-view-all"
                  onClick={() => scrollToSection(categorySectionId(group.category))}
                >
                  View All
                </button>
              </div>
              <div className="video-library-grid">
                {group.videos.map((item) => renderWideCard(item, 'wide'))}
              </div>
            </div>
          ))
        )}

        {galleryStatus === 'ready' && (isIntroGallery ? introVideos : normalVideos).length === 0 ? (
          <p className="video-library-empty">No videos found.</p>
        ) : null}
      </section>

      {!isIntroGallery ? (
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
      ) : null}

      <VideoModalPlayer
        video={selectedVideo}
        variant={selectedVideo?.variant}
        onClose={closeVideoModal}
      />

      {shareMessage ? <p className="video-share-toast" role="status">{shareMessage}</p> : null}

      {!isIntroGallery ? (
        <nav className="home-footer-nav" aria-label="Primary">
          <button type="button" className="footer-nav-item" onClick={() => { navigate('/home'); scrollPageToTop() }}>
            <span className="footer-nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z" /></svg>
            </span>
            <span className="footer-nav-label">Home</span>
          </button>

          <button type="button" className="footer-nav-item" onClick={() => { navigate('/account'); scrollPageToTop() }}>
            <span className="footer-nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M12 12a4.5 4.5 0 1 0-4.5-4.5A4.5 4.5 0 0 0 12 12m0 2c-4 0-7 2-7 4.5V20h14v-1.5C19 16 16 14 12 14" /></svg>
            </span>
            <span className="footer-nav-label">My Account</span>
          </button>

          <button type="button" className="footer-nav-item is-active" aria-current="page" onClick={scrollPageToTop}>
            <span className="footer-nav-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M4 6h5v5H4zm0 7h5v5H4zm7-7h9v5h-9zm0 7h9v5h-9z" /></svg>
            </span>
            <span className="footer-nav-label">Gallery</span>
          </button>

        </nav>
      ) : null}
    </main>
  )
}

export default VideoLibraryPage
