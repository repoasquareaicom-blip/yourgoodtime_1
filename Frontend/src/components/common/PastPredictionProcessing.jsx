import { useMemo } from 'react'
import PredictionCelebration from './PredictionCelebration'
import PredictionTimeline from './PredictionTimeline'
import './PastPredictionProcessing.css'

const zodiacSymbols = ['♈', '♉', '♊', '♋', '♌', '♍', '♎', '♏', '♐', '♑', '♒', '♓']
const timelineYears = ['2016', '2017', '2018', '2019', '2020', '2021', '2022', '2023', '2024', '2025']
const preparationItems = [
  'Analysing planetary influences',
  'Matching historical life events',
  'Identifying relevant prediction topics',
  'Preparing personalised guidance',
  'Finalising your prediction experience',
]

export const predictionStages = [
  {
    id: 'birth_details',
    key: 'birth_details',
    label: 'Receiving your birth details',
    message: 'Confirming your name, birth date, time, and place.',
    completeLabel: '✓ Birth details verified',
    visual: 'pulse',
  },
  {
    id: 'horoscope',
    key: 'horoscope',
    label: 'Reading your horoscope',
    message: 'Receiving your horoscope and mapping the zodiac signs.',
    completeLabel: '✓ Horoscope received',
    visual: 'zodiac',
  },
  {
    id: 'lagnam',
    key: 'lagnam',
    label: 'Identifying your Lagnam',
    message: 'Searching for the ascendant that anchors your chart.',
    completeLabel: '✓ Lagnam identified',
    visual: 'search',
  },
  {
    id: 'nakshatra',
    key: 'nakshatra',
    label: 'Identifying your Rasi & Nakshatra',
    message: 'Searching the lunar sign and star pattern for your journey.',
    completeLabel: '✓ Nakshatra identified',
    visual: 'search',
  },
  {
    id: 'dasha',
    key: 'dasha',
    label: 'Analysing your Dasha periods',
    message: 'Reviewing planetary periods across your past years.',
    completeLabel: '✓ Dasha periods analysed',
    visual: 'dasha',
  },
  {
    id: 'matching',
    key: 'matching',
    label: 'Matching your past years',
    message: 'Comparing important years with matching prediction themes.',
    completeLabel: '✓ 10 years matched',
    visual: 'timeline',
  },
  {
    id: 'personalised_videos',
    key: 'playlist',
    label: 'Searching prediction library',
    message: 'Finding the videos that match your chart and past timeline.',
    completeLabel: '✓ Prediction videos found',
    visual: 'library',
  },
  {
    id: 'playlist',
    key: 'playlist',
    label: 'Preparing your personalised experience',
    message: 'Finalising your prediction playlist with a warm golden finish.',
    completeLabel: '✓ Prediction playlist prepared',
    visual: 'brighten',
  },
]

const firstStage = predictionStages[0].key

export const predictionStageSequence = [
  'birth_details',
  'horoscope',
  'lagnam',
  'nakshatra',
  'dasha',
  'matching',
  'playlist',
]

function displayNameFrom(fullName = '') {
  return fullName.trim().split(/\s+/)[0] || ''
}

function activeIndexFor(stage, progress) {
  if (stage === 'preparing') return 0

  if (stage === 'playlist') {
    return Number(progress) >= 1 || progress === undefined ? 7 : 6
  }

  return Math.max(predictionStages.findIndex((item) => item.key === stage), 0)
}

function StageVisual({ visual, status }) {
  return (
    <div className={`past-processing__stage-visual is-${visual} is-${status}`}>
      {visual === 'pulse' ? (
        <div className="past-processing__golden-pulse">
          <span />
          <span />
          <span />
        </div>
      ) : null}

      {visual === 'zodiac' || visual === 'dasha' ? (
        <div className={`past-processing__zodiac ${visual === 'dasha' ? 'is-large' : ''}`}>
          {zodiacSymbols.map((symbol, index) => (
            <span key={symbol} style={{ '--zodiac-index': index }}>{symbol}</span>
          ))}
        </div>
      ) : null}

      {visual === 'search' ? (
        <div className="past-processing__search-orbit">
          <span />
          <span />
          <span />
        </div>
      ) : null}

      {visual === 'timeline' ? (
        <div className="past-processing__year-timeline">
          {timelineYears.map((year, index) => (
            <span key={year} style={{ '--year-index': index }}>{year} ✓</span>
          ))}
        </div>
      ) : null}

      {visual === 'library' ? (
        <div className="past-processing__video-stack">
          {preparationItems.map((item, index) => (
            <span key={item} style={{ '--video-index': index }}>✓ {item}</span>
          ))}
        </div>
      ) : null}

      {visual === 'brighten' ? (
        <div className="past-processing__bright-card">
          <div className="past-processing__video-stack is-compact">
            {preparationItems.slice(2, 5).map((item, index) => (
              <span key={item} style={{ '--video-index': index }}>✓ {item}</span>
            ))}
          </div>
          <span />
          <strong>Personalising</strong>
        </div>
      ) : null}
    </div>
  )
}

function PastPredictionProcessing({
  predictionStage = 'preparing',
  progress,
  message = '',
  engineUpdate = null,
  preparationStatus = '',
  userName = '',
  errorMessage = '',
  resultSummary = null,
  playlist = [],
  onRetry,
  onBackToForm,
  onContinue,
}) {
  const effectiveStage = engineUpdate?.stage || predictionStage
  const effectiveMessage = engineUpdate?.message || message
  const effectiveProgress = engineUpdate?.progress ?? progress
  const isIntroStage = effectiveStage === 'preparing'
  const normalizedStage = isIntroStage ? firstStage : effectiveStage
  const friendlyName = displayNameFrom(userName)

  const status = useMemo(() => {
    if (effectiveStage === 'ready') return 'success'
    if (effectiveStage === 'error') return 'error'
    return 'processing'
  }, [effectiveStage])

  const activeIndex = activeIndexFor(normalizedStage, effectiveProgress)
  const activeStage = predictionStages[activeIndex] || predictionStages[0]
  const progressValue = Number.isFinite(Number(effectiveProgress))
    ? Math.max(0, Math.min(100, Number(effectiveProgress)))
    : isIntroStage
      ? 4
      : Math.round(((activeIndex + 1) / predictionStages.length) * 100)

  const currentTitle = useMemo(() => {
    if (status === 'success') return '✨ Your Past Prediction Is Ready'
    if (status === 'error') return "We Couldn't Prepare Your Prediction"
    if (friendlyName) return `Hello ${friendlyName}`
    return 'Preparing your personalised past prediction...'
  }, [friendlyName, status])

  const currentMessage = useMemo(() => {
    if (status === 'success') {
      return 'We have analysed your past years and prepared your personalised video prediction.'
    }

    if (status === 'error') {
      return errorMessage || 'We could not prepare your prediction. Please try again.'
    }

    if (isIntroStage) {
      return "We are carefully analysing your horoscope to understand your life's journey."
    }

    return effectiveMessage || activeStage.message
  }, [activeStage.message, effectiveMessage, errorMessage, isIntroStage, status])

  return (
    <div className={`past-processing is-${status}`} role="status" aria-live="polite" data-preparation-status={preparationStatus}>
      <div className="past-processing__visual" aria-hidden="true">
        <div className="past-processing__stars" />
        <div className="past-processing__particles" />
        <div className="past-processing__radial-glow" />
        {status === 'processing' ? (
          <StageVisual visual={isIntroStage ? 'pulse' : activeStage.visual} status={status} />
        ) : (
          <StageVisual visual="brighten" status={status} />
        )}
        <div className="past-processing__core">YGT</div>
      </div>

      <div className="past-processing__content">
        {status !== 'success' ? (
          <>
            <h1>{currentTitle}</h1>
            {status === 'processing' && friendlyName ? (
              <p className="past-processing__headline">Preparing your personalised past prediction...</p>
            ) : null}
            <p>{currentMessage}</p>
          </>
        ) : null}

        {status === 'processing' ? (
          <>
            <div className="past-processing__progress" aria-label={`Prediction preparation ${progressValue}% complete`}>
              <span style={{ width: `${progressValue}%` }} />
            </div>
            <PredictionTimeline
              steps={predictionStages}
              activeIndex={activeIndex}
              isIntroStage={isIntroStage}
            />
          </>
        ) : null}

        {status === 'error' ? (
          <div className="past-processing__actions">
            <button type="button" className="past-processing__primary" onClick={onRetry}>
              Retry
            </button>
            <button type="button" className="past-processing__secondary-btn" onClick={onBackToForm}>
              Back to Form
            </button>
          </div>
        ) : null}

        {status === 'success' ? (
          <PredictionCelebration
            onContinue={onContinue}
            summary={resultSummary}
            playlist={playlist}
          />
        ) : null}
      </div>
    </div>
  )
}

export default PastPredictionProcessing
