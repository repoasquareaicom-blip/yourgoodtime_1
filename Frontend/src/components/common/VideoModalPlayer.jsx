import { useEffect, useRef } from 'react'

function VideoModalPlayer({ video, variant = 'landscape', onClose }) {
  const videoRef = useRef(null)
  const onCloseRef = useRef(onClose)
  const videoId = video?.id

  onCloseRef.current = onClose

  useEffect(() => {
    if (!videoId) return undefined

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    const handleKeyDown = (event) => {
      if (event.key !== 'Escape') return
      if (document.fullscreenElement) return
      onCloseRef.current()
    }

    document.addEventListener('keydown', handleKeyDown)
    window.setTimeout(() => {
      videoRef.current?.play().catch(() => {
        // Playback can be blocked by browser policy; controls remain available.
      })
    }, 0)

    return () => {
      const player = videoRef.current
      if (player) {
        player.pause()
        player.removeAttribute('src')
        player.load()
      }

      document.body.style.overflow = previousOverflow
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [videoId])

  if (!video) return null

  return (
    <section
      className="video-modal-backdrop"
      role="presentation"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <div
        className={`video-modal-panel ${variant === 'portrait' ? 'is-portrait' : ''}`}
        role="dialog"
        aria-modal="true"
        aria-labelledby="video-modal-title"
      >
        <header className="video-modal-header">
          <div className="video-modal-heading">
            <h2 id="video-modal-title">{video.title}</h2>
            {video.category ? <p>{video.category}</p> : null}
          </div>
          <button type="button" className="video-modal-close" onClick={onClose} aria-label="Close video player">
            ×
          </button>
        </header>
        <div className="video-modal-frame">
          <video
            ref={videoRef}
            key={videoId}
            src={video.videoSrc}
            controls
            playsInline
          />
        </div>
      </div>
    </section>
  )
}

export default VideoModalPlayer
