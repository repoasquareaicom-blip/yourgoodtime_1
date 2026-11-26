import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import PredictionCountdown from './PredictionCountdown'
import { getLanguageByCode, getStoredLanguage, LANGUAGE_CHANGE_EVENT } from '../../data/languageOptions'
import { submitPredictionFeedback } from '../../services/feedbackService'
import { fetchProtectedVideoPlaybackUrl } from '../../services/predictionResultService'
import { isCurtainVideo } from '../../utils/curtainVideo'
import './PredictionPlayer.css'

const playerStates = {
  MULTI_VIDEO_NOTICE: 'MULTI_VIDEO_NOTICE',
  COUNTDOWN: 'COUNTDOWN',
  PLAYING: 'PLAYING',
  COMPLETED: 'COMPLETED',
  ERROR: 'ERROR',
}

const videoDebugEnabled = import.meta.env.VITE_VIDEO_DEBUG === 'true'
const temporarilyUnavailableMessage = 'This video is temporarily unavailable. Please try again later.'

function entryStateForGroup(group) {
  return group?.type === 'PREDICTION' && group?.videos?.length > 1
    ? playerStates.MULTI_VIDEO_NOTICE
    : playerStates.COUNTDOWN
}

function groupDetails(group) {
  if (group.type === 'IDENTITY') {
    return [
      { label: 'Lagnam', value: 'Your outward journey' },
      { label: 'Rasi', value: 'Your emotional nature' },
      { label: 'Nakshatra', value: 'Your inner pattern' },
    ]
  }

  if (!videoDebugEnabled) {
    return group.systemYearLabel
      ? [{ label: 'Year', value: group.systemYearLabel }]
      : []
  }

  return [
    group.ageLabel ? { label: 'Age', value: group.ageLabel } : null,
    group.systemYearLabel ? { label: 'Calendar', value: group.systemYearLabel } : null,
    group.majorDasha ? { label: 'Mahadasha', value: group.majorDasha } : null,
    group.dashaLabel ? { label: 'Dasha period', value: group.dashaLabel } : null,
  ].filter(Boolean)
}

function dubbedDisclosureText(languageCode) {
  const languageName = getLanguageByCode(languageCode)?.name || 'English'
  return `Original version is Tamil. You are watching dubbed ${languageName} version`
}

const accuracyOptions = [
  ['BELOW_50', 'Less than 50%'],
  ['FROM_50_TO_74', '50% to less than 75%'],
  ['FROM_75_TO_89', '75% to less than 90%'],
  ['ABOVE_OR_EQUAL_90', '90% and above'],
]

function navLabel(group) {
  return group?.type === 'IDENTITY' ? 'My Identity' : group?.systemYearLabel || group?.title || 'Prediction'
}

function videoProgressLabel(group, currentVideoIndex) {
  if (group?.type === 'IDENTITY') {
    return 'Identity Reading'
  }

  const totalVideos = group?.videos.length || 0
  const ids = videoDebugEnabled && Array.isArray(group?.videoIds) && group.videoIds.length > 1
    ? ` (${group.videoIds.join(', ')})`
    : ''

  return `Video ${currentVideoIndex + 1} of ${totalVideos}${ids}`
}

function groupTitle(group) {
  if (group?.type === 'IDENTITY') return 'Astrological Identity'
  if (!group) return 'Past Journey'

  if (videoDebugEnabled && group.ageLabel) {
    return `${group.systemYearLabel || 'Prediction'} | Age ${group.ageLabel}`
  }

  return group.systemYearLabel || 'Past Journey'
}

function VideoDebugInfo({ debug }) {
  if (!videoDebugEnabled || !debug) return null

  const paddedCandidate = Array.isArray(debug.filename_candidates) ? debug.filename_candidates[0] : null
  const rawCandidate = Array.isArray(debug.filename_candidates) ? debug.filename_candidates[1] : null

  return (
    <div className="prediction-player__debug">
      <strong>DEBUG</strong>
      <span>Video Role: {debug.video_role || '-'}</span>
      <span>Video ID: {debug.video_id || '-'}</span>
      <span>Prediction Type: {debug.prediction_type || debug.prediction_period || '-'}</span>
      <span>Language: {debug.language || '-'}</span>
      <span>Prediction Result ID: {debug.prediction_result_id || '-'}</span>
      <span>Prediction Request UUID: {debug.request_uuid || '-'}</span>
      <span>Playback URL Status: {debug.playback_url_status || debug.status || '-'}</span>
      <span>Stream Status: {debug.stream_status || '-'}</span>
      <span>Stored Video ID: {debug.stored_video_id || '-'}</span>
      <span>Final Video ID: {debug.final_video_id || '-'}</span>
      <span>Video ID Source: {debug.video_id_source || '-'}</span>
      {paddedCandidate ? (
        <span>Padded Candidate: {paddedCandidate.filename || '-'} / Exists: {paddedCandidate.exists ? 'true' : 'false'}</span>
      ) : null}
      {rawCandidate ? (
        <span>Raw Candidate: {rawCandidate.filename || '-'} / Exists: {rawCandidate.exists ? 'true' : 'false'}</span>
      ) : null}
      <span>Resolved File: {debug.resolved_filename || debug.filename || '-'}</span>
      <span>Excel Source: {debug.excel_file_path || '-'}</span>
    </div>
  )
}

function PredictionPlayer({
  playlist,
  onClose,
  onComplete,
  completionTitle = 'Your Past Reading Is Complete',
  predictionRequestAccess,
  predictionResultId,
  predictionType = 'past',
  requireFeedbackOnFinish = false,
  onFeedbackSaved,
  onFeedbackSubmitted,
}) {
  const videoRef = useRef(null)
  const navRefs = useRef([])
  const pendingResumeTimeRef = useRef(null)
  const [state, setState] = useState({
    playerState: entryStateForGroup(playlist[0]),
    currentGroupIndex: 0,
    currentVideoIndex: 0,
    countdownKey: 0,
    feedbackOpen: false,
    savedPlaybackTime: 0,
    completedGroupIndexes: [],
  })
  const [playbackError, setPlaybackError] = useState('')
  const [needsTapToContinue, setNeedsTapToContinue] = useState(false)
  const [feedbackRating, setFeedbackRating] = useState(0)
  const [feedbackAccuracy, setFeedbackAccuracy] = useState('')
  const [feedbackComments, setFeedbackComments] = useState('')
  const [feedbackStatus, setFeedbackStatus] = useState('')
  const [feedbackError, setFeedbackError] = useState('')
  const [isSubmittingFeedback, setIsSubmittingFeedback] = useState(false)
  const [hasSubmittedFeedback, setHasSubmittedFeedback] = useState(false)
  const [playbackSource, setPlaybackSource] = useState({
    key: '',
    url: '',
    isLoading: false,
    error: '',
    debug: null,
  })
  const [playbackRefreshKey, setPlaybackRefreshKey] = useState(0)
  const [selectedPlaybackLanguage, setSelectedPlaybackLanguage] = useState(() => (
    getStoredLanguage()?.code || 'ta'
  ))

  const currentGroup = playlist[state.currentGroupIndex]
  const currentVideo = currentGroup?.videos[state.currentVideoIndex]
  const isCompleted = state.playerState === playerStates.COMPLETED
  const currentVideoKey = `${state.currentGroupIndex}-${state.currentVideoIndex}-${currentVideo?.videoId || ''}`
  const currentPeriodVideoCount = currentGroup?.type === 'PREDICTION' ? currentGroup.videos.length : 0
  const curtainVideo = currentGroup?.type === 'PREDICTION' && isCurtainVideo(currentVideo?.videoId)
  const shouldShowDubbedDisclosure = selectedPlaybackLanguage !== 'ta'
  const visibleDebug = {
    ...(playbackSource.debug || {}),
    video_role: playbackSource.debug?.video_role || currentGroup?.type || '',
    video_id: playbackSource.debug?.video_id || currentVideo?.videoId || '',
    prediction_type: playbackSource.debug?.prediction_type || predictionType,
    language: playbackSource.debug?.language || selectedPlaybackLanguage,
    prediction_result_id: playbackSource.debug?.prediction_result_id || predictionResultId || '',
    request_uuid: playbackSource.debug?.request_uuid || predictionRequestAccess?.requestUuid || '',
  }

  const introSubtitle = currentGroup?.type === 'IDENTITY'
    ? 'Let us begin by understanding your astrological identity.'
    : currentGroup?.subtitle

  const playCurrentVideo = useCallback(() => {
    const video = videoRef.current
    if (!video) return

    setNeedsTapToContinue(false)
    const playPromise = video.play()

    if (playPromise?.catch) {
      playPromise.catch(() => {
        setNeedsTapToContinue(true)
      })
    }
  }, [])

  useEffect(() => () => {
    const video = videoRef.current
    if (!video) return

    video.pause()
    video.removeAttribute('src')
    video.load()
  }, [])

  useEffect(() => {
    const syncPlaybackLanguage = (event) => {
      const changedLanguage = getLanguageByCode(event?.detail?.code)
      const storedLanguage = changedLanguage || getStoredLanguage()

      setSelectedPlaybackLanguage(storedLanguage?.code || 'ta')
    }

    const handleStorage = (event) => {
      if (event.key !== 'ygtPreferredLanguage') return
      syncPlaybackLanguage()
    }

    window.addEventListener(LANGUAGE_CHANGE_EVENT, syncPlaybackLanguage)
    window.addEventListener('storage', handleStorage)

    return () => {
      window.removeEventListener(LANGUAGE_CHANGE_EVENT, syncPlaybackLanguage)
      window.removeEventListener('storage', handleStorage)
    }
  }, [])

  useEffect(() => {
    if (state.playerState === playerStates.PLAYING && !state.feedbackOpen) {
      playCurrentVideo()
    }
  }, [playCurrentVideo, state.currentGroupIndex, state.currentVideoIndex, state.feedbackOpen, state.playerState])

  useEffect(() => {
    navRefs.current[state.currentGroupIndex]?.scrollIntoView({
      behavior: 'smooth',
      inline: 'center',
      block: 'nearest',
    })
  }, [state.currentGroupIndex])

  useEffect(() => {
    if (state.playerState !== playerStates.MULTI_VIDEO_NOTICE) return undefined

    const timer = window.setTimeout(() => {
      setState((current) => (
        current.playerState === playerStates.MULTI_VIDEO_NOTICE
          ? { ...current, countdownKey: current.countdownKey + 1, playerState: playerStates.COUNTDOWN }
          : current
      ))
    }, 2000)

    return () => window.clearTimeout(timer)
  }, [state.currentGroupIndex, state.playerState])

  useEffect(() => {
    const videoId = currentVideo?.videoId

    if (!videoId) {
      setPlaybackSource({ key: currentVideoKey, url: '', isLoading: false, error: '', debug: null })
      return undefined
    }

    if (!predictionRequestAccess?.predictionRequestId || !predictionRequestAccess?.requestUuid) {
      setPlaybackSource({ key: currentVideoKey, url: currentVideo?.url || '', isLoading: false, error: '', debug: null })
      return undefined
    }

    const controller = new AbortController()
    setPlaybackSource((current) => ({
      key: currentVideoKey,
      url: current.key === currentVideoKey ? current.url : '',
      isLoading: true,
      error: '',
      debug: current.key === currentVideoKey ? current.debug : null,
    }))

    fetchProtectedVideoPlaybackUrl({
      ...predictionRequestAccess,
      predictionType,
      predictionResultId,
      videoId,
      videoRole: currentGroup?.type,
      language: selectedPlaybackLanguage,
      signal: controller.signal,
    })
      .then(async (data) => {
        let debug = data?.debug ? { ...data.debug, playback_url_status: data.status } : { playback_url_status: data?.status }
        let streamOk = true

        if (videoDebugEnabled && data?.url) {
          try {
            const streamResponse = await fetch(data.url, {
              headers: { Range: 'bytes=0-0' },
              signal: controller.signal,
            })
            debug = { ...debug, stream_status: streamResponse.status }
            streamOk = streamResponse.ok || streamResponse.status === 206
          } catch (streamError) {
            if (streamError?.name === 'AbortError') return
            debug = { ...debug, stream_status: 'NETWORK_ERROR' }
            streamOk = false
          }
        }

        setPlaybackSource({
          key: currentVideoKey,
          url: streamOk ? data?.url || '' : '',
          isLoading: false,
          error: streamOk ? '' : temporarilyUnavailableMessage,
          debug,
        })
      })
      .catch((error) => {
        if (error?.name === 'CanceledError' || error?.code === 'ERR_CANCELED') return

        setPlaybackSource({
          key: currentVideoKey,
          url: '',
          isLoading: false,
          error: temporarilyUnavailableMessage,
          debug: error?.response?.data?.data?.debug
            ? { ...error.response.data.data.debug, playback_url_status: error.response.status }
            : { playback_url_status: error?.response?.status || 'NETWORK_ERROR' },
        })
      })

    return () => controller.abort()
  }, [
    currentGroup?.type,
    currentVideo?.url,
    currentVideo?.videoId,
    currentVideoKey,
    predictionRequestAccess,
    predictionResultId,
    predictionType,
    playbackRefreshKey,
    selectedPlaybackLanguage,
  ])

  const handleCountdownComplete = useCallback(() => {
    if (playbackSource.isLoading) {
      setPlaybackError(temporarilyUnavailableMessage)
      setState((current) => ({ ...current, playerState: playerStates.ERROR }))
      return
    }

    if (!playbackSource.url) {
      setPlaybackError(playbackSource.error || temporarilyUnavailableMessage)
      setState((current) => ({ ...current, playerState: playerStates.ERROR }))
      return
    }

    setState((current) => ({ ...current, playerState: playerStates.PLAYING }))
  }, [playbackSource.error, playbackSource.isLoading, playbackSource.url])

  const advance = useCallback(() => {
    if (!currentGroup) return

    if (state.currentVideoIndex < currentGroup.videos.length - 1) {
      setState((current) => ({
        ...current,
        currentVideoIndex: current.currentVideoIndex + 1,
        playerState: playerStates.PLAYING,
      }))
      setPlaybackError('')
      return
    }

    if (state.currentGroupIndex < playlist.length - 1) {
      const nextGroupIndex = state.currentGroupIndex + 1
      const nextGroup = playlist[nextGroupIndex]

      setState((current) => ({
        ...current,
        currentGroupIndex: nextGroupIndex,
        currentVideoIndex: 0,
        countdownKey: current.countdownKey + 1,
        playerState: entryStateForGroup(nextGroup),
        completedGroupIndexes: Array.from(new Set([...current.completedGroupIndexes, current.currentGroupIndex])),
      }))
      setPlaybackError('')
      return
    }

    if (requireFeedbackOnFinish && hasSubmittedFeedback) {
      onComplete?.()
      return
    }

    setState((current) => ({
      ...current,
      playerState: playerStates.COMPLETED,
      completedGroupIndexes: Array.from(new Set([...current.completedGroupIndexes, current.currentGroupIndex])),
    }))
  }, [currentGroup, hasSubmittedFeedback, onComplete, playlist.length, requireFeedbackOnFinish, state.currentGroupIndex, state.currentVideoIndex])

  const handleSelectGroup = useCallback((index) => {
    const video = videoRef.current
    video?.pause()
    pendingResumeTimeRef.current = null
    setPlaybackError('')
    setNeedsTapToContinue(false)
    setState((current) => ({
      ...current,
      currentGroupIndex: index,
      currentVideoIndex: 0,
      countdownKey: current.countdownKey + 1,
      playerState: entryStateForGroup(playlist[index]),
      savedPlaybackTime: 0,
    }))
  }, [playlist])

  const openFeedback = () => {
    const video = videoRef.current
    const savedPlaybackTime = Number.isFinite(video?.currentTime) ? video.currentTime : 0

    video?.pause()
    setFeedbackStatus('')
    setFeedbackError('')
    setState((current) => ({
      ...current,
      feedbackOpen: true,
      savedPlaybackTime,
    }))
  }

  const closeFeedback = ({ resume = true } = {}) => {
    pendingResumeTimeRef.current = state.savedPlaybackTime
    setState((current) => ({ ...current, feedbackOpen: false }))

    if (resume && state.playerState === playerStates.PLAYING) {
      window.setTimeout(playCurrentVideo, 80)
    }
  }

  const handleSubmitFeedback = async () => {
    if (!feedbackRating || !feedbackAccuracy) {
      setFeedbackError('Please choose a rating and accuracy option.')
      return
    }

    if (!predictionRequestAccess?.predictionRequestId || !predictionRequestAccess?.requestUuid || !predictionResultId) {
      setFeedbackError('Feedback is not available for this reading yet.')
      return
    }

    setIsSubmittingFeedback(true)
    setFeedbackError('')

    try {
      await submitPredictionFeedback({
        ...predictionRequestAccess,
        predictionResultId,
        predictionType,
        rating: feedbackRating,
        accuracyBand: feedbackAccuracy,
        comments: feedbackComments.trim() || null,
      })
      setHasSubmittedFeedback(true)
      setFeedbackStatus('Thank you for your feedback.')
      onFeedbackSaved?.()
      if (requireFeedbackOnFinish && state.playerState === playerStates.COMPLETED) {
        onFeedbackSubmitted?.()
        return
      }
      window.setTimeout(() => closeFeedback(), 520)
    } catch (error) {
      const message = error?.response?.data?.message || error?.message || 'We could not save your feedback. Please try again.'
      setFeedbackError(message)
    } finally {
      setIsSubmittingFeedback(false)
    }
  }

  const progressText = useMemo(() => {
    if (!currentGroup) return ''

    return `${state.currentGroupIndex + 1} of ${playlist.length} readings`
  }, [currentGroup, playlist.length, state.currentGroupIndex])

  if (!playlist.length) {
    return (
      <div className="prediction-player-overlay" role="dialog" aria-modal="true">
        <section className="prediction-player">
          <button type="button" className="prediction-player__close" onClick={onClose} aria-label="Close prediction player">x</button>
          <div className="prediction-player__empty">
            <h2>Prediction Videos Are Not Ready</h2>
            <p>We could not find playable videos for this reading yet.</p>
          </div>
        </section>
      </div>
    )
  }

  return (
    <div className="prediction-player-overlay" role="dialog" aria-modal="true" aria-label="Past prediction video player">
      <section className="prediction-player">
        <button type="button" className="prediction-player__close" onClick={onClose} aria-label="Close prediction player">x</button>
        <button type="button" className="prediction-player__feedback-link" onClick={openFeedback}>
          Rate This Prediction
        </button>

        <header className="prediction-player__header">
          <span>{isCompleted ? 'Complete' : progressText}</span>
          <strong>{groupTitle(currentGroup)}</strong>
        </header>

        {videoDebugEnabled ? (
          <nav className="prediction-player__timeline" aria-label="Prediction year navigation">
            {playlist.map((group, index) => (
              <button
                key={`${group.type}-${group.systemYearLabel || index}`}
                ref={(element) => {
                  navRefs.current[index] = element
                }}
                type="button"
                className={[
                  index === state.currentGroupIndex && !isCompleted ? 'is-active' : '',
                  (isCompleted || state.completedGroupIndexes.includes(index)) ? 'is-complete' : '',
                ].filter(Boolean).join(' ')}
                onClick={() => handleSelectGroup(index)}
              >
                <span>{navLabel(group)}</span>
                {group.type === 'PREDICTION' && group.ageLabel ? <small>Age {group.ageLabel}</small> : null}
                {group.type === 'PREDICTION' && group.majorDasha ? <small>{group.majorDasha}</small> : null}
              </button>
            ))}
          </nav>
        ) : null}

        <div className="prediction-player__stage">
          {state.playerState === playerStates.MULTI_VIDEO_NOTICE ? (
            <div className="prediction-player__period-notice">
              <span>{currentGroup?.systemYearLabel || currentGroup?.title || 'Prediction'}</span>
              <strong>{currentPeriodVideoCount} videos for this period</strong>
            </div>
          ) : null}

          {state.playerState === playerStates.COUNTDOWN ? (
            <PredictionCountdown
              key={`${state.currentGroupIndex}-${state.countdownKey}`}
              seconds={2}
              title={currentGroup?.title}
              subtitle={introSubtitle}
              details={groupDetails(currentGroup)}
              onComplete={handleCountdownComplete}
            />
          ) : null}

          {state.playerState === playerStates.PLAYING ? (
            <div className="prediction-player__video-wrap">
              <video
                ref={videoRef}
                key={`${state.currentGroupIndex}-${state.currentVideoIndex}-${currentVideo?.videoId}-${playbackSource.url}`}
                className={curtainVideo ? 'prediction-player__video prediction-video--curtain' : 'prediction-player__video'}
                src={playbackSource.url}
                controls
                autoPlay
                playsInline
                preload="auto"
                onLoadedMetadata={(event) => {
                  if (pendingResumeTimeRef.current !== null) {
                    event.currentTarget.currentTime = Math.min(pendingResumeTimeRef.current, event.currentTarget.duration || pendingResumeTimeRef.current)
                    pendingResumeTimeRef.current = null
                  }
                }}
                onEnded={advance}
                onError={() => {
                  setPlaybackError(temporarilyUnavailableMessage)
                  setPlaybackSource((current) => ({
                    ...current,
                    debug: {
                      ...(current.debug || {}),
                      stream_status: current.debug?.stream_status || 'BROWSER_PLAYBACK_ERROR',
                    },
                  }))
                  setState((current) => ({ ...current, playerState: playerStates.ERROR }))
                }}
              />
              {shouldShowDubbedDisclosure ? (
                <p className="prediction-player__dubbed-disclosure">
                  {dubbedDisclosureText(selectedPlaybackLanguage)}
                </p>
              ) : null}
              <div className="prediction-player__caption">
                <strong>{videoProgressLabel(currentGroup, state.currentVideoIndex)}</strong>
                {videoDebugEnabled && currentVideo?.videoId ? <span>Video ID {currentVideo.videoId}</span> : null}
              </div>
              <VideoDebugInfo debug={visibleDebug} />
              {needsTapToContinue ? (
                <button type="button" className="prediction-player__tap" onClick={playCurrentVideo}>
                  Tap to Continue
                </button>
              ) : null}
            </div>
          ) : null}

          {state.playerState === playerStates.ERROR ? (
            <div className="prediction-player__empty">
              <h2>Playback Paused</h2>
              <p>{playbackError || 'We could not play this video right now.'}</p>
              <VideoDebugInfo debug={visibleDebug} />
              <div className="prediction-player__actions">
                <button
                  type="button"
                  onClick={() => {
                    setPlaybackError('')
                    setPlaybackRefreshKey((current) => current + 1)
                    setState((current) => ({ ...current, countdownKey: current.countdownKey + 1, playerState: playerStates.COUNTDOWN }))
                  }}
                >
                  Retry Video
                </button>
                <button type="button" onClick={onClose}>Close</button>
              </div>
            </div>
          ) : null}

          {isCompleted ? (
            <div className="prediction-player__complete">
              <span className="prediction-player__complete-ring" aria-hidden="true" />
              <h2>{completionTitle}</h2>
              <p>
                {hasSubmittedFeedback
                  ? 'Thank you for sharing your feedback about this prediction.'
                  : 'Would you like to rate this prediction before returning to your personal dashboard?'}
              </p>
              <div className="prediction-player__actions">
                {!hasSubmittedFeedback ? (
                  <button type="button" onClick={openFeedback}>Rate This Prediction</button>
                ) : null}
                <button type="button" onClick={onComplete || onClose}>Back to Home</button>
              </div>
            </div>
          ) : null}
        </div>

        {!isCompleted ? (
          <div className="prediction-player__progress" aria-label="Prediction playback progress">
            {playlist.map((group, index) => (
              <span
                key={`${group.type}-${group.ageLabel || index}`}
                className={index === state.currentGroupIndex ? 'is-active' : ''}
              />
            ))}
          </div>
        ) : null}

        {state.feedbackOpen ? (
          <div className="prediction-player__feedback-overlay" role="dialog" aria-modal="true" aria-label="Rate this prediction">
            <div className="prediction-player__feedback-sheet">
              <button type="button" className="prediction-player__feedback-close" onClick={() => closeFeedback()} aria-label="Close feedback">x</button>
              <h2>Rate This Prediction</h2>
              <p>Your feedback helps us improve future readings.</p>

              <div className="prediction-player__stars" aria-label="Rating">
                {[1, 2, 3, 4, 5].map((star) => (
                  <button
                    key={star}
                    type="button"
                    className={feedbackRating >= star ? 'is-active' : ''}
                    onClick={() => setFeedbackRating(star)}
                    aria-label={`${star} star rating`}
                  >
                    *
                  </button>
                ))}
              </div>

              <fieldset className="prediction-player__accuracy">
                <legend>How accurate was your prediction?</legend>
                {accuracyOptions.map(([value, label]) => (
                  <label key={value}>
                    <input
                      type="radio"
                      name="prediction-player-accuracy"
                      value={value}
                      checked={feedbackAccuracy === value}
                      onChange={() => setFeedbackAccuracy(value)}
                    />
                    <span>{label}</span>
                  </label>
                ))}
              </fieldset>

              <label className="prediction-player__comments">
                Comments
                <textarea
                  value={feedbackComments}
                  maxLength={1000}
                  rows={4}
                  onChange={(event) => setFeedbackComments(event.target.value)}
                />
              </label>

              {feedbackStatus ? <p className="prediction-player__feedback-status">{feedbackStatus}</p> : null}
              {feedbackError ? <p className="prediction-player__feedback-error">{feedbackError}</p> : null}

              <div className="prediction-player__feedback-actions">
                <button type="button" disabled={isSubmittingFeedback} onClick={handleSubmitFeedback}>
                  {isSubmittingFeedback ? 'Submitting...' : 'Submit Feedback'}
                </button>
                <button type="button" onClick={() => closeFeedback()}>Close</button>
              </div>
            </div>
          </div>
        ) : null}
      </section>
    </div>
  )
}

export default PredictionPlayer
