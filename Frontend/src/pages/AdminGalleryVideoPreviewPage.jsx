import { useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import VideoModalPlayer from '../components/common/VideoModalPlayer'
import { fetchAdminGalleryVideo } from '../services/adminService'
import './HomePage.css'
import './VideoLibraryPage.css'

function AdminGalleryVideoPreviewPage() {
  const { id } = useParams()
  const [video, setVideo] = useState(null)
  const [status, setStatus] = useState('loading')
  const [selectedVideo, setSelectedVideo] = useState(null)

  useEffect(() => {
    let isMounted = true

    setStatus('loading')
    fetchAdminGalleryVideo(id)
      .then((data) => {
        if (!isMounted) return
        setVideo({
          id: data.id,
          title: data.title || '',
          description: data.description || '',
          category: data.category || 'General',
          videoType: data.video_type,
          thumbnail: data.thumbnail_url,
          videoSrc: data.video_url,
          variant: data.video_type === 'SHORT' ? 'portrait' : 'landscape',
          publicationStatus: data.publication_status,
          isActive: Boolean(data.is_active),
        })
        setStatus('ready')
      })
      .catch(() => {
        if (isMounted) setStatus('error')
      })

    return () => {
      isMounted = false
    }
  }, [id])

  const isShort = video?.videoType === 'SHORT'
  const sectionTitle = isShort ? 'Shorts' : 'Wide Videos'
  const card = useMemo(() => {
    if (!video) return null

    if (isShort) {
      return (
        <button
          type="button"
          className="video-short-card"
          onClick={() => setSelectedVideo(video)}
        >
          <img src={video.thumbnail} alt={`${video.title} short thumbnail`} />
          <span className="video-short-play" aria-hidden="true"></span>
          <span className="video-short-copy">
            <strong>{video.title}</strong>
          </span>
        </button>
      )
    }

    return (
      <article className="video-library-card">
        <button type="button" className="video-card-media" onClick={() => setSelectedVideo(video)}>
          <img src={video.thumbnail} alt={`${video.title} thumbnail`} className="video-card-thumb" />
          <span className="video-card-play" aria-hidden="true"></span>
        </button>
        <div className="video-card-body">
          <button type="button" className="video-card-copy" onClick={() => setSelectedVideo(video)}>
            <span className="video-card-title">{video.title}</span>
            {video.description ? <span className="video-card-description">{video.description}</span> : null}
          </button>
          {video.category ? <span className="video-card-category">{video.category}</span> : null}
        </div>
      </article>
    )
  }, [isShort, video])

  return (
    <main className="video-library-page is-app-gallery admin-gallery-preview-page">
      <section className="video-library-title">
        <h2>Video Gallery Preview</h2>
        <p>Admin-only preview of how this {isShort ? 'short' : 'wide video'} will appear before publishing.</p>
      </section>

      <div className="admin-gallery-preview-actions">
        <Link to="/admin">Back to Admin</Link>
        {video ? (
          <span>{video.publicationStatus === 'PUBLISHED' ? 'Published' : 'Draft'} / {video.isActive ? 'Visible' : 'Hidden'}</span>
        ) : null}
      </div>

      {status === 'loading' ? <p className="video-library-empty">Loading preview...</p> : null}
      {status === 'error' ? <p className="video-library-empty">Unable to load preview.</p> : null}

      {video ? (
        <>
          <section className="video-section-heading video-all-heading" aria-label={`${sectionTitle} preview heading`}>
            <h3>{sectionTitle}</h3>
          </section>

          <section className={isShort ? 'video-shorts-section' : 'video-library-wide-wrap'} aria-label="Video preview">
            <div className="video-category-group">
              <h4>{video.category}</h4>
              {isShort ? (
                <div className="video-shorts-row">{card}</div>
              ) : (
                <div className="video-library-grid">{card}</div>
              )}
            </div>
          </section>
        </>
      ) : null}

      <VideoModalPlayer
        video={selectedVideo}
        variant={selectedVideo?.variant}
        onClose={() => setSelectedVideo(null)}
      />
    </main>
  )
}

export default AdminGalleryVideoPreviewPage
