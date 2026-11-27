import { predictionPaymentText as t } from '../data/predictionPaymentCopy'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import AppFooter from '../components/common/AppFooter'
import FuturePurchaseModal from '../components/common/FuturePurchaseModal'
import HeaderAuthActions from '../components/common/HeaderAuthActions'
import PrimaryNav from '../components/common/PrimaryNav'
import PredictionPlayer from '../components/common/PredictionPlayer'
import TempleRemedyModal from '../components/common/TempleRemedyModal'
import TalkToAstrologerModal from '../components/common/TalkToAstrologerModal'
import ygtLogo from '../assets/yt-logo.png'
import { clearMyAccountPredictionCache, fetchMyAccountPredictions } from '../services/myAccountService'
import { fetchAdminAccountPrediction, fetchAdminPredictionPlaybackUrl, fetchAdminUserAccount } from '../services/adminService'
import { createAppointmentOrder, createTempleRemedyOrder, loadRazorpayScript, verifyRazorpayPayment } from '../services/paymentService'
import { buildPredictionPlaylist, fetchPredictionResult, fetchStoredPredictionResult } from '../services/predictionResultService'
import { clearPredictionSession, savePredictionSession } from '../services/predictionSessionService'
import { cancelTempleRemedyPayment, fetchTempleRemedyFile, submitTempleRemedyFeedback } from '../services/templeRemedyService'
import './HomePage.css'
import './AccountPage.css'

function Icon({ name }) {
  const paths = {
    sun: 'M12 4V2m0 20v-2m8-8h2M2 12h2m14.4-6.4 1.4-1.4M4.2 19.8l1.4-1.4m12.8 0 1.4 1.4M4.2 4.2l1.4 1.4M12 7.5a4.5 4.5 0 1 1 0 9 4.5 4.5 0 0 1 0-9Z',
    moon: 'M20.2 15.4A8.3 8.3 0 0 1 8.6 3.8a8.8 8.8 0 1 0 11.6 11.6Z',
    star: 'm12 3 2.6 5.8 6.4.7-4.8 4.3 1.4 6.2L12 16.8 6.4 20l1.4-6.2L3 9.5l6.4-.7Z',
    compass: 'm16.8 7.2-2.5 7.1-7.1 2.5 2.5-7.1Z M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z',
    calendar: 'M7 3v3m10-3v3M4.5 9h15M6 5h12a2 2 0 0 1 2 2v11.5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z',
    clock: 'M12 7v5l3.2 1.9M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    ring: 'M8.2 9.2 12 4.6l3.8 4.6M12 20a6 6 0 1 0 0-12 6 6 0 0 0 0 12Z',
    pin: 'M12 21s6-5.2 6-11a6 6 0 1 0-12 0c0 5.8 6 11 6 11Z M12 12.2a2.2 2.2 0 1 0 0-4.4 2.2 2.2 0 0 0 0 4.4Z',
    globe: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z M3.6 9h16.8M3.6 15h16.8M12 3c2.2 2.4 3.2 5.4 3.2 9s-1 6.6-3.2 9c-2.2-2.4-3.2-5.4-3.2-9S9.8 5.4 12 3Z',
    planet: 'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z M3 15.8c2.2 1.7 7.6 1 12.1-1.6S22 8.5 21 6.5c-.5-.9-2.1-1-4.2-.4M7.2 17.9c-2.1.6-3.7.5-4.2-.4-.7-1.3.7-3.3 3.3-5.2',
    constellation: 'M5 17.5 10.2 9l4.1 4.2L19 5.8M5 17.5h.1M10.2 9h.1M14.3 13.2h.1M19 5.8h.1',
    chevron: 'M8 4l8 8-8 8',
    home: 'M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1Z',
    user: 'M12 12a4.5 4.5 0 1 0-4.5-4.5A4.5 4.5 0 0 0 12 12Zm0 2c-4 0-7 2-7 4.5V20h14v-1.5C19 16 16 14 12 14Z',
    gallery: 'M4 6h5v5H4Zm0 7h5v5H4Zm7-7h9v5h-9Zm0 7h9v5h-9Z',
    settings: 'm19.4 13.5 1.3-1.5-1.3-1.5-1.9.2a6 6 0 0 0-.8-.5l-.3-1.9-2-.3-.8 1.7a5 5 0 0 0-.9 0l-.8-1.7-2 .3-.3 1.9c-.3.1-.6.3-.8.5l-1.9-.2L3.3 12l1.3 1.5-.2 1.9 1.9.8.8 1.7 2-.3.8-1.7h.9l.8 1.7 2-.3.3-1.9.8-.5 1.9.2.9-1.9ZM12 14.8a2.8 2.8 0 1 1 2.8-2.8 2.8 2.8 0 0 1-2.8 2.8Z',
  }

  return (
    <svg className="account-icon" viewBox="0 0 24 24" aria-hidden="true">
      <path d={paths[name]} />
    </svg>
  )
}

function periodLabel(startYear, endYear) {
  if (!startYear || !endYear) return null

  return startYear === endYear ? String(startYear) : `${startYear} - ${endYear}`
}

function accessFromWatch(watch) {
  if (!watch?.prediction_request_id || !watch?.request_uuid) return null

  return {
    predictionRequestId: watch.prediction_request_id,
    requestUuid: watch.request_uuid,
    identityVideoId: watch.identity_video_id || null,
  }
}

function ensureIdentityVideo(result, access) {
  if (result?.identity_video?.video_id || !access?.identityVideoId) return result

  return {
    ...(result || {}),
    identity_video: {
      video_id: String(access.identityVideoId),
      source: {
        file: 'nakshatra_lagnam.xlsx',
      },
    },
  }
}

function displayValue(value) {
  if (value === null || value === undefined || value === '') return null

  return String(value)
}

function titleValue(value) {
  const text = displayValue(value)

  if (!text) return null

  return text
    .replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase())
}

function formatBirthDateTime(dateValue, timeValue) {
  if (!dateValue && !timeValue) return null

  let dateText = displayValue(dateValue)
  const dateParts = displayValue(dateValue)?.match(/^(\d{4})-(\d{2})-(\d{2})/)

  if (dateParts) {
    const [, year, month, day] = dateParts
    const monthName = new Date(Number(year), Number(month) - 1, 1).toLocaleString('en-US', { month: 'short' })
    dateText = `${monthName}-${day}-${year}`
  }

  let timeText = displayValue(timeValue)
  const timeParts = displayValue(timeValue)?.match(/^(\d{1,2}):(\d{2})/)

  if (timeParts) {
    let hour = Number(timeParts[1])
    const minute = timeParts[2]
    const suffix = hour >= 12 ? 'PM' : 'AM'
    hour = hour % 12 || 12
    timeText = `${hour}:${minute} ${suffix}`
  }

  return [dateText, timeText].filter(Boolean).join(' ')
}

function formatDateTime(value) {
  if (!value) return null

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) return displayValue(value)

  return date.toLocaleString('en-US', {
    month: 'short',
    day: '2-digit',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
}

function formatMoney(amountMinor, currency = 'INR') {
  const amount = Number(amountMinor)

  if (!Number.isFinite(amount)) return null

  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency,
    maximumFractionDigits: 2,
  }).format(amount / 100)
}

function normalizedRemedyType(type, url) {
  const text = displayValue(type)?.toLowerCase()
  const path = displayValue(url)?.toLowerCase() || ''

  if (text?.includes('video') || /\.(mp4|webm|ogg)(\?|$)/.test(path)) return 'video'
  if (text?.includes('image') || /\.(png|jpe?g|gif|webp)(\?|$)/.test(path)) return 'image'
  if (text?.includes('pdf') || /\.pdf(\?|$)/.test(path)) return 'pdf'

  return 'file'
}

function formatCoordinate(value) {
  const numberValue = Number(value)

  if (!Number.isFinite(numberValue)) return null

  return numberValue.toFixed(4)
}

function formatLocation(details) {
  const location = [displayValue(details.place_of_birth), displayValue(details.country)].filter(Boolean).join(', ')
  const latitude = formatCoordinate(details.latitude)
  const longitude = formatCoordinate(details.longitude)
  const coordinates = latitude && longitude ? ` (${latitude}, ${longitude})` : ''

  return location ? `${location}${coordinates}` : null
}

function yearParts(startYear, endYear) {
  if (!startYear && !endYear) return null

  return {
    start: startYear ? String(startYear) : null,
    end: endYear ? String(endYear) : null,
  }
}

function ProfileHero({ identity, submittedDetails, astrologyRows, isLoading }) {
  const displayName = displayValue(identity?.name) || 'Your Account'
  const marriageStatus = titleValue(submittedDetails.marriage_status)
  const birthDateTime = formatBirthDateTime(submittedDetails.date_of_birth, submittedDetails.time_of_birth)
  const location = formatLocation(submittedDetails)
  const visibleAstrologyRows = astrologyRows.filter((row) => row.value)

  return (
    <article className="account-details-card" aria-label="User details">
      <section className="account-details-section">
        <span className="account-kicker">Your Details</span>
        {isLoading && !identity ? (
          <div className="account-detail-loading" aria-hidden="true"><span /><span /></div>
        ) : (
          <div className="account-identity-summary">
            <div className="account-identity-main-row">
              <p><strong>{displayName}</strong>{marriageStatus ? ` - ${marriageStatus}` : ''}</p>
              {birthDateTime ? <p>{birthDateTime}</p> : null}
            </div>
            {location ? <p className="account-location-line">{location}</p> : null}
          </div>
        )}
      </section>

      <section className="account-details-section account-astro-summary">
        {visibleAstrologyRows.length || (isLoading && !identity) ? <span className="account-kicker">Astrology Summary</span> : null}
        {isLoading && !identity ? (
          <div className="account-detail-loading" aria-hidden="true"><span /><span /></div>
        ) : visibleAstrologyRows.length ? (
          <div className="account-astro-list">
            {visibleAstrologyRows.map((row) => (
              <p key={row.label}><span>{row.label}:</span> <strong>{row.value}</strong></p>
            ))}
          </div>
        ) : (
          <span className="account-astro-placeholder" aria-hidden="true" />
        )}
      </section>
    </article>
  )
}

function PeriodSummary({ years, label }) {
  if (!years?.start && !years?.end) {
    return label ? <p className="account-period-summary is-empty">{label}</p> : null
  }

  return (
    <p className="account-period-summary">
      <span>Available period</span>
      <strong>{years.start}{years.end && years.end !== years.start ? ` - ${years.end}` : ''}</strong>
    </p>
  )
}

function PredictionCard({
  tone = 'past',
  icon,
  title,
  copy,
  years,
  fallbackLabel,
  buttonLabel,
  disabled,
  onAction,
  note,
  rangeButtons = [],
  secondaryAction = null,
}) {
  return (
    <div className={`account-prediction-item is-${tone}`}>
      <div className="account-prediction-title-row">
        <div className="account-card-icon"><Icon name={icon} /></div>
        <div className="account-prediction-copy">
          <h3>{title}</h3>
          <p>{copy}</p>
        </div>
      </div>
      <article className={`account-prediction-card is-${tone}`}>
        <PeriodSummary years={years} label={fallbackLabel} />
        {rangeButtons.length ? (
          <div className="account-range-list" aria-label="Purchased Future ranges">
            {rangeButtons.map((range) => (
              <button
                type="button"
                key={`${range.entitlement_id}-${range.start_year}-${range.end_year}`}
                  disabled={disabled}
                onClick={range.onClick}
              >
                {periodLabel(range.start_year, range.end_year)}
              </button>
            ))}
          </div>
        ) : null}
        {buttonLabel && (
          <button type="button" className="account-primary-btn" disabled={disabled} onClick={onAction}>
            {buttonLabel}
          </button>
        )}
        {secondaryAction ? (
          <button
            type="button"
            className="account-secondary-btn"
            disabled={secondaryAction.disabled}
            onClick={secondaryAction.onClick}
          >
            <span>{secondaryAction.label}</span>
            {secondaryAction.years ? <strong>{secondaryAction.years}</strong> : null}
          </button>
        ) : null}
        {note ? <p className="account-card-note">{note}</p> : null}
      </article>
    </div>
  )
}

function TempleRemedySection({ remedies, isLoading, onRequest, onPay, onViewRemedy, onSubmitFeedback, payingRemedyId, readOnly = false }) {
  const items = Array.isArray(remedies) ? remedies : []
  const hasActiveRemedy = items.some((item) => item.status !== 'COMPLETED')

  return (
    <section className="account-remedy-section" aria-label="Temple Remedy requests">
      <div className="account-section-heading account-remedy-heading">
        <div className="account-remedy-title-row">
          <div className="account-card-icon"><Icon name="star" /></div>
          <h2>Temple Remedy Requests</h2>
        </div>
      </div>

      <article className="account-remedy-request-panel">
        <div>
          <span>Temple Remedy</span>
          <strong>{hasActiveRemedy ? 'One active request is in progress' : 'You can request a new remedy'}</strong>
        </div>
        {!hasActiveRemedy ? (
          <button type="button" disabled={readOnly} onClick={onRequest}>{items.length ? 'Request Another Remedy' : 'Request Temple Remedy'}</button>
        ) : (
          <small>Request another remedy after the current request is completed.</small>
        )}
      </article>

      {isLoading && !items.length ? (
        <article className="account-remedy-card is-empty">
          <p>Loading your Temple Remedy requests...</p>
        </article>
      ) : items.length ? (
        <div className="account-remedy-list">
          {items.map((item) => {
            const amount = formatMoney(item.amount_minor, item.currency)
            const remedy = item.remedy || {}
            const selectedRemedyTitle = item.remedy_option?.title || titleValue(item.remedy_option?.code) || 'Temple Remedy'
            const statusLabel = item.status === 'COMPLETED'
              ? 'Completed'
              : item.payment_status === 'PAID'
                ? 'Payment Received'
                : item.status_label || titleValue(item.status) || 'Request received'
            const needsPayment = Boolean(item.can_pay)
            const guidanceText = item.status === 'RECEIVED'
              ? 'Your request has been received.'
              : item.status === 'REVIEWING'
                ? 'Your request is currently being reviewed.'
                : item.status === 'PAYMENT_PENDING'
                  ? item.payment_method === 'qr_online'
                    ? 'Your QR payment reference is waiting for admin confirmation.'
                    : item.payment_status === 'PAID'
                      ? 'Payment received. Your remedy will appear here once it is completed.'
                      : 'Complete the payment to send this request to admin.'
                  : 'Your remedy is ready. View it securely and share feedback after seeing the guidance.'

            return (
              <article className="account-remedy-history-card" key={item.id}>
                <div className="account-remedy-history-main">
                  <div className="account-remedy-history-head">
                    <span className="account-remedy-id">Request #{item.id}</span>
                    <span className="account-remedy-history-chip">{statusLabel}</span>
                  </div>
                  <small>{formatDateTime(item.submitted_at) || '-'}</small>
                </div>
                <div className="account-remedy-history-status">
                  <strong>{selectedRemedyTitle}</strong>
                  {item.status === 'PAYMENT_PENDING' && item.payment_status !== 'PAID' && amount ? <small>{amount}</small> : null}
                  <p>{guidanceText}</p>
                </div>
                <div className="account-remedy-history-actions">
                  {needsPayment ? (
                    <button type="button" className="account-remedy-pay-btn" disabled={readOnly || payingRemedyId === item.id} onClick={() => onPay?.(item)}>
                      {payingRemedyId === item.id ? 'Opening Payment...' : `Pay Securely${amount ? ` - ${amount}` : ''}`}
                    </button>
                  ) : null}
                  {remedy.available ? (
                    <button type="button" className="account-remedy-download" disabled={readOnly} onClick={() => onViewRemedy?.(item)}>
                      View PDF
                    </button>
                  ) : null}
                  {item.feedback?.submitted ? (
                    <small>Feedback submitted</small>
                  ) : remedy.available ? (
                    <button type="button" className="account-remedy-feedback-link" disabled={readOnly} onClick={() => onSubmitFeedback?.(item)}>
                      Submit feedback
                    </button>
                  ) : null}
                </div>
                {item.admin_note ? <p className="account-remedy-history-note">{item.admin_note}</p> : null}
              </article>
            )
          })}
        </div>
      ) : (
        <article className="account-remedy-card is-empty">
          <p>No Temple Remedy request submitted yet.</p>
        </article>
      )}
    </section>
  )
}

function AppointmentSection({ appointments, isLoading, onBook, onPay, payingAppointmentId, readOnly = false }) {
  const groups = appointments?.groups || {}
  const pending = groups.pending || []
  const upcoming = groups.upcoming || []
  const completed = groups.completed || []
  const allItems = [...pending, ...upcoming, ...completed]

  return (
    <section className="account-remedy-section account-appointment-section" aria-label="Talk to Astrologer appointments">
      <div className="account-section-heading account-remedy-heading">
        <div className="account-remedy-title-row">
          <div className="account-card-icon"><Icon name="clock" /></div>
          <h2>Talk to Astrologer</h2>
        </div>
      </div>

      <article className="account-remedy-card is-empty">
        <p>Book a private online consultation with our astrologer for personalised guidance.</p>
        <button type="button" disabled={readOnly} onClick={onBook}>Book Consultation</button>
      </article>

      {isLoading && !allItems.length ? (
        <article className="account-remedy-card is-empty"><p>Loading your appointments...</p></article>
      ) : null}

      {allItems.length ? (
        <div className="account-remedy-list">
          {allItems.map((item) => {
            const confirmed = formatDateTime(item.confirmed_start_at)
            const preferred = [item.preferred_date, item.preferred_time].filter(Boolean).join(' ')
            const amount = formatMoney(item.amount_minor, item.currency)

            return (
              <article className="account-remedy-card" key={item.id}>
                <div className="account-remedy-head">
                  <span className="account-remedy-id">{item.appointment_number}</span>
                  <time>{formatDateTime(item.submitted_at) || '-'}</time>
                </div>
                <div className="account-remedy-badge-row">
                  <span>{item.status_label}</span>
                  <span>{item.questions_count} Questions</span>
                  <span>{item.payment_status_label || item.payment_status}</span>
                </div>
                <div className="account-remedy-problem">
                  <span>{confirmed ? 'Confirmed Slot' : 'Preferred Slot'}</span>
                  <p>{confirmed || preferred || 'Our team will confirm the appointment slot.'}</p>
                </div>
                {item.can_pay ? (
                  <div className="account-remedy-status-panel">
                    <button type="button" className="account-remedy-pay-btn" disabled={readOnly || payingAppointmentId === item.id} onClick={() => onPay(item)}>
                      {payingAppointmentId === item.id ? 'Opening Payment...' : `Pay Now - ${amount}`}
                    </button>
                  </div>
                ) : null}
                {(item.meeting_link || item.has_meeting_link) && ['slot_fixed', 'booked', 'upcoming'].includes(item.status) ? (
                  <div className="account-remedy-action-row">
                    {readOnly
                      ? <button type="button" className="account-remedy-download" disabled>Open Meeting Link</button>
                      : <a className="account-remedy-download" href={item.meeting_link} target="_blank" rel="noreferrer">Open Meeting Link</a>}
                  </div>
                ) : null}
              </article>
            )
          })}
        </div>
      ) : null}
    </section>
  )
}

function TempleRemedyFeedbackPrompt({
  remedyRequest,
  rating,
  comments,
  error,
  status,
  isSubmitting,
  onRatingChange,
  onCommentsChange,
  onSubmit,
  onClose,
}) {
  const remedy = remedyRequest?.remedy || {}

  if (!remedyRequest) return null

  return (
    <section className="account-remedy-viewer-overlay" role="dialog" aria-modal="true" aria-label="Temple Remedy feedback">
      <div className="account-remedy-feedback-modal">
        <button type="button" className="account-remedy-viewer-close" onClick={onClose} aria-label="Close remedy feedback">x</button>
        <span className="account-kicker">Temple Remedy</span>
        <h2>How was your remedy?</h2>
        <p className="account-remedy-feedback-intro">
          You opened {remedy.title || `Temple Remedy Request #${remedyRequest.id}`}. Share a quick rating now, or choose later.
        </p>

        <form className="account-remedy-feedback" onSubmit={onSubmit}>
          <div className="account-remedy-stars" aria-label="Remedy feedback rating">
            {[1, 2, 3, 4, 5].map((star) => (
              <button
                key={star}
                type="button"
                className={rating >= star ? 'is-active' : ''}
                onClick={() => onRatingChange(star)}
                aria-label={`${star} star rating`}
              />
            ))}
          </div>
          <label>
            Comments
            <textarea
              value={comments}
              maxLength={1000}
              rows={3}
              onChange={(event) => onCommentsChange(event.target.value)}
            />
          </label>
          {status ? <p className="account-remedy-feedback-status">{status}</p> : null}
          {error ? <p className="account-remedy-feedback-error">{error}</p> : null}
          <div className="account-remedy-viewer-actions">
            <button type="button" className="is-quiet" onClick={onClose}>No</button>
            <button type="button" className="is-secondary" onClick={onClose}>Later</button>
            <button type="submit" disabled={isSubmitting}>
              {isSubmitting ? 'Submitting...' : 'Submit Feedback'}
            </button>
          </div>
        </form>
      </div>
    </section>
  )
}

function AccountPage({ previewUserId = null }) {
  const navigate = useNavigate()
  const isPreview = previewUserId !== null
  const loadPreviewPlaybackUrl = useCallback(
    (options) => fetchAdminPredictionPlaybackUrl(previewUserId, options),
    [previewUserId],
  )
  const [history, setHistory] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState('')
  const [actionMessage, setActionMessage] = useState('')
  const [isPreparing, setIsPreparing] = useState('')
  const [player, setPlayer] = useState(null)
  const [futurePurchaseOpen, setFuturePurchaseOpen] = useState(false)
  const [isRemedyModalOpen, setIsRemedyModalOpen] = useState(false)
  const [isTalkToAstrologerOpen, setIsTalkToAstrologerOpen] = useState(false)
  const [remedyFeedbackPrompt, setRemedyFeedbackPrompt] = useState(null)
  const [remedyFeedbackRating, setRemedyFeedbackRating] = useState(0)
  const [remedyFeedbackComments, setRemedyFeedbackComments] = useState('')
  const [remedyFeedbackStatus, setRemedyFeedbackStatus] = useState('')
  const [remedyFeedbackError, setRemedyFeedbackError] = useState('')
  const [isSubmittingRemedyFeedback, setIsSubmittingRemedyFeedback] = useState(false)
  const [payingRemedyId, setPayingRemedyId] = useState(null)
  const [payingAppointmentId, setPayingAppointmentId] = useState(null)
  const abortControllerRef = useRef(null)
  const pendingRemedyFeedbackRef = useRef(null)
  const openedRemedyUrlsRef = useRef([])

  const loadHistory = useCallback(async ({ force = false, background = false } = {}) => {
    abortControllerRef.current?.abort()
    const controller = new AbortController()
    abortControllerRef.current = controller

    if (force && !isPreview) {
      clearMyAccountPredictionCache()
    }

    if (!background) {
      setIsLoading(true)
      setError('')
    }

    try {
      const data = isPreview
        ? await fetchAdminUserAccount(previewUserId, controller.signal)
        : await fetchMyAccountPredictions({ signal: controller.signal, force })
      setHistory(data)
    } catch (loadError) {
      if (loadError?.name === 'CanceledError' || loadError?.code === 'ERR_CANCELED') return

      if (loadError?.response?.status === 401) {
        const previewPath = `/admin/users/${previewUserId}/account`
        navigate(isPreview ? `/admin-login?redirect=${encodeURIComponent(previewPath)}` : '/login?redirect=/account', { replace: true })
        return
      }

      if (!background) setError(isPreview && loadError?.response?.status === 404
        ? 'User not found.'
        : 'We could not load the prediction history right now.')
    } finally {
      if (abortControllerRef.current === controller) {
        setIsLoading(false)
        abortControllerRef.current = null
      }
    }
  }, [navigate, isPreview, previewUserId])

  useEffect(() => {
    loadHistory({ force: true })

    return () => {
      abortControllerRef.current?.abort()
    }
  }, [loadHistory])

  const futureRanges = useMemo(() => history?.future?.ranges || [], [history?.future?.ranges])
  const canOpenFutureRange = (range) => isPreview ? range.can_open : accessFromWatch(range.watch)
  const watchableFutureRanges = futureRanges.filter((range) => (range.watch_ready ?? range.has_result) && canOpenFutureRange(range))
  const firstWatchableFuture = watchableFutureRanges[0]
  const firstPreparableFuture = futureRanges.find(canOpenFutureRange)
  const resumePayment = history?.future?.resume_payment
  const pendingPayment = history?.future?.payment_pending
  const preparingFuture = history?.future?.activation_pending
  const preparingPast = history?.past?.action === 'preparing'
  const futureActionRange = firstWatchableFuture || firstPreparableFuture
  const identity = history?.identity
  const submittedDetails = identity?.submitted_details || {}
  const pastYears = yearParts(history?.past?.start_year, history?.past?.end_year)
  const futurePeriodYears = useMemo(() => {
    if (!futureRanges.length) return null

    return yearParts(futureRanges[0]?.start_year, futureRanges[futureRanges.length - 1]?.end_year)
  }, [futureRanges])
  const upcomingYears = yearParts(history?.future?.next_purchase?.start_year, history?.future?.next_purchase?.end_year)
  const nextFutureYearsLabel = periodLabel(history?.future?.next_purchase?.start_year, history?.future?.next_purchase?.end_year)
  const templeRemedies = history?.temple_remedies || []
  const appointments = history?.appointments || { groups: { pending: [], upcoming: [], completed: [] } }
  const astrologyRows = [
    { icon: 'planet', label: 'Rasi', value: displayValue(identity?.astrology?.rasi) },
    { icon: 'constellation', label: 'Nakshatra', value: displayValue(identity?.astrology?.nakshatra) },
    { icon: 'compass', label: 'Lagnam', value: displayValue(identity?.astrology?.lagnam) },
  ]

  const rememberAccess = (access) => {
    if (!access?.predictionRequestId || !access?.requestUuid) return

    savePredictionSession({
      predictionRequestId: access.predictionRequestId,
      requestUuid: access.requestUuid,
      predictionType: 'past',
      otpVerified: true,
      resultReady: true,
      status: 'ready',
    })
  }

  const openPredictionPlayer = async ({ type, watch, entitlementId }) => {
    const access = accessFromWatch(watch)

    if (!isPreview && !access) {
      if (type === 'past') {
        navigate('/past-prediction')
        return
      }

      setActionMessage('Complete your birth details before opening Future Prediction.')
      return
    }

    if (!isPreview) rememberAccess(access)
    setIsPreparing(type)
    setActionMessage('')

    try {
      let result

      if (isPreview) {
        result = await fetchAdminAccountPrediction(previewUserId, { type, entitlementId })
      } else if (type === 'future') {
        try {
          result = await fetchStoredPredictionResult({ ...access, type: 'future', entitlementId })
        } catch {
          result = await fetchPredictionResult({ ...access, type: 'future', entitlementId })
        }
      } else {
        try {
          result = await fetchStoredPredictionResult({ ...access, type: 'past' })
        } catch {
          result = await fetchPredictionResult({ ...access, type: 'past' })
        }
      }

      const playableResult = !isPreview && type === 'past' ? ensureIdentityVideo(result, access) : result
      const playlist = buildPredictionPlaylist(playableResult, type)

      if (!playlist.length) {
        setActionMessage(type === 'future'
          ? 'Your Future Prediction is not playable yet. Please try again shortly.'
          : 'Your Past Prediction is not playable yet. Please try again shortly.')
        setIsPreparing('')
        return
      }

      setPlayer({
        type,
        playlist,
        resultId: playableResult?.result_id,
        access: isPreview ? { entitlementId } : access,
      })
      setIsPreparing('')
      if (!isPreview) loadHistory({ force: true })
    } catch (playError) {
      setActionMessage(playError?.response?.data?.message || 'We could not open this prediction right now.')
      setIsPreparing('')
    }
  }

  const openFuturePurchase = () => {
    const pastAccess = accessFromWatch(history?.past?.watch)

    if (pastAccess) {
      rememberAccess(pastAccess)
    }

    setFuturePurchaseOpen(true)
  }

  const updateFuturePurchase = (payment) => {
    if (payment?.status !== 'AWAITING_SUBMISSION' && payment?.status !== 'PENDING') {
      loadHistory({ force: true, background: true })
      return
    }

    clearMyAccountPredictionCache()
    setHistory((current) => current && {
      ...current,
      future: {
        ...current.future,
        resume_payment: payment.status === 'AWAITING_SUBMISSION' ? payment : null,
        payment_pending: payment.status === 'PENDING' || current.future?.payment_pending,
      },
    })
  }

  const openPastBirthDetails = () => {
    clearPredictionSession()
    navigate('/past-prediction')
  }

  const futureRangeButtons = futureRanges.length > 1
    ? watchableFutureRanges.map((range) => ({
      ...range,
      onClick: () => openPredictionPlayer({ type: 'future', watch: range.watch, entitlementId: range.entitlement_id }),
    }))
    : []

  const showRemedyFeedbackPrompt = (remedyRequest) => {
    if (!remedyRequest?.id || remedyRequest.feedback?.submitted) return

    setRemedyFeedbackPrompt(remedyRequest)
    setRemedyFeedbackRating(Number(remedyRequest?.feedback?.rating) || 0)
    setRemedyFeedbackComments(remedyRequest?.feedback?.comments || '')
    setRemedyFeedbackStatus('')
    setRemedyFeedbackError('')
  }

  useEffect(() => {
    const handleFocus = () => {
      const remedyRequest = pendingRemedyFeedbackRef.current
      if (!remedyRequest) return

      pendingRemedyFeedbackRef.current = null
      window.setTimeout(() => showRemedyFeedbackPrompt(remedyRequest), 250)
    }

    window.addEventListener('focus', handleFocus)

    return () => {
      window.removeEventListener('focus', handleFocus)
      openedRemedyUrlsRef.current.forEach((url) => URL.revokeObjectURL(url))
      openedRemedyUrlsRef.current = []
    }
  }, [])

  const openRemedyViewer = async (remedyRequest) => {
    if (!remedyRequest?.id) return

    const pdfWindow = window.open('', '_blank')
    if (!pdfWindow) {
      setActionMessage('Please allow pop-ups to open the Temple Remedy PDF.')
      return
    }
    pdfWindow.opener = null

    try {
      const blob = await fetchTempleRemedyFile(remedyRequest.id)
      const pdfBlob = blob.type === 'application/pdf' ? blob : new Blob([blob], { type: 'application/pdf' })
      const objectUrl = URL.createObjectURL(pdfBlob)
      openedRemedyUrlsRef.current.push(objectUrl)
      pdfWindow.location.href = objectUrl

      if (!remedyRequest.feedback?.submitted) {
        pendingRemedyFeedbackRef.current = remedyRequest
      }
    } catch {
      pdfWindow.close()
      setActionMessage('The requested Temple Remedy PDF is unavailable.')
    }
  }

  const payTempleRemedy = async (remedyRequest) => {
    if (!remedyRequest?.id || payingRemedyId) return

    setPayingRemedyId(remedyRequest.id)
    setActionMessage('Preparing secure Temple Remedy payment...')

    try {
      const checkout = await createTempleRemedyOrder(remedyRequest.id)
      const razorpayLoaded = await loadRazorpayScript()

      if (!razorpayLoaded || !window.Razorpay || !checkout?.razorpay_key_id) {
        setActionMessage('Secure payment checkout is not configured in this environment.')
        setPayingRemedyId(null)
        return
      }

      const razorpay = new window.Razorpay({
        key: checkout.razorpay_key_id,
        amount: checkout.remedy?.amount_minor,
        currency: checkout.remedy?.currency || remedyRequest.currency || 'INR',
        name: 'Your Good Time',
        description: `Temple Remedy Request #${remedyRequest.id}`,
        order_id: checkout.razorpay_order_id,
        prefill: checkout.prefill || {},
        handler: async (response) => {
          setActionMessage('Confirming Temple Remedy payment...')

          try {
            const verification = await verifyRazorpayPayment(response)
            if (!verification.temple_remedy_paid) {
              setActionMessage('Payment was verified, but the Temple Remedy request was not updated. Please contact support.')
              return
            }

            setActionMessage('Payment completed. Your remedy will appear here once it is ready.')
            await loadHistory({ force: true })
          } catch (paymentError) {
            setActionMessage(paymentError?.response?.data?.message || 'Payment could not be verified. Please try again.')
          } finally {
            setPayingRemedyId(null)
          }
        },
        modal: {
          ondismiss: () => {
            cancelTempleRemedyPayment(remedyRequest.id).catch(() => {})
            setActionMessage('Payment was not completed. The pending request has been removed from My Account.')
            setPayingRemedyId(null)
            loadHistory({ force: true })
          },
        },
        theme: {
          color: '#ff9d2e',
        },
      })

      razorpay.open()
    } catch (paymentError) {
      setActionMessage(paymentError?.response?.data?.message || 'Payment could not be started. Please try again.')
      setPayingRemedyId(null)
    }
  }

  const payAppointment = async (appointment) => {
    if (!appointment?.id || payingAppointmentId) return

    setPayingAppointmentId(appointment.id)
    setActionMessage('Preparing secure appointment payment...')

    try {
      const checkout = await createAppointmentOrder(appointment.id)
      const razorpayLoaded = await loadRazorpayScript()

      if (!razorpayLoaded || !window.Razorpay || !checkout?.razorpay_key_id) {
        setActionMessage('Secure payment checkout is not configured in this environment.')
        setPayingAppointmentId(null)
        return
      }

      const razorpay = new window.Razorpay({
        key: checkout.razorpay_key_id,
        amount: checkout.appointment?.amount_minor,
        currency: checkout.appointment?.currency || appointment.currency || 'INR',
        name: 'Your Good Time',
        description: `Astrologer Consultation ${appointment.appointment_number}`,
        order_id: checkout.razorpay_order_id,
        prefill: checkout.prefill || {},
        handler: async (response) => {
          setActionMessage('Confirming appointment payment...')

          try {
            const verification = await verifyRazorpayPayment(response)
            if (!verification.appointment_paid) {
              setActionMessage('Payment was verified, but the appointment was not updated. Please contact support.')
              return
            }

            setActionMessage('Payment completed. Your meeting details will appear here.')
            await loadHistory({ force: true })
          } catch (paymentError) {
            setActionMessage(paymentError?.response?.data?.message || 'Payment could not be verified. Please try again.')
          } finally {
            setPayingAppointmentId(null)
          }
        },
        modal: {
          ondismiss: () => {
            setActionMessage('Payment was not completed. You can try again whenever you are ready.')
            setPayingAppointmentId(null)
          },
        },
        theme: {
          color: '#ff9d2e',
        },
      })

      razorpay.open()
    } catch (paymentError) {
      setActionMessage(paymentError?.response?.data?.message || 'Payment could not be started. Please try again.')
      setPayingAppointmentId(null)
    }
  }

  const submitRemedyFeedback = async (event) => {
    event.preventDefault()

    if (!remedyFeedbackPrompt?.id) return

    if (!remedyFeedbackRating) {
      setRemedyFeedbackError('Please choose a rating before submitting feedback.')
      return
    }

    setIsSubmittingRemedyFeedback(true)
    setRemedyFeedbackError('')
    setRemedyFeedbackStatus('')

    try {
      const response = await submitTempleRemedyFeedback(remedyFeedbackPrompt.id, {
        rating: remedyFeedbackRating,
        comments: remedyFeedbackComments.trim() || null,
      })
      setRemedyFeedbackStatus(response?.message || 'Thank you for your Temple Remedy feedback.')
      await loadHistory({ force: true })
      setRemedyFeedbackPrompt((current) => current ? { ...current, feedback: response?.data?.feedback || current.feedback } : current)
      window.setTimeout(() => {
        setRemedyFeedbackPrompt(null)
        setActionMessage('Thank you for your Temple Remedy feedback.')
      }, 450)
    } catch (feedbackError) {
      setRemedyFeedbackError(feedbackError?.response?.data?.message || 'We could not save your feedback right now.')
    } finally {
      setIsSubmittingRemedyFeedback(false)
    }
  }

  return (
    <main className={isPreview ? 'account-page is-admin-preview' : 'account-page'}>
      <section className="account-shell">
        <header className="home-hero-header account-header" aria-label="Account page header">
          <div className="home-hero-brand">
            <img src={ygtLogo} alt="YGT" className="home-hero-logo" />
            <div className="home-hero-brand-copy">
              <h1>Your Good Time</h1>
              <p>Your Journey Written in the Stars</p>
            </div>
          </div>

          {isPreview ? (
            <div className="account-preview-header">
              <span>Read-only admin preview</span>
              <Link to="/admin">Back to Users</Link>
            </div>
          ) : <HeaderAuthActions />}
        </header>

        <section className="account-dashboard" aria-label="My Account dashboard">
          <ProfileHero
            identity={identity}
            submittedDetails={submittedDetails}
            astrologyRows={astrologyRows}
            isLoading={isLoading}
          />

          {error ? (
            <section className="account-state-card" role="alert">
              <h2>Prediction History Unavailable</h2>
              <p>{error}</p>
              <button type="button" className="account-primary-btn" onClick={() => loadHistory({ force: true })}>Retry</button>
            </section>
          ) : (
            <>
              <section className="account-prediction-grid" aria-label="Your Predictions">
                <PredictionCard
                  tone="past"
                  icon="sun"
                  title="Past Prediction"
                  copy="Watch your prepared life journey reading."
                  years={history?.past?.available ? pastYears : null}
                  fallbackLabel={isLoading ? 'Loading' : 'Pending'}
                  buttonLabel={preparingPast ? null : isPreparing === 'past' ? 'Opening...' : isLoading ? 'Loading...' : history?.past?.watch_ready ? 'Watch Prediction' : 'Prepare Past Prediction'}
                  disabled={(isPreview && !history?.past?.watch_ready) || Boolean(isPreparing) || isLoading}
                  onAction={() => history?.past?.available
                    ? openPredictionPlayer({ type: 'past', watch: history.past.watch })
                    : openPastBirthDetails()}
                  note={preparingPast ? t('preparing') : history?.past?.available
                    ? null
                    : history?.past?.message || 'Complete your details to create your reading.'}
                />

                <PredictionCard
                  tone="future"
                  icon="moon"
                  title="Future Prediction"
                  copy={history?.future?.purchased
                    ? 'Watch your purchased future reading.'
                    : 'Unlock your next future reading.'}
                  years={history?.future?.purchased ? futurePeriodYears : upcomingYears}
                  fallbackLabel={isLoading ? 'Loading' : null}
                  buttonLabel={isPreparing === 'future' ? 'Opening...'
                    : isLoading ? 'Loading...'
                      : firstWatchableFuture ? 'Watch Prediction'
                        : resumePayment ? t('resume')
                          : firstPreparableFuture ? t('prepare')
                            : (pendingPayment || preparingFuture) ? null
                              : 'Unlock Future Prediction'}
                  disabled={(isPreview && !firstWatchableFuture) || Boolean(isPreparing) || isLoading}
                  onAction={() => firstWatchableFuture || (!resumePayment && firstPreparableFuture)
                    ? openPredictionPlayer({ type: 'future', watch: futureActionRange.watch, entitlementId: futureActionRange.entitlement_id })
                    : openFuturePurchase()}
                  note={pendingPayment ? t('pending')
                    : preparingFuture ? t('preparing')
                      : history?.future?.purchased || resumePayment ? null : history?.future?.next_purchase?.message}
                  rangeButtons={futureRangeButtons}
                  secondaryAction={firstWatchableFuture && resumePayment
                    ? { label: t('resume'), disabled: isPreview || isLoading, onClick: openFuturePurchase }
                    : history?.future?.purchased && history?.future?.next_purchase?.available && !pendingPayment && !resumePayment && !preparingFuture
                      ? {
                          label: 'Unlock Next Future Prediction',
                          years: nextFutureYearsLabel,
                          disabled: isPreview || isLoading,
                          onClick: openFuturePurchase,
                        }
                      : null}
                />
              </section>

              <TempleRemedySection
                readOnly={isPreview}
                remedies={templeRemedies}
                isLoading={isLoading}
                onRequest={() => setIsRemedyModalOpen(true)}
                onPay={payTempleRemedy}
                onViewRemedy={openRemedyViewer}
                onSubmitFeedback={showRemedyFeedbackPrompt}
                payingRemedyId={payingRemedyId}
              />

              <AppointmentSection
                readOnly={isPreview}
                appointments={appointments}
                isLoading={isLoading}
                onBook={() => setIsTalkToAstrologerOpen(true)}
                onPay={payAppointment}
                payingAppointmentId={payingAppointmentId}
              />
            </>
          )}

          {actionMessage ? <p className="account-action-message" role="status">{actionMessage}</p> : null}
        </section>

        <PrimaryNav accountPreview={isPreview} />
        <AppFooter />
      </section>

      {player ? (
        <PredictionPlayer
          playlist={player.playlist}
          onClose={() => setPlayer(null)}
          onComplete={() => setPlayer(null)}
          completionTitle={player.type === 'future' ? 'Your Future Reading Is Complete' : 'Your Past Reading Is Complete'}
          predictionRequestAccess={player.access}
          predictionResultId={player.resultId}
          predictionType={player.type}
          loadPlaybackUrl={isPreview ? loadPreviewPlaybackUrl : undefined}
          readOnly={isPreview}
        />
      ) : null}

      {futurePurchaseOpen ? (
        <FuturePurchaseModal
          resumePayment={resumePayment}
          onClose={() => setFuturePurchaseOpen(false)}
          onUpdated={updateFuturePurchase}
          onNeedBirthDetails={() => {
            setFuturePurchaseOpen(false)
            navigate('/past-prediction')
          }}
        />
      ) : null}

      {isRemedyModalOpen ? (
        <TempleRemedyModal
          isAuthenticated
          onClose={() => setIsRemedyModalOpen(false)}
          onSubmitted={() => {
            loadHistory({ force: true })
            window.setTimeout(() => setIsRemedyModalOpen(false), 900)
          }}
        />
      ) : null}

      {!isPreview && (
        <TalkToAstrologerModal
          open={isTalkToAstrologerOpen}
          onClose={() => setIsTalkToAstrologerOpen(false)}
          onRequireLogin={() => {}}
          onSubmitted={() => loadHistory({ force: true })}
        />
      )}

      {remedyFeedbackPrompt ? (
        <TempleRemedyFeedbackPrompt
          remedyRequest={remedyFeedbackPrompt}
          rating={remedyFeedbackRating}
          comments={remedyFeedbackComments}
          error={remedyFeedbackError}
          status={remedyFeedbackStatus}
          isSubmitting={isSubmittingRemedyFeedback}
          onRatingChange={setRemedyFeedbackRating}
          onCommentsChange={setRemedyFeedbackComments}
          onSubmit={submitRemedyFeedback}
          onClose={() => setRemedyFeedbackPrompt(null)}
        />
      ) : null}
    </main>
  )
}

export default AccountPage
