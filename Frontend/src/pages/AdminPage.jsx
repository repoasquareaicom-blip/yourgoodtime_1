import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Navigate } from 'react-router-dom'
import {
  fetchAdminOverview,
  fetchAdminAppointments,
  fetchAdminAppointmentSlots,
  fetchAdminAppointmentMonthSlots,
  fetchAdminAppointmentManualPaymentFile,
  fetchAdminPreferredSlotAvailability,
  fetchAdminPredictionRequestDetails,
  fetchAdminPredictionRequests,
  fetchAdminPredictionRegenerationSettings,
  fetchAdminFeedback,
  fetchAdminGalleryVideos,
  fetchAdminTempleRemedies,
  fetchAdminUsers,
  createAdminGalleryVideo,
  updateAdminGalleryVideo,
  updateAdminGalleryVideoVisibility,
  deleteAdminGalleryVideo,
  regenerateAdminPastPrediction,
  markAdminPredictionRegeneration,
  logoutAdmin,
  updateAdminTempleRemedy,
  updateAdminAppointment,
  confirmAdminAppointmentSlot,
  fetchAdminTempleRemedyFile,
  fetchAdminTempleRemedyAstrology,
  fetchAdminTempleRemedyManualPaymentFile,
  fetchAdminChatbotFaqs,
  createAdminChatbotFaq,
  updateAdminChatbotFaq,
  deactivateAdminChatbotFaq,
  fetchAdminChatbotTopics,
  createAdminChatbotTopic,
  updateAdminChatbotTopic,
  deactivateAdminChatbotTopic,
  fetchAdminChatbotIntents,
  createAdminChatbotIntent,
  updateAdminChatbotIntent,
  deactivateAdminChatbotIntent,
  fetchAdminChatbotSettings,
  updateAdminChatbotSettings,
  clearAdminChatbotOpenAiCache,
  fetchAdminPaymentMethodSettings,
  updateAdminPaymentMethodSetting,
  fetchAdminFutureVideoManualPayments,
  fetchAdminFutureVideoManualPaymentProof,
  verifyAdminFutureVideoManualPayment,
  rejectAdminFutureVideoManualPayment,
  validateAdminPredictionExcel,
} from '../services/adminService'
import { getAdminUser, removeAdminToken, removeAdminUser } from '../utils/auth'
import { copyTextToClipboard, galleryVideoShareUrl } from '../services/galleryVideoService'
import { isAbortError, uploadGalleryVideoInChunks } from '../services/galleryVideoChunkUpload'
import './AdminPage.css'

const tabs = [
  { id: 'users', label: 'Users' },
  { id: 'past', label: 'Past Requests' },
  { id: 'future', label: 'Future Requests' },
  { id: 'remedies', label: 'Temple Remedies' },
  { id: 'appointments', label: 'Appointments' },
  { id: 'paymentSettings', label: 'Payment Settings' },
  { id: 'paymentVerification', label: 'Payment Verification' },
  { id: 'galleryVideos', label: 'Video Gallery' },
  { id: 'feedback', label: 'Feedback' },
  { id: 'chatbot', label: 'Chatbot' },
  { id: 'excelValidator', label: 'Prediction Excel Validator' },

]

const statusOptions = ['PAYMENT_PENDING', 'COMPLETED']
const appointmentFallbackGroups = ['payment_received', 'upcoming', 'completed', 'cancelled_failed']
const appointmentTimezone = 'Asia/Kolkata'
const chatbotViews = [
  { id: 'faqs', label: 'FAQs' },
  { id: 'topics', label: 'Topics' },
  { id: 'intents', label: 'Intents' },
  { id: 'settings', label: 'Settings' },
]
const paymentServiceLabels = {
  FUTURE_VIDEO: 'Future Video',
  TALK_TO_ASTROLOGER: 'Talk to Astrologer',
  TEMPLE_REMEDY: 'Temple Remedy',
}
const paymentServiceMeta = {
  FUTURE_VIDEO: { icon: 'video', tone: 'blue' },
  TALK_TO_ASTROLOGER: { icon: 'headset', tone: 'orange' },
  TEMPLE_REMEDY: { icon: 'temple', tone: 'green' },
}
const futureManualPaymentFilters = [
  { id: 'PENDING', label: 'Pending' },
  { id: 'VERIFIED', label: 'Verified' },
  { id: 'REJECTED', label: 'Rejected' },
  { id: 'ALL', label: 'All' },
]
const excelValidatorOptions = [
  {
    id: 'rule001_nakshatra_lagnam',
    label: 'Rule001 - Nakshatra / Lagnam',
    file: 'nakshatra_lagnam.xlsx',
    description: 'Checks Lagnam, Rasi, Nakshatra, Video ID and duplicate combinations.',
  },
  {
    id: 'rule002_past_workbooks',
    label: 'Rule002 - Past Workbooks',
    file: 'Past age workbooks',
    description: 'Checks age ranges, Dasha worksheets, Lagnam blocks, positions, years and video IDs.',
  },
]

const emptyGalleryVideoDraft = {
  id: null,
  title: '',
  description: '',
  category: 'General',
  video_type: 'WIDE',
  publication_status: 'DRAFT',
  display_order: 0,
  is_active: true,
  thumbnail_file: null,
  video_file: null,
}

function adminUploadErrorMessage(error) {
  const data = error.response?.data
  const firstValidationMessage = data?.errors
    ? Object.values(data.errors).flat().find(Boolean)
    : ''

  if (firstValidationMessage) return firstValidationMessage
  if (data?.message) return data.message
  if (error.response?.status === 413) return 'The selected video is larger than the server upload limit.'
  if (!error.response) return 'Unable to reach the server. Check that the backend is running and try again.'

  return 'Unable to save gallery video.'
}

function adminUploadErrorMessages(error) {
  const data = error.response?.data
  const validationMessages = data?.errors ? Object.values(data.errors).flat().filter(Boolean) : []

  if (validationMessages.length) return validationMessages

  return [adminUploadErrorMessage(error)]
}

function formatDate(value, timezone = null) {
  if (!value) return 'Not available'

  const options = {
    dateStyle: 'medium',
    timeStyle: 'short',
  }
  if (timezone) options.timeZone = timezone

  try {
    return new Intl.DateTimeFormat('en-IN', options).format(new Date(value))
  } catch {
    return new Intl.DateTimeFormat('en-IN', {
      dateStyle: 'medium',
      timeStyle: 'short',
    }).format(new Date(value))
  }
}

function dateValueInTimezone(value, timezone = null) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  if (!timezone) return value.slice(0, 10)

  try {
    const parts = new Intl.DateTimeFormat('en-CA', {
      timeZone: timezone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).formatToParts(date)
    const partMap = Object.fromEntries(parts.map((part) => [part.type, part.value]))

    return `${partMap.year}-${partMap.month}-${partMap.day}`
  } catch {
    return value.slice(0, 10)
  }
}

function zonedDateTimeParts(value, timezone = appointmentTimezone) {
  if (!value) return null
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return null

  try {
    const parts = new Intl.DateTimeFormat('en-CA', {
      timeZone: timezone,
      hourCycle: 'h23',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    }).formatToParts(date)

    return Object.fromEntries(parts.map((part) => [part.type, part.value]))
  } catch {
    return null
  }
}

function slotWallKey(value, timezone = appointmentTimezone) {
  const parts = zonedDateTimeParts(value, timezone)
  if (!parts) return ''

  return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}`
}

function instantValueInTimezone(value, timezone = null) {
  if (!value || !timezone) return value || ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value

  try {
    const partMap = zonedDateTimeParts(value, timezone)
    if (!partMap) return value
    const localAsUtc = Date.UTC(
      Number(partMap.year),
      Number(partMap.month) - 1,
      Number(partMap.day),
      Number(partMap.hour),
      Number(partMap.minute),
      Number(partMap.second),
    )
    const offsetMinutes = Math.round((localAsUtc - date.getTime()) / 60000)
    const offsetSign = offsetMinutes >= 0 ? '+' : '-'
    const absoluteOffset = Math.abs(offsetMinutes)
    const offsetHour = String(Math.floor(absoluteOffset / 60)).padStart(2, '0')
    const offsetMinute = String(absoluteOffset % 60).padStart(2, '0')

    return `${partMap.year}-${partMap.month}-${partMap.day}T${partMap.hour}:${partMap.minute}:${partMap.second}${offsetSign}${offsetHour}:${offsetMinute}`
  } catch {
    return value
  }
}

function formatMoney(amountMinor, currency = 'INR') {
  if (!amountMinor) return 'No amount'

  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency,
  }).format(amountMinor / 100)
}

function ratingAverageLabel(summary) {
  const average = Number(summary?.average)

  return Number.isFinite(average) ? average.toFixed(1) : 'N/A'
}

function amountToRupees(amountMinor) {
  const amount = Number(amountMinor)

  if (!Number.isFinite(amount) || amount <= 0) return ''

  return String(amount / 100)
}

function formatFileSize(bytes) {
  const size = Number(bytes)

  if (!Number.isFinite(size) || size <= 0) return null

  if (size < 1024 * 1024) return `${Math.round(size / 1024)} KB`

  return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

function fileKind(fileName = '', mimeType = '') {
  const source = `${fileName} ${mimeType}`.toLowerCase()

  if (source.includes('pdf')) return 'PDF'
  if (source.includes('image') || /\.(jpe?g|png|webp)$/i.test(fileName)) return 'Image'
  if (source.includes('video') || /\.(mp4|mov)$/i.test(fileName)) return 'Video'

  return 'File'
}

function labelFromKey(value) {
  return String(value || 'Not available').replaceAll('_', ' ').toLowerCase().replace(/\b\w/g, (letter) => letter.toUpperCase())
}

function languageLabel(value) {
  return ({
    ta: 'Tamil',
    en: 'English',
    hi: 'Hindi',
  })[String(value || '').toLowerCase()] || labelFromKey(value)
}

function todayValue() {
  const date = new Date()

  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function monthLabel(value) {
  if (!value) return 'Current Month'
  const date = new Date(`${value}-01T00:00:00`)
  if (Number.isNaN(date.getTime())) return value

  return date.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })
}

function addMonths(value, offset) {
  const source = value || todayValue().slice(0, 7)
  const date = new Date(`${source}-01T00:00:00`)
  if (Number.isNaN(date.getTime())) return todayValue().slice(0, 7)
  date.setMonth(date.getMonth() + offset)

  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

function defaultDateForMonth(value) {
  const today = todayValue()
  const currentMonth = today.slice(0, 7)

  if (value < currentMonth) return ''

  return value === currentMonth ? today : `${value}-01`
}

function isPastDate(value) {
  return Boolean(value) && value < todayValue()
}

function isPastInstant(value) {
  return Boolean(value) && new Date(value).getTime() < Date.now()
}

function appointmentStatusLabel(value) {
  const normalized = String(value || '').toLowerCase()
  if (['pending', 'reviewing', 'slot_proposed', 'payment_pending'].includes(normalized)) return 'Open'
  if (['booked', 'upcoming', 'slot_fixed'].includes(normalized)) return 'Slot Fixed'

  return labelFromKey(value)
}

function AdminProgress({ label }) {
  return (
    <div className="admin-progress-inline is-list-progress" role="status" aria-live="polite">
      <span>{label}</span>
      <div><i /></div>
    </div>
  )
}
function PredictionExcelValidator({
  result,
  validationStatus,
  selectedValidator,
  onValidatorChange,
  onValidate,
}) {
  const isLoading = validationStatus === 'loading'
  const selectedOption = excelValidatorOptions.find((option) => option.id === selectedValidator) || excelValidatorOptions[0]
  const isRule002 = selectedValidator === 'rule002_past_workbooks'
  const summaryItems = isRule002
    ? [
        ['Workbooks', result?.summary?.workbooks_checked ?? 0],
        ['Worksheets', result?.summary?.worksheets_checked ?? 0],
        ['Total Rows', result?.summary?.total_rows ?? 0],
        ['Valid Rows', result?.summary?.valid_rows ?? 0],
        ['Issue Rows', result?.summary?.issue_rows ?? 0],
        ['Missing Videos', result?.summary?.missing_video_ids ?? 0],
        ['Missing Positions', result?.summary?.missing_positions ?? 0],
        ['Invalid Years', result?.summary?.invalid_year_cells ?? 0],
        ['Mapping Issues', result?.summary?.mapping_mismatches ?? 0],
        ['Duplicates', result?.summary?.duplicate_combinations ?? 0],
        ['Structure Issues', result?.summary?.structural_row_problems ?? 0],
        ['Range Overlaps', result?.summary?.overlapping_age_ranges ?? 0],
      ]
    : [
        ['Total Rows', result?.summary?.total_rows ?? 0],
        ['Valid Rows', result?.summary?.valid_rows ?? 0],
        ['Issue Rows', result?.summary?.issue_rows ?? 0],
        ['Unique Lagnams', result?.summary?.unique_lagnams ?? 0],
        ['Unique Rasis', result?.summary?.unique_rasis ?? 0],
        ['Unique Nakshatras', result?.summary?.unique_nakshatras ?? 0],
      ]

  return (
    <section className="admin-section">
      <div className="admin-section-header">
        <div>
          <h2>Prediction Excel Validator</h2>
          <p>
            Validate prediction Excel files against the same configured mappings
            and workbook rules used by the prediction engine.
          </p>
        </div>
      </div>

      <div className="admin-card excel-validator-file-card">
        <div>
          <label>
            Validator
            <select
              value={selectedValidator}
              onChange={(event) => onValidatorChange(event.target.value)}
              disabled={isLoading}
            >
              {excelValidatorOptions.map((option) => (
                <option key={option.id} value={option.id}>{option.label}</option>
              ))}
            </select>
          </label>
          <strong>{selectedOption.file}</strong>
          <p>{selectedOption.description}</p>
        </div>

        <button
          type="button"
          className="admin-primary-button"
          onClick={onValidate}
          disabled={isLoading}
        >
          {isLoading ? 'Validating...' : 'Validate Excel'}
        </button>
      </div>

      {result ? (
      <>
        <div
          className={`excel-validation-result ${
            result.valid ? 'is-valid' : 'has-issues'
          }`}
        >
          <strong>
            {result.valid ? '✓ Validation Passed' : '⚠ Validation Issues Found'}
          </strong>

          <p>{result.message}</p>
        </div>

        <div className="excel-validator-summary">
          {summaryItems.map(([label, value]) => (
            <div key={label}>
              <span>{label}</span>
              <strong>{value}</strong>
            </div>
          ))}
        </div>
        {Array.isArray(result.workbooks) && result.workbooks.length > 0 ? (
          <div className="admin-card">
            <h3>Workbooks Checked</h3>

            <div className="admin-table-wrap">
              <table className="admin-table">
                <thead>
                  <tr>
                    <th>Workbook</th>
                    <th>Period</th>
                    <th>Age Range</th>
                    <th>Worksheets</th>
                    <th>Total Rows</th>
                    <th>Valid Rows</th>
                    <th>Issue Rows</th>
                    <th>Missing Videos</th>
                    <th>Status</th>
                  </tr>
                </thead>

                <tbody>
                  {result.workbooks.map((workbook) => (
                    <tr key={workbook.workbook}>
                      <td>{workbook.workbook}</td>
                      <td>{workbook.period ?? '-'}</td>
                      <td>
                        {workbook.age_range?.from != null && workbook.age_range?.to != null
                          ? `${workbook.age_range.from}-${workbook.age_range.to}`
                          : '-'}
                      </td>
                      <td>{workbook.worksheets_checked ?? 0}</td>
                      <td>{workbook.total_rows ?? 0}</td>
                      <td>{workbook.valid_rows ?? 0}</td>
                      <td>{workbook.issue_rows ?? 0}</td>
                      <td>{workbook.missing_video_ids ?? 0}</td>
                      <td>{workbook.valid ? 'Valid' : 'Issues'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        ) : null}
        {Array.isArray(result.issues) && result.issues.length > 0 ? (
          <div className="admin-card">
            <h3>Validation Issues</h3>

            <div className="admin-table-wrap">
              <table className="admin-table">
                <thead>
                  <tr>
                    {isRule002 ? <th>Workbook</th> : null}
                    {isRule002 ? <th>Worksheet</th> : null}
                    <th>Excel Row</th>
                    <th>Field</th>
                    <th>Excel Value</th>
                    <th>Normalized Value</th>
                    <th>Issue</th>
                  </tr>
                </thead>

                <tbody>
                  {result.issues.map((issue, index) => (
                    <tr key={`${issue.workbook || 'rule001'}-${issue.worksheet || 'sheet'}-${issue.row}-${issue.field}-${index}`}>
                      {isRule002 ? <td>{issue.workbook ?? '-'}</td> : null}
                      {isRule002 ? <td>{issue.worksheet ?? '-'}</td> : null}
                      <td>{issue.row ?? '-'}</td>
                      <td>{issue.field ?? '-'}</td>
                      <td>{issue.excel_value ?? '-'}</td>
                      <td>{issue.normalized_value ?? '-'}</td>
                      <td>{issue.issue ?? '-'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        ) : null}
      </>
    ) : null}
    </section>
  )
}
function AdminPage() {
  const user = getAdminUser()
  const [activeTab, setActiveTab] = useState('users')
  const [overview, setOverview] = useState(null)
  const [users, setUsers] = useState([])
  const [pastRequests, setPastRequests] = useState([])
  const [futureRequests, setFutureRequests] = useState([])
  const [feedbackItems, setFeedbackItems] = useState([])
  const [feedbackCounts, setFeedbackCounts] = useState({ all: 0, past: 0, future: 0, temple_remedy: 0 })
  const [feedbackCategory, setFeedbackCategory] = useState('all')
  const [selectedFeedback, setSelectedFeedback] = useState(null)
  const [selectedPrediction, setSelectedPrediction] = useState(null)
  const [predictionDetail, setPredictionDetail] = useState(null)
  const [predictionDetailStatus, setPredictionDetailStatus] = useState('idle')
  const [regeneratingPastId, setRegeneratingPastId] = useState(null)
  const [remedies, setRemedies] = useState([])
  const [appointments, setAppointments] = useState([])
  const [appointmentGroup, setAppointmentGroup] = useState('waiting_payment')
  const [appointmentCounts, setAppointmentCounts] = useState({ waiting_payment: 0, payment_received: 0, upcoming: 0, completed: 0, cancelled: 0 })
  const [appointmentStatus, setAppointmentStatus] = useState('idle')
  const [selectedAppointment, setSelectedAppointment] = useState(null)
  const [appointmentDraft, setAppointmentDraft] = useState({})
  const [appointmentSlots, setAppointmentSlots] = useState([])
  const [appointmentSlotsSummary, setAppointmentSlotsSummary] = useState(null)
  const [appointmentSlotsError, setAppointmentSlotsError] = useState('')
  const [appointmentSlotStatus, setAppointmentSlotStatus] = useState('idle')
  const [appointmentMonth, setAppointmentMonth] = useState('')
  const [appointmentMonthDays, setAppointmentMonthDays] = useState([])
  const [appointmentMonthStatus, setAppointmentMonthStatus] = useState('idle')
  const [preferredCheckLoading, setPreferredCheckLoading] = useState(false)
  const [preferredAvailability, setPreferredAvailability] = useState(null)
  const [savingAppointment, setSavingAppointment] = useState(false)
  const [remedyGroup, setRemedyGroup] = useState('payment_pending')
  const [remedyCounts, setRemedyCounts] = useState({ payment_pending: 0, payment_received: 0, completed: 0 })
  const [remedyStatus, setRemedyStatus] = useState('idle')
  const [edits, setEdits] = useState({})
  const [selectedRemedyId, setSelectedRemedyId] = useState(null)
  const [savingRemedyId, setSavingRemedyId] = useState(null)
  const [chatbotView, setChatbotView] = useState('faqs')
  const [chatbotFaqs, setChatbotFaqs] = useState([])
  const [chatbotTopics, setChatbotTopics] = useState([])
  const [chatbotIntents, setChatbotIntents] = useState([])
  const [chatbotSettings, setChatbotSettings] = useState(null)
  const [chatbotCacheCount, setChatbotCacheCount] = useState(0)
  const [chatbotStatus, setChatbotStatus] = useState('idle')
  const [chatbotEditor, setChatbotEditor] = useState(null)
  const [savingChatbot, setSavingChatbot] = useState(false)
  const [paymentSettings, setPaymentSettings] = useState([])
  const [paymentSettingsStatus, setPaymentSettingsStatus] = useState('idle')
  const [paymentSettingsDrafts, setPaymentSettingsDrafts] = useState({})
  const [savingPaymentService, setSavingPaymentService] = useState('')
  const [predictionRegenerationSettings, setPredictionRegenerationSettings] = useState([])
  const [markingRegenerationType, setMarkingRegenerationType] = useState('')
  const [futureManualPayments, setFutureManualPayments] = useState([])
  const [futureManualPaymentCounts, setFutureManualPaymentCounts] = useState({ PENDING: 0, VERIFIED: 0, REJECTED: 0, ALL: 0 })
  const [futureManualPaymentStatus, setFutureManualPaymentStatus] = useState('idle')
  const [futureManualPaymentFilter, setFutureManualPaymentFilter] = useState('PENDING')
  const [futureManualPaymentSearch, setFutureManualPaymentSearch] = useState('')
  const [selectedFuturePaymentProof, setSelectedFuturePaymentProof] = useState(null)
  const [savingFutureManualPaymentId, setSavingFutureManualPaymentId] = useState(null)
  const [galleryVideos, setGalleryVideos] = useState([])
  const [galleryVideoStatus, setGalleryVideoStatus] = useState('idle')
  const [galleryVideoDraft, setGalleryVideoDraft] = useState(emptyGalleryVideoDraft)
  const [savingGalleryVideo, setSavingGalleryVideo] = useState(false)
  const [galleryVideoUploadProgress, setGalleryVideoUploadProgress] = useState(null)
  const [galleryVideoUploadPhase, setGalleryVideoUploadPhase] = useState('idle')
  const [galleryVideoErrors, setGalleryVideoErrors] = useState([])
  const [updatingGalleryVideoId, setUpdatingGalleryVideoId] = useState(null)
  const galleryUploadAbortRef = useRef(null)
  const finalizedGalleryUploadRef = useRef(null)
  const [selectedExcelValidator, setSelectedExcelValidator] = useState('rule001_nakshatra_lagnam')
  const [excelValidationResult, setExcelValidationResult] = useState(null)
  const [excelValidationStatus, setExcelValidationStatus] = useState('idle')
  const [tabLoading, setTabLoading] = useState('')
  const [status, setStatus] = useState('loading')
  const [message, setMessage] = useState('')
  const remedyRequestRef = useRef(0)
  const appointmentRequestRef = useRef(0)
  const slotListRequestRef = useRef(0)
  const monthListRequestRef = useRef(0)
  const preferredCheckRequestRef = useRef(0)
  const slotListAbortRef = useRef(null)
  const monthListAbortRef = useRef(null)
  const preferredCheckAbortRef = useRef(null)

  const loadOverview = useCallback(async () => {
    const overviewData = await fetchAdminOverview()
    setOverview(overviewData)
    return overviewData
  }, [])

  const loadUsers = useCallback(async () => {
    setTabLoading('users')
    setMessage('')
    try {
      const userData = await fetchAdminUsers()
      setUsers(userData)
      return userData
    } catch (error) {
      setMessage(error.response?.data?.message || 'Unable to load users.')
      return []
    } finally {
      setTabLoading('')
    }
  }, [])

  const loadPredictionRequests = useCallback(async (type) => {
    setTabLoading(type)
    setMessage('')
    try {
      const data = await fetchAdminPredictionRequests(type)
      if (type === 'past') {
        setPastRequests(data)
      } else {
        setFutureRequests(data)
      }
      return data
    } catch (error) {
      setMessage(error.response?.data?.message || `Unable to load ${type} requests.`)
      return []
    } finally {
      setTabLoading('')
    }
  }, [])

  const loadFeedback = useCallback(async () => {
    setTabLoading('feedback')
    setMessage('')
    try {
      const response = await fetchAdminFeedback()
      setFeedbackItems(response.data)
      setFeedbackCounts(response.counts)
      return response
    } catch (error) {
      setMessage(error.response?.data?.message || 'Unable to load feedback.')
      return null
    } finally {
      setTabLoading('')
    }
  }, [])

  const loadAppointments = useCallback(async (group = appointmentGroup) => {
    const requestId = appointmentRequestRef.current + 1
    appointmentRequestRef.current = requestId
    setAppointmentStatus('loading')

    try {
      const response = await fetchAdminAppointments(group)
      if (appointmentRequestRef.current !== requestId) return null
      if (group === 'waiting_payment' && !response.data.length) {
        const nextGroup = appointmentFallbackGroups.find((item) => (response.counts?.[item] ?? (item === 'cancelled_failed' ? response.counts?.cancelled : 0) ?? 0) > 0)
        if (nextGroup) {
          setAppointmentGroup(nextGroup)
          return loadAppointments(nextGroup)
        }
      }
      setAppointments(response.data)
      setAppointmentCounts(response.counts)
      setAppointmentStatus('ready')
      return response
    } catch (error) {
      if (appointmentRequestRef.current !== requestId) return null
      setAppointmentStatus('error')
      setMessage(error.response?.data?.message || 'Unable to load appointments.')
      return null
    }
  }, [appointmentGroup])

  const loadTempleRemedies = useCallback(async (group = remedyGroup) => {
    const requestId = remedyRequestRef.current + 1
    remedyRequestRef.current = requestId
    setRemedyStatus('loading')

    try {
      const remedyResponse = await fetchAdminTempleRemedies(group)
      if (remedyRequestRef.current !== requestId) return null

      setRemedies(remedyResponse.data)
      setRemedyCounts(remedyResponse.counts)
      setEdits((current) => ({
        ...current,
        ...Object.fromEntries(remedyResponse.data.map((remedy) => [remedy.id, remedyToEdit(remedy)])),
      }))
      setRemedyStatus('ready')

      return remedyResponse
    } catch (error) {
      if (remedyRequestRef.current !== requestId) return null

      setRemedyStatus('error')
      setMessage(error.response?.data?.message || 'Unable to load temple remedy requests.')

      return null
    }
  }, [remedyGroup])

  const loadChatbot = useCallback(async (view = chatbotView) => {
    setTabLoading('chatbot')
    setChatbotStatus('loading')
    setMessage('')

    try {
      if (view === 'topics') {
        const topics = await fetchAdminChatbotTopics()
        setChatbotTopics(topics)
      } else if (view === 'intents') {
        const intents = await fetchAdminChatbotIntents()
        setChatbotIntents(intents)
      } else if (view === 'settings') {
        const response = await fetchAdminChatbotSettings()
        setChatbotSettings(response.data)
        setChatbotCacheCount(response.cacheCount)
      } else {
        const response = await fetchAdminChatbotFaqs()
        setChatbotFaqs(response.data)
        setChatbotTopics(response.topics)
        setChatbotIntents(response.intents)
      }
      setChatbotStatus('ready')
    } catch (error) {
      setChatbotStatus('error')
      setMessage(error.response?.data?.message || 'Unable to load chatbot records.')
    } finally {
      setTabLoading('')
    }
  }, [chatbotView])

  const loadPaymentSettings = useCallback(async () => {
    setTabLoading('paymentSettings')
    setPaymentSettingsStatus('loading')
    setMessage('')

    try {
      const [data, regenerationSettings] = await Promise.all([
        fetchAdminPaymentMethodSettings(),
        fetchAdminPredictionRegenerationSettings(),
      ])
      setPaymentSettings(data)
      setPredictionRegenerationSettings(regenerationSettings)
      setPaymentSettingsDrafts(Object.fromEntries(data.map((setting) => [setting.service_type, paymentSettingDraft(setting)])))
      setPaymentSettingsStatus('ready')
      return data
    } catch (error) {
      setPaymentSettingsStatus('error')
      setMessage(error.response?.data?.message || 'Unable to load payment method settings.')
      return []
    } finally {
      setTabLoading('')
    }
  }, [])

  const loadFutureManualPayments = useCallback(async (filter = futureManualPaymentFilter, search = futureManualPaymentSearch) => {
    setTabLoading('paymentVerification')
    setFutureManualPaymentStatus('loading')
    setMessage('')

    try {
      const response = await fetchAdminFutureVideoManualPayments(filter, search)
      setFutureManualPayments(response.data)
      setFutureManualPaymentCounts(response.counts)
      setFutureManualPaymentStatus('ready')
      return response
    } catch (error) {
      setFutureManualPaymentStatus('error')
      setMessage(error.response?.data?.message || 'Unable to load Future Video payment submissions.')
      return null
    } finally {
      setTabLoading('')
    }
  }, [futureManualPaymentFilter, futureManualPaymentSearch])

  const loadGalleryVideos = useCallback(async () => {
    setTabLoading('galleryVideos')
    setGalleryVideoStatus('loading')
    setMessage('')

    try {
      const data = await fetchAdminGalleryVideos()
      setGalleryVideos(data)
      setGalleryVideoStatus('ready')
      return data
    } catch (error) {
      setGalleryVideoStatus('error')
      setMessage(error.response?.data?.message || 'Unable to load gallery videos.')
      return []
    } finally {
      setTabLoading('')
    }
  }, [])

  useEffect(() => {
    let mounted = true

    async function loadAdminData() {
      setStatus('loading')
      setMessage('')

      try {
        const [overviewData, userData] = await Promise.all([
          fetchAdminOverview(),
          fetchAdminUsers(),
        ])

        if (!mounted) return

        setOverview(overviewData)
        setUsers(userData)
        setStatus('ready')
      } catch (error) {
        if (!mounted) return
        setStatus(error.response?.status === 403 ? 'forbidden' : 'error')
        setMessage(error.response?.data?.message || 'Unable to load admin dashboard.')
      }
    }

    loadAdminData()

    return () => {
      mounted = false
    }
  }, [])

  useEffect(() => () => {
    galleryUploadAbortRef.current?.abort()
  }, [])

  useEffect(() => () => {
    if (selectedFuturePaymentProof?.url) {
      URL.revokeObjectURL(selectedFuturePaymentProof.url)
    }
  }, [selectedFuturePaymentProof])
  const validatePredictionExcel = async () => {
    setExcelValidationStatus('loading')
    setExcelValidationResult(null)
    setMessage('Validating prediction Excel...')

    try {
      const result = await validateAdminPredictionExcel(selectedExcelValidator)

      setExcelValidationResult(result)
      setExcelValidationStatus('ready')
      setMessage(result.message || 'Excel validation completed.')
    } catch (error) {
      setExcelValidationStatus('error')
      setMessage(
        error.response?.data?.message ||
        'Unable to validate prediction Excel.'
      )
    }
  }
  const changeAdminTab = (tabId) => {
    if (tabId === activeTab) return

    setActiveTab(tabId)
    setMessage('')

    if (tabId === 'users' && users.length === 0) {
      loadUsers()
    }

    if (tabId === 'past' && pastRequests.length === 0) {
      loadPredictionRequests('past')
    }

    if (tabId === 'future' && futureRequests.length === 0) {
      loadPredictionRequests('future')
    }

    if (tabId === 'appointments' && appointmentStatus === 'idle') {
      loadAppointments(appointmentGroup)
    }

    if (tabId === 'paymentSettings' && paymentSettingsStatus === 'idle') {
      loadPaymentSettings()
    }

    if (tabId === 'paymentVerification' && futureManualPaymentStatus === 'idle') {
      loadFutureManualPayments(futureManualPaymentFilter, futureManualPaymentSearch)
    }

    if (tabId === 'galleryVideos' && galleryVideoStatus === 'idle') {
      loadGalleryVideos()
    }

    if (tabId === 'remedies' && remedyStatus === 'idle') {
      loadTempleRemedies(remedyGroup)
    }

    if (tabId === 'feedback' && feedbackItems.length === 0) {
      loadFeedback()
    }

    if (tabId === 'chatbot' && chatbotStatus === 'idle') {
      loadChatbot(chatbotView)
    }
  }

  const activeCount = useMemo(() => {
    if (activeTab === 'users') return users.length
    if (activeTab === 'past') return pastRequests.length
    if (activeTab === 'future') return futureRequests.length
    if (activeTab === 'feedback') return feedbackCategory === 'all' ? feedbackCounts.all : feedbackCounts[feedbackCategory] ?? 0
    if (activeTab === 'appointments') return appointmentCounts[appointmentGroup] ?? appointments.length
    if (activeTab === 'paymentSettings') return paymentSettings.length
    if (activeTab === 'paymentVerification') return futureManualPaymentCounts[futureManualPaymentFilter] ?? futureManualPayments.length
    if (activeTab === 'galleryVideos') return galleryVideos.length
    if (activeTab === 'chatbot') {
      if (chatbotView === 'topics') return chatbotTopics.length
      if (chatbotView === 'intents') return chatbotIntents.length
      if (chatbotView === 'settings') return chatbotSettings ? 1 : 0
      return chatbotFaqs.length
    }
    return remedyCounts[remedyGroup] ?? remedies.length
  }, [activeTab, appointmentCounts, appointmentGroup, appointments.length, chatbotFaqs.length, chatbotIntents.length, chatbotSettings, chatbotTopics.length, chatbotView, feedbackCategory, feedbackCounts, futureManualPaymentCounts, futureManualPaymentFilter, futureManualPayments.length, futureRequests.length, galleryVideos.length, pastRequests.length, paymentSettings.length, remedies.length, remedyCounts, remedyGroup, users.length])
  const ratingSummary = useMemo(() => {
    const backendSummary = overview?.cards?.ratings
    if (backendSummary?.overall?.count) return backendSummary

    const rows = feedbackItems
      .map((item) => ({ category: item.category, rating: Number(item.rating) }))
      .filter((item) => Number.isFinite(item.rating))
    const summarize = (category = null) => {
      const scoped = category ? rows.filter((item) => item.category === category) : rows
      if (!scoped.length) return { average: null, count: 0 }

      return {
        average: scoped.reduce((total, item) => total + item.rating, 0) / scoped.length,
        count: scoped.length,
      }
    }

    return {
      overall: summarize(),
      past: summarize('past'),
      future: summarize('future'),
      temple_remedy: summarize('temple_remedy'),
    }
  }, [feedbackItems, overview?.cards?.ratings])

  const handleLogout = async () => {
    try {
      await logoutAdmin()
    } catch {
      // Local cleanup is enough if the token is already invalid.
    }

    removeAdminToken()
    removeAdminUser()
    window.location.assign('/admin-login')
  }

  const updateGalleryVideoDraft = (key, value) => {
    if (key === 'video_file') {
      finalizedGalleryUploadRef.current = null
    }

    setGalleryVideoDraft((current) => ({
      ...current,
      [key]: value,
    }))
  }

  const editGalleryVideo = (video) => {
    setGalleryVideoDraft({
      id: video.id,
      title: video.title || '',
      description: video.description || '',
      category: video.category || 'General',
      video_type: video.video_type || 'WIDE',
      publication_status: video.publication_status || 'DRAFT',
      display_order: video.display_order ?? 0,
      is_active: Boolean(video.is_active),
      thumbnail_file: null,
      video_file: null,
    })
  }

  const clearGalleryVideoDraft = () => {
    finalizedGalleryUploadRef.current = null
    setGalleryVideoDraft(emptyGalleryVideoDraft)
    setGalleryVideoUploadProgress(null)
    setGalleryVideoUploadPhase('idle')
    setGalleryVideoErrors([])
  }

  const resetGalleryVideoDraft = () => {
    if (savingGalleryVideo) {
      galleryUploadAbortRef.current?.abort()
      return
    }

    clearGalleryVideoDraft()
  }

  const cancelGalleryVideoUpload = () => {
    galleryUploadAbortRef.current?.abort()
  }

  const saveGalleryVideo = async (event) => {
    event.preventDefault()
    if (savingGalleryVideo) return

    if (!galleryVideoDraft.id && !galleryVideoDraft.video_file && !finalizedGalleryUploadRef.current) {
      setMessage('Choose a video file before saving.')
      return
    }

    const abortController = new AbortController()
    galleryUploadAbortRef.current = abortController
    setSavingGalleryVideo(true)
    setGalleryVideoErrors([])
    setGalleryVideoUploadProgress(galleryVideoDraft.video_file ? 0 : null)
    setGalleryVideoUploadPhase(galleryVideoDraft.video_file ? 'uploading' : 'saving')
    setMessage(galleryVideoDraft.id ? 'Updating gallery video...' : 'Saving gallery video...')

    try {
      const payload = new FormData()
      payload.append('title', galleryVideoDraft.title)
      payload.append('description', galleryVideoDraft.description || '')
      payload.append('category', galleryVideoDraft.category || 'General')
      payload.append('video_type', galleryVideoDraft.video_type)
      payload.append('publication_status', galleryVideoDraft.publication_status)
      payload.append('display_order', galleryVideoDraft.display_order ?? 0)
      payload.append('is_active', galleryVideoDraft.is_active ? '1' : '0')
      if (galleryVideoDraft.thumbnail_file) payload.append('thumbnail_file', galleryVideoDraft.thumbnail_file)

      if (galleryVideoDraft.video_file) {
        const file = galleryVideoDraft.video_file
        const cached = finalizedGalleryUploadRef.current
        const canReuse = cached
          && cached.fileName === file.name
          && cached.fileSize === file.size
          && cached.fileLastModified === file.lastModified
        let uploadId = canReuse ? cached.uploadId : null

        if (!uploadId) {
          const result = await uploadGalleryVideoInChunks({
            file,
            signal: abortController.signal,
            onProgress: ({ percent, phase }) => {
              setGalleryVideoUploadProgress(percent)
              setGalleryVideoUploadPhase(phase)
              setMessage(phase === 'processing' ? 'Processing video...' : `Uploading video... ${percent}%`)
            },
          })
          uploadId = result.uploadId
          finalizedGalleryUploadRef.current = {
            uploadId,
            fileName: file.name,
            fileSize: file.size,
            fileLastModified: file.lastModified,
          }
        }

        payload.append('upload_id', uploadId)
        setGalleryVideoUploadProgress(100)
        setGalleryVideoUploadPhase('processing')
        setMessage('Processing video...')
      }

      const response = galleryVideoDraft.id
        ? await updateAdminGalleryVideo(galleryVideoDraft.id, payload, { signal: abortController.signal })
        : await createAdminGalleryVideo(payload, { signal: abortController.signal })

      finalizedGalleryUploadRef.current = null
      setMessage(response.message || 'Video uploaded successfully.')
      clearGalleryVideoDraft()
      await loadGalleryVideos()
    } catch (error) {
      if (isAbortError(error)) {
        setMessage('Video upload cancelled.')
        return
      }

      const messages = adminUploadErrorMessages(error)
      setGalleryVideoErrors(messages)
      setMessage(messages[0] || 'Unable to save gallery video.')
    } finally {
      if (galleryUploadAbortRef.current === abortController) {
        galleryUploadAbortRef.current = null
      }
      setSavingGalleryVideo(false)
      window.setTimeout(() => {
        setGalleryVideoUploadProgress(null)
        setGalleryVideoUploadPhase('idle')
      }, 700)
    }
  }

  const toggleGalleryVideoVisibility = async (video) => {
    setUpdatingGalleryVideoId(video.id)
    setMessage(video.is_active ? 'Hiding gallery video...' : 'Showing gallery video...')

    try {
      const response = await updateAdminGalleryVideoVisibility(video.id, !video.is_active)
      setMessage(response.message || 'Gallery video updated.')
      await loadGalleryVideos()
    } catch (error) {
      setMessage(error.response?.data?.message || 'Unable to update gallery video visibility.')
    } finally {
      setUpdatingGalleryVideoId(null)
    }
  }

  const removeGalleryVideo = async (video) => {
    if (!window.confirm(`Delete "${video.title}" from the gallery?`)) return

    setUpdatingGalleryVideoId(video.id)
    setMessage('Deleting gallery video...')

    try {
      const response = await deleteAdminGalleryVideo(video.id)
      setMessage(response.message || 'Gallery video deleted.')
      if (galleryVideoDraft.id === video.id) resetGalleryVideoDraft()
      await loadGalleryVideos()
    } catch (error) {
      setMessage(error.response?.data?.message || 'Unable to delete gallery video.')
    } finally {
      setUpdatingGalleryVideoId(null)
    }
  }

  const updateRemedyEdit = (id, key, value) => {
    setEdits((current) => ({
      ...current,
      [id]: {
        ...current[id],
        [key]: value,
      },
    }))
  }

  const changeRemedyGroup = (group) => {
    if (group === remedyGroup) return

    setSelectedRemedyId(null)
    setRemedyGroup(group)
    loadTempleRemedies(group)
  }

  const changeAppointmentGroup = (group) => {
    if (group === appointmentGroup) return
    setSelectedAppointment(null)
    setAppointmentGroup(group)
    loadAppointments(group)
  }

  const openAppointment = (appointment) => {
    const appointmentTimezone = 'Asia/Kolkata'
    setSelectedAppointment(appointment)
    setAppointmentDraft({
      confirmed_date: appointment.confirmed_start_at ? dateValueInTimezone(appointment.confirmed_start_at, appointmentTimezone) : appointment.preferred_date || new Date().toISOString().slice(0, 10),
      confirmed_start_at: instantValueInTimezone(appointment.confirmed_start_at, appointmentTimezone),
      timezone: appointmentTimezone,
      meeting_provider: appointment.meeting_provider || 'manual',
      meeting_link: appointment.meeting_link || '',
      admin_note: appointment.admin_note || '',
      status: appointment.status,
      payment_status: appointment.payment_status,
      astrologer_id: appointment.astrologer_id || appointment.default_astrologer_id || appointment.available_astrologers?.[0]?.id || '',
    })
    setAppointmentSlots([])
    setAppointmentSlotsSummary(null)
    setAppointmentSlotsError('')
    setAppointmentSlotStatus('idle')
    setAppointmentMonth((appointment.preferred_date || new Date().toISOString().slice(0, 10)).slice(0, 7))
    setAppointmentMonthDays([])
    setAppointmentMonthStatus('idle')
    setPreferredAvailability(null)
    setPreferredCheckLoading(false)
  }

  const loadPreferredSlotAvailability = useCallback(async (appointmentOverride = null, astrologerIdOverride = null) => {
    const appointment = appointmentOverride || selectedAppointment
    const astrologerId = astrologerIdOverride || appointmentDraft.astrologer_id

    if (!appointment?.id || !astrologerId) return

    preferredCheckAbortRef.current?.abort()
    const controller = new AbortController()
    preferredCheckAbortRef.current = controller
    const requestId = preferredCheckRequestRef.current + 1
    preferredCheckRequestRef.current = requestId
    setPreferredCheckLoading(true)
    setPreferredAvailability(null)

    try {
      const startedAt = performance.now()
      const availability = await fetchAdminPreferredSlotAvailability(appointment.id, {
        astrologer_id: astrologerId,
      }, { signal: controller.signal })
      if (preferredCheckRequestRef.current !== requestId) return
      setPreferredAvailability(availability)
      if (import.meta.env.DEV) {
        console.debug('Admin preferred slot check completed', {
          appointment_id: appointment.id,
          astrologer_id: astrologerId,
          available: availability.available,
          duration_ms: Math.round(performance.now() - startedAt),
        })
      }
    } catch (error) {
      if (controller.signal.aborted || preferredCheckRequestRef.current !== requestId) return
      setPreferredAvailability({ available: false, reason: error.response?.data?.message || 'Unable to check requested slot.' })
    } finally {
      if (preferredCheckRequestRef.current === requestId) setPreferredCheckLoading(false)
    }
  }, [appointmentDraft.astrologer_id, selectedAppointment])

  const loadAppointmentSlots = useCallback(async (dateOverride = null, astrologerIdOverride = null, appointmentOverride = null) => {
    const appointment = appointmentOverride || selectedAppointment
    const date = dateOverride || appointmentDraft.confirmed_date
    const astrologerId = astrologerIdOverride || appointmentDraft.astrologer_id

    if (!date || !astrologerId) return
    slotListAbortRef.current?.abort()
    const controller = new AbortController()
    slotListAbortRef.current = controller
    const requestId = slotListRequestRef.current + 1
    slotListRequestRef.current = requestId
    setAppointmentSlotStatus('loading')
    setAppointmentSlotsError('')
    try {
      const startedAt = performance.now()
      const response = await fetchAdminAppointmentSlots({
        slotDate: date,
        astrologerId,
        appointmentId: appointment?.id,
      }, { signal: controller.signal })
      if (slotListRequestRef.current !== requestId) return
      const slots = response.slots || []
      setAppointmentSlots(slots)
      setAppointmentSlotsSummary(response.summary || null)
      setAppointmentSlotStatus(slots.length ? 'ready' : 'empty')
      if (import.meta.env.DEV) {
        console.debug('Admin appointment slots completed', {
          appointment_id: appointment?.id,
          astrologer_id: astrologerId,
          slot_date: date,
          slot_count: slots.length,
          duration_ms: Math.round(performance.now() - startedAt),
        })
      }
    } catch (error) {
      if (controller.signal.aborted || slotListRequestRef.current !== requestId) return
      setAppointmentSlots([])
      setAppointmentSlotsSummary(null)
      setAppointmentSlotsError(error.response?.data?.message || 'Unable to load available slots.')
      setAppointmentSlotStatus('error')
    }
  }, [appointmentDraft.astrologer_id, appointmentDraft.confirmed_date, selectedAppointment])

  const loadAppointmentMonthSlots = useCallback(async (monthOverride = null, astrologerIdOverride = null, appointmentOverride = null) => {
    const appointment = appointmentOverride || selectedAppointment
    const month = monthOverride || appointmentMonth || appointment?.preferred_date?.slice(0, 7) || todayValue().slice(0, 7)
    const astrologerId = astrologerIdOverride || appointmentDraft.astrologer_id

    if (!month || !astrologerId) return
    monthListAbortRef.current?.abort()
    const controller = new AbortController()
    monthListAbortRef.current = controller
    const requestId = monthListRequestRef.current + 1
    monthListRequestRef.current = requestId
    setAppointmentMonthStatus('loading')

    try {
      const response = await fetchAdminAppointmentMonthSlots({
        month,
        astrologerId,
        appointmentId: appointment?.id,
      }, { signal: controller.signal })
      if (monthListRequestRef.current !== requestId) return
      setAppointmentMonthDays(response.days || [])
      setAppointmentMonthStatus('ready')
    } catch {
      if (controller.signal.aborted || monthListRequestRef.current !== requestId) return
      setAppointmentMonthDays([])
      setAppointmentMonthStatus('error')
    }
  }, [appointmentDraft.astrologer_id, appointmentMonth, selectedAppointment])

  useEffect(() => {
    if (!selectedAppointment?.id || !appointmentDraft.astrologer_id) return

    loadPreferredSlotAvailability(selectedAppointment, appointmentDraft.astrologer_id)
  }, [selectedAppointment, appointmentDraft.astrologer_id, loadPreferredSlotAvailability])

  useEffect(() => {
    if (!selectedAppointment?.id || !appointmentDraft.confirmed_date || !appointmentDraft.astrologer_id) return

    loadAppointmentSlots(appointmentDraft.confirmed_date, appointmentDraft.astrologer_id, selectedAppointment)
  }, [selectedAppointment, appointmentDraft.confirmed_date, appointmentDraft.astrologer_id, loadAppointmentSlots])

  useEffect(() => {
    if (!selectedAppointment?.id || !appointmentMonth || !appointmentDraft.astrologer_id) return

    loadAppointmentMonthSlots(appointmentMonth, appointmentDraft.astrologer_id, selectedAppointment)
  }, [selectedAppointment, appointmentMonth, appointmentDraft.astrologer_id, loadAppointmentMonthSlots])

  useEffect(() => () => {
    slotListAbortRef.current?.abort()
    monthListAbortRef.current?.abort()
    preferredCheckAbortRef.current?.abort()
  }, [])

  const saveAppointment = async () => {
    if (!selectedAppointment) return
    const wantsSlotConfirmation = appointmentDraft.status === 'slot_fixed' || Boolean(appointmentDraft.confirmed_start_at)

    if (wantsSlotConfirmation && !appointmentDraft.confirmed_start_at) {
      setMessage('Select an available appointment slot.')
      return
    }

    setSavingAppointment(true)
    setMessage('Saving appointment...')
    try {
      if (appointmentDraft.confirmed_start_at) {
        await confirmAdminAppointmentSlot(selectedAppointment.id, {
          astrologer_id: appointmentDraft.astrologer_id,
          slot_start: appointmentDraft.confirmed_start_at,
          meeting_provider: appointmentDraft.meeting_provider,
          meeting_link: appointmentDraft.meeting_link || null,
          admin_note: appointmentDraft.admin_note || null,
        })
      } else {
        const payload = {
          status: appointmentDraft.status,
          payment_status: appointmentDraft.payment_status,
          meeting_provider: appointmentDraft.meeting_provider,
          meeting_link: appointmentDraft.meeting_link || null,
          admin_note: appointmentDraft.admin_note || null,
        }
        await updateAdminAppointment(selectedAppointment.id, payload)
      }
      setSelectedAppointment(null)
      setMessage('Appointment updated.')
      await loadAppointments(appointmentGroup)
    } catch (error) {
      setMessage(error.response?.data?.message || 'Unable to update appointment.')
      if (error.response?.data?.code === 'SLOT_NO_LONGER_AVAILABLE') {
        await loadAppointmentSlots(appointmentDraft.confirmed_date, appointmentDraft.astrologer_id, selectedAppointment)
      }
    } finally {
      setSavingAppointment(false)
    }
  }

  const quickUpdateAppointment = async (appointment, payload) => {
    if (!appointment?.id) return

    setSavingAppointment(true)
    setMessage('Updating appointment...')
    try {
      await updateAdminAppointment(appointment.id, payload)
      setMessage('Appointment updated.')
      await loadAppointments(appointmentGroup)
      await loadOverview()
    } catch (error) {
      setMessage(error.response?.data?.message || 'Unable to update appointment.')
    } finally {
      setSavingAppointment(false)
    }
  }

  const openPredictionDetail = async (row, type) => {
    const requestId = row.prediction_request_id || row.id
    if (!requestId || predictionDetailStatus === 'loading') return

    setSelectedPrediction({ row, type })
    setPredictionDetail(null)
    setPredictionDetailStatus('loading')
    setMessage('Loading Prediction Details...')

    try {
      const detail = await fetchAdminPredictionRequestDetails(requestId, {
        type,
        result_id: row.prediction_result_id || row.id,
        entitlement_id: row.prediction_entitlement_id || row.entitlement?.id,
      })
      setPredictionDetail(detail)
      setPredictionDetailStatus('ready')
      setMessage('')
    } catch (error) {
      setPredictionDetailStatus('error')
      setMessage(error.response?.data?.message || 'Unable to load prediction request.')
    }
  }

  const regeneratePastPrediction = async () => {
    const requestId = predictionDetail?.request?.id || selectedPrediction?.row?.prediction_request_id || selectedPrediction?.row?.id
    if (!requestId || regeneratingPastId) return

    setRegeneratingPastId(requestId)
    setMessage('Regenerating past prediction...')

    try {
      const response = await regenerateAdminPastPrediction(requestId)
      setPredictionDetail(response.data)
      setPredictionDetailStatus('ready')
      setMessage(response.message || 'Past prediction regenerated.')
      await Promise.all([loadPredictionRequests('past'), loadOverview()])
    } catch (error) {
      setMessage(error.response?.data?.message || 'Unable to regenerate past prediction.')
    } finally {
      setRegeneratingPastId(null)
    }
  }

  const saveRemedy = async (id) => {
    const draft = edits[id]
    if (!draft) return

    setMessage('Saving temple remedy workflow...')
    setSavingRemedyId(id)

    try {
      const payload = new FormData()
      payload.append('_method', 'PATCH')
      payload.append('status', draft.status)
      payload.append('payment_status', draft.payment_status || '')
      payload.append('amount_inr', draft.amount_inr === '' ? '' : draft.amount_inr)
      payload.append('currency', draft.currency || 'INR')
      payload.append('remedy_title', draft.remedy_title || '')
      payload.append('admin_note', draft.admin_note || '')
      if (draft.remedy_file) payload.append('remedy_file', draft.remedy_file)

      const response = await updateAdminTempleRemedy(id, payload)

      setEdits((current) => ({ ...current, [id]: remedyToEdit(response.data) }))
      setSelectedRemedyId(null)
      setMessage('Temple remedy request updated.')
      await loadTempleRemedies(remedyGroup)
    } catch (error) {
      setMessage(error.response?.data?.message || 'Unable to update temple remedy request.')
    } finally {
      setSavingRemedyId(null)
    }
  }

  const changeChatbotView = (view) => {
    setChatbotView(view)
    setChatbotEditor(null)
    loadChatbot(view)
  }

  const saveChatbotRecord = async (view, record) => {
    setSavingChatbot(true)
    setMessage('Saving chatbot record...')

    try {
      const isExisting = Boolean(record.id)
      const saveAction = view === 'topics'
        ? isExisting ? updateAdminChatbotTopic : createAdminChatbotTopic
        : view === 'intents'
          ? isExisting ? updateAdminChatbotIntent : createAdminChatbotIntent
          : isExisting ? updateAdminChatbotFaq : createAdminChatbotFaq

      if (isExisting) {
        await saveAction(record.id, record)
      } else {
        await saveAction(record)
      }

      setChatbotEditor(null)
      setMessage('Chatbot record saved.')
      await loadChatbot(view)
    } catch (error) {
      setMessage(error.response?.data?.message || 'Unable to save chatbot record.')
    } finally {
      setSavingChatbot(false)
    }
  }

  const deactivateChatbotRecord = async (view, record) => {
    if (!record?.id) return
    if (!window.confirm('Deactivate this chatbot record?')) return

    setSavingChatbot(true)
    setMessage('Deactivating chatbot record...')

    try {
      if (view === 'topics') {
        await deactivateAdminChatbotTopic(record.id)
      } else if (view === 'intents') {
        await deactivateAdminChatbotIntent(record.id)
      } else {
        await deactivateAdminChatbotFaq(record.id)
      }

      setChatbotEditor(null)
      setMessage('Chatbot record deactivated.')
      await loadChatbot(view)
    } catch (error) {
      setMessage(error.response?.data?.message || 'Unable to deactivate chatbot record.')
    } finally {
      setSavingChatbot(false)
    }
  }

  const saveChatbotSettings = async (settings) => {
    setSavingChatbot(true)
    setMessage('Saving chatbot settings...')

    try {
      const response = await updateAdminChatbotSettings(settings)
      setChatbotSettings(response.data)
      setChatbotCacheCount(response.cacheCount)
      setMessage(response.message || 'Chatbot settings saved.')
    } catch (error) {
      setMessage(error.response?.data?.message || 'Unable to save chatbot settings.')
    } finally {
      setSavingChatbot(false)
    }
  }

  const clearChatbotOpenAiCache = async () => {
    if (!window.confirm('Clear all cached OpenAI chatbot answers? Usage history and YGT FAQs will not be deleted.')) return

    setSavingChatbot(true)
    setMessage('Clearing OpenAI chatbot cache...')

    try {
      const response = await clearAdminChatbotOpenAiCache()
      setChatbotCacheCount(0)
      setMessage(response.message || 'OpenAI chatbot cache cleared.')
    } catch (error) {
      setMessage(error.response?.data?.message || 'Unable to clear OpenAI chatbot cache.')
    } finally {
      setSavingChatbot(false)
    }
  }

  const updatePaymentSettingDraft = (serviceType, key, value) => {
    setPaymentSettingsDrafts((current) => ({
      ...current,
      [serviceType]: {
        ...paymentSettingDraft(paymentSettings.find((setting) => setting.service_type === serviceType)),
        ...current[serviceType],
        [key]: value,
      },
    }))
  }

  const savePaymentSetting = async (serviceType) => {
    const draft = paymentSettingsDrafts[serviceType]
    if (!draft) return

    setSavingPaymentService(serviceType)
    setMessage(`Saving ${paymentServiceLabels[serviceType] || serviceType} payment settings...`)

    try {
      const payload = new FormData()
      payload.append('_method', 'PUT')
      payload.append('service_type', serviceType)
      payload.append('payment_gateway_enabled', draft.payment_gateway_enabled ? '1' : '0')
      payload.append('gpay_qr_enabled', draft.gpay_qr_enabled ? '1' : '0')
      if (draft.qr_image) payload.append('qr_image', draft.qr_image)

      const response = await updateAdminPaymentMethodSetting(serviceType, payload)
      const saved = response.data
      setPaymentSettings((current) => current.map((setting) => (setting.service_type === serviceType ? saved : setting)))
      setPaymentSettingsDrafts((current) => ({ ...current, [serviceType]: paymentSettingDraft(saved) }))
      setMessage(response.message || 'Payment method setting saved.')
    } catch (error) {
      setMessage(error.response?.data?.message || 'Unable to save payment method setting.')
    } finally {
      setSavingPaymentService('')
    }
  }

  const markPredictionRegeneration = async (predictionType) => {
    const label = predictionType === 'future' ? 'Future' : 'Past'
    const confirmed = window.confirm(`Existing ${label} prediction results will be recalculated from the current prediction data the next time each prediction is accessed. Continue?`)
    if (!confirmed) return

    setMarkingRegenerationType(predictionType)
    setMessage(`Marking ${label} predictions for regeneration...`)

    try {
      const response = await markAdminPredictionRegeneration(predictionType)
      setPredictionRegenerationSettings((current) => current.map((setting) => (
        setting.prediction_type === predictionType ? response.data : setting
      )))
      setMessage(response.message || `${label} predictions marked for regeneration.`)
    } catch (error) {
      setMessage(error.response?.data?.message || `Unable to mark ${label} predictions for regeneration.`)
    } finally {
      setMarkingRegenerationType('')
    }
  }

  const changeFutureManualPaymentFilter = (filter) => {
    setFutureManualPaymentFilter(filter)
    loadFutureManualPayments(filter, futureManualPaymentSearch)
  }

  const searchFutureManualPayments = (event) => {
    event.preventDefault()
    loadFutureManualPayments(futureManualPaymentFilter, futureManualPaymentSearch)
  }

  const refreshFutureManualPayments = () => {
    loadFutureManualPayments(futureManualPaymentFilter, futureManualPaymentSearch)
  }

  const closeFuturePaymentProof = () => {
    if (selectedFuturePaymentProof?.url) {
      URL.revokeObjectURL(selectedFuturePaymentProof.url)
    }
    setSelectedFuturePaymentProof(null)
  }

  const openFuturePaymentProof = async (payment) => {
    setMessage('Loading payment proof...')

    try {
      const blob = await fetchAdminFutureVideoManualPaymentProof(payment.id)
      if (selectedFuturePaymentProof?.url) {
        URL.revokeObjectURL(selectedFuturePaymentProof.url)
      }
      setSelectedFuturePaymentProof({
        payment,
        url: URL.createObjectURL(blob),
        mimeType: blob.type || payment.payment_proof?.mime_type || '',
        fileName: payment.payment_proof?.file_name || 'payment-proof',
      })
      setMessage('')
    } catch (error) {
      setMessage(error.response?.data?.message || 'Unable to load payment proof.')
    }
  }

  const verifyFutureManualPayment = async (payment) => {
    if (!window.confirm('Confirm this GPay QR payment has been received? Future Video access will be enabled after verification.')) return

    setSavingFutureManualPaymentId(payment.id)
    setMessage('Verifying Future Video payment...')

    try {
      const response = await verifyAdminFutureVideoManualPayment(payment.id)
      setMessage(response.message || 'Payment verified and Future Video enabled.')
      await loadFutureManualPayments(futureManualPaymentFilter, futureManualPaymentSearch)
    } catch (error) {
      setMessage(error.response?.data?.message || 'Unable to verify payment.')
    } finally {
      setSavingFutureManualPaymentId(null)
    }
  }

  const rejectFutureManualPayment = async (payment) => {
    const rejectionReason = window.prompt('Reason for rejection (optional)')
    if (rejectionReason === null) return

    setSavingFutureManualPaymentId(payment.id)
    setMessage('Rejecting Future Video payment...')

    try {
      const response = await rejectAdminFutureVideoManualPayment(payment.id, rejectionReason.trim())
      setMessage(response.message || 'Payment rejected.')
      await loadFutureManualPayments(futureManualPaymentFilter, futureManualPaymentSearch)
    } catch (error) {
      setMessage(error.response?.data?.message || 'Unable to reject payment.')
    } finally {
      setSavingFutureManualPaymentId(null)
    }
  }

  if (status === 'forbidden' || (status !== 'loading' && !user?.is_admin)) {
    return <Navigate to="/admin-login" replace />
  }

  const activeTabLabel = tabs.find((tab) => tab.id === activeTab)?.label || 'Records'
  const isActiveTabLoading = tabLoading === activeTab

  return (
    <main className="admin-shell">
      <aside className="admin-sidebar">
        <div className="admin-brand">
          <span className="admin-mark">YGT</span>
          <div>
            <strong>Your Good Time</strong>
            <span>Admin workspace</span>
          </div>
        </div>
        <nav className="admin-tabs" aria-label="Admin sections">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              type="button"
              className={activeTab === tab.id ? 'is-active' : ''}
              onClick={() => changeAdminTab(tab.id)}
            >
              {tab.label}
            </button>
          ))}
        </nav>
        <button type="button" className="admin-sidebar-logout" onClick={handleLogout}>Logout</button>
      </aside>

      <section className="admin-main">
        <header className="admin-header">
          <div>
            <p>Admin Dashboard</p>
            <h1>Operations Control Center</h1>
          </div>
          <div className="admin-user-chip">
            <span>{user?.name?.charAt(0)?.toUpperCase() || 'A'}</span>
            <div>
              <strong>{user?.name || 'Admin'}</strong>
              <small>{user?.email}</small>
            </div>
            <button type="button" onClick={handleLogout}>Logout</button>
          </div>
        </header>

        <section className="admin-metrics" aria-label="Dashboard summary">
          <Metric label="Users" value={overview?.cards?.users} icon="users" tone="blue" />
          <Metric label="Past Requests" value={overview?.cards?.past_requests} icon="sun" tone="gold" />
          <Metric label="Future Requests" value={overview?.cards?.future_requests} icon="moon" tone="violet" />
          <Metric label="Temple Remedies" value={overview?.cards?.temple_remedies} icon="star" tone="green" />
          <Metric label="Pending Appointments" value={overview?.cards?.pending_appointments} icon="clock" tone="orange" />
          <Metric label="Upcoming Appointments" value={overview?.cards?.upcoming_appointments} icon="calendar" tone="cyan" />
          <Metric
            label="Overall Rating"
            value={ratingAverageLabel(ratingSummary?.overall)}
            icon="rating"
            tone="pink"
            suffix={ratingAverageLabel(ratingSummary?.overall) === 'N/A' ? '' : '/5'}
            details={[
              { label: 'Past', value: ratingAverageLabel(ratingSummary?.past) },
              { label: 'Future', value: ratingAverageLabel(ratingSummary?.future) },
              { label: 'Temple', value: ratingAverageLabel(ratingSummary?.temple_remedy) },
            ]}
          />
        </section>

        <section className="admin-panel">
          <div className="admin-panel-head">
            <div>
              <p>{activeCount} records</p>
              <h2>{activeTabLabel}</h2>
            </div>
            {message ? <span className="admin-message">{message}</span> : null}
          </div>

          {status === 'loading' ? <AdminProgress label="Loading admin data..." /> : null}
          {status === 'error' ? <div className="admin-empty">{message}</div> : null}
          {status === 'ready' && isActiveTabLoading ? <AdminProgress label={`Loading ${activeTabLabel}...`} /> : null}
          {status === 'ready' && !isActiveTabLoading && activeTab === 'users' ? <UsersTable users={users} /> : null}
          {status === 'ready' && !isActiveTabLoading && activeTab === 'past' ? (
            <PredictionRequestTable
              type="past"
              requests={pastRequests}
              loadingId={predictionDetailStatus === 'loading' ? selectedPrediction?.row?.id : null}
              onOpen={openPredictionDetail}
            />
          ) : null}
          {status === 'ready' && !isActiveTabLoading && activeTab === 'future' ? (
            <PredictionRequestTable
              type="future"
              requests={futureRequests}
              loadingId={predictionDetailStatus === 'loading' ? selectedPrediction?.row?.id : null}
              onOpen={openPredictionDetail}
            />
          ) : null}
          {status === 'ready' && !isActiveTabLoading && activeTab === 'appointments' ? (
              <AppointmentList
                appointments={appointments}
                group={appointmentGroup}
                counts={appointmentCounts}
                isLoading={appointmentStatus === 'loading'}
                isError={appointmentStatus === 'error'}
                selectedAppointment={selectedAppointment}
                draft={appointmentDraft}
                slots={appointmentSlots}
                slotsSummary={appointmentSlotsSummary}
                slotsError={appointmentSlotsError}
                slotStatus={appointmentSlotStatus}
                month={appointmentMonth}
                monthDays={appointmentMonthDays}
                monthStatus={appointmentMonthStatus}
                preferredCheckLoading={preferredCheckLoading}
                preferredAvailability={preferredAvailability}
                isSaving={savingAppointment}
                onGroupChange={changeAppointmentGroup}
                onOpen={openAppointment}
              onClose={() => setSelectedAppointment(null)}
                onDraftChange={(key, value) => setAppointmentDraft((current) => ({ ...current, [key]: value }))}
              onMonthChange={setAppointmentMonth}
              onLoadSlots={loadAppointmentSlots}
              onSave={saveAppointment}
              onQuickUpdate={quickUpdateAppointment}
            />
          ) : null}
          {status === 'ready' && !isActiveTabLoading && activeTab === 'remedies' ? (
            <RemedyList
              remedies={remedies}
              group={remedyGroup}
              counts={remedyCounts}
              isLoading={remedyStatus === 'loading'}
              isError={remedyStatus === 'error'}
              edits={edits}
              selectedRemedyId={selectedRemedyId}
              onGroupChange={changeRemedyGroup}
              onSelect={setSelectedRemedyId}
              onClose={() => setSelectedRemedyId(null)}
              onChange={updateRemedyEdit}
              onSave={saveRemedy}
              savingRemedyId={savingRemedyId}
            />
          ) : null}
          {status === 'ready' && !isActiveTabLoading && activeTab === 'paymentSettings' ? (
            <>
              <PredictionRegenerationPanel
                settings={predictionRegenerationSettings}
                markingType={markingRegenerationType}
                onMark={markPredictionRegeneration}
              />
              <PaymentMethodSettingsPanel
                settings={paymentSettings}
                drafts={paymentSettingsDrafts}
                status={paymentSettingsStatus}
                savingService={savingPaymentService}
                onChange={updatePaymentSettingDraft}
                onSave={savePaymentSetting}
              />
            </>
          ) : null}
          {status === 'ready' && !isActiveTabLoading && activeTab === 'paymentVerification' ? (
            <FutureVideoPaymentVerificationPanel
              payments={futureManualPayments}
              counts={futureManualPaymentCounts}
              filter={futureManualPaymentFilter}
              search={futureManualPaymentSearch}
              status={futureManualPaymentStatus}
              savingPaymentId={savingFutureManualPaymentId}
              onFilterChange={changeFutureManualPaymentFilter}
              onSearchChange={setFutureManualPaymentSearch}
              onSearchSubmit={searchFutureManualPayments}
              onRefresh={refreshFutureManualPayments}
              onViewProof={openFuturePaymentProof}
              onVerify={verifyFutureManualPayment}
              onReject={rejectFutureManualPayment}
            />
          ) : null}
          {status === 'ready' && !isActiveTabLoading && activeTab === 'galleryVideos' ? (
            <AdminGalleryVideosPanel
              videos={galleryVideos}
              status={galleryVideoStatus}
              draft={galleryVideoDraft}
              isSaving={savingGalleryVideo}
              uploadProgress={galleryVideoUploadProgress}
              uploadPhase={galleryVideoUploadPhase}
              errors={galleryVideoErrors}
              updatingId={updatingGalleryVideoId}
              onChange={updateGalleryVideoDraft}
              onEdit={editGalleryVideo}
              onCancel={resetGalleryVideoDraft}
              onCancelUpload={cancelGalleryVideoUpload}
              onSave={saveGalleryVideo}
              onToggle={toggleGalleryVideoVisibility}
              onDelete={removeGalleryVideo}
              onRefresh={loadGalleryVideos}
            />
          ) : null}
          {status === 'ready' && !isActiveTabLoading && activeTab === 'feedback' ? (
            <FeedbackList
              items={feedbackItems}
              counts={feedbackCounts}
              category={feedbackCategory}
              selectedFeedback={selectedFeedback}
              onCategoryChange={setFeedbackCategory}
              onSelect={setSelectedFeedback}
              onClose={() => setSelectedFeedback(null)}
            />
          ) : null}
          {status === 'ready' && !isActiveTabLoading && activeTab === 'chatbot' ? (
            <ChatbotAdminPanel
              view={chatbotView}
              faqs={chatbotFaqs}
              topics={chatbotTopics}
              intents={chatbotIntents}
              settings={chatbotSettings}
              cacheCount={chatbotCacheCount}
              status={chatbotStatus}
              editor={chatbotEditor}
              isSaving={savingChatbot}
              onViewChange={changeChatbotView}
              onNew={(view) => setChatbotEditor({ view, record: null })}
              onEdit={(view, record) => setChatbotEditor({ view, record })}
              onClose={() => setChatbotEditor(null)}
              onSave={saveChatbotRecord}
              onDeactivate={deactivateChatbotRecord}
              onSaveSettings={saveChatbotSettings}
              onClearCache={clearChatbotOpenAiCache}
            />
          ) : null}
          {status === 'ready' && activeTab === 'excelValidator' ? (
            <PredictionExcelValidator
              result={excelValidationResult}
              validationStatus={excelValidationStatus}
              selectedValidator={selectedExcelValidator}
              onValidatorChange={(validator) => {
                setSelectedExcelValidator(validator)
                setExcelValidationResult(null)
                setExcelValidationStatus('idle')
              }}
              onValidate={validatePredictionExcel}
            />
          ) : null}
        </section>

        {selectedPrediction ? (
          <PredictionDetailView
            type={selectedPrediction.type}
            detail={predictionDetail}
            status={predictionDetailStatus}
            onClose={() => {
              setSelectedPrediction(null)
              setPredictionDetail(null)
              setPredictionDetailStatus('idle')
            }}
            onRegeneratePast={regeneratePastPrediction}
            regeneratingPast={Boolean(regeneratingPastId)}
          />
        ) : null}
        {selectedFuturePaymentProof ? (
          <FuturePaymentProofModal proof={selectedFuturePaymentProof} onClose={closeFuturePaymentProof} />
        ) : null}
      </section>
    </main>
  )
}

function AdminGalleryVideosPanel({
  videos,
  status,
  draft,
  isSaving,
  uploadProgress,
  uploadPhase,
  errors,
  updatingId,
  onChange,
  onEdit,
  onCancel,
  onCancelUpload,
  onSave,
  onToggle,
  onDelete,
  onRefresh,
}) {
  const [copiedVideoId, setCopiedVideoId] = useState(null)
  const categoryOptions = Array.from(new Set(['General', ...videos.map((video) => video.category || 'General')]))
  const isCustomCategory = draft.category && !categoryOptions.includes(draft.category)
  const categorySelectValue = isCustomCategory || !draft.category ? '__new' : draft.category

  const copyShareLink = async (video) => {
    const shareUrl = galleryVideoShareUrl(video)

    try {
      const copied = await copyTextToClipboard(shareUrl)
      if (!copied) return
      setCopiedVideoId(video.id)
      window.setTimeout(() => {
        setCopiedVideoId((current) => (current === video.id ? null : current))
      }, 1800)
    } catch {
      setCopiedVideoId(null)
    }
  }

  return (
    <section className="admin-section">
      <div className="admin-section-header">
        <div>
          <h3>Gallery Videos</h3>
          <p>Manage the single public video library used by Gallery, Recent Videos, and Intro videos.</p>
        </div>
        <button type="button" className="admin-row-action" onClick={onRefresh}>Refresh</button>
      </div>

      <form className="admin-card admin-gallery-video-form is-compact" onSubmit={onSave}>
        <div className="admin-gallery-upload-row">
          <label>
            <span>Thumbnail</span>
            <input
              type="file"
              accept="image/jpeg,image/png,image/webp"
              onChange={(event) => onChange('thumbnail_file', event.target.files?.[0] || null)}
              disabled={isSaving}
            />
            {draft.thumbnail_file ? <small>{draft.thumbnail_file.name}</small> : null}
          </label>

          <label>
            <span>Video File {draft.id ? '' : '*'}</span>
            <input
              type="file"
              accept="video/mp4,video/webm,video/quicktime"
              onChange={(event) => onChange('video_file', event.target.files?.[0] || null)}
              required={!draft.id}
              disabled={isSaving}
            />
            {draft.video_file ? <small>{draft.video_file.name}</small> : null}
          </label>
        </div>

        <div className="admin-form-grid admin-gallery-compact-grid">
          <label>
            <span>Title *</span>
            <input
              type="text"
              value={draft.title}
              onChange={(event) => onChange('title', event.target.value)}
              required
            />
          </label>

          <label>
            <span>Video Type *</span>
            <select value={draft.video_type} onChange={(event) => onChange('video_type', event.target.value)} required>
              <option value="SHORT">Short</option>
              <option value="WIDE">Wide Video</option>
            </select>
          </label>

          <label>
            <span>Category *</span>
            <select
              value={categorySelectValue}
              onChange={(event) => onChange('category', event.target.value === '__new' ? '' : event.target.value)}
              required
            >
              {categoryOptions.map((category) => (
                <option key={category} value={category}>{category}</option>
              ))}
              <option value="__new">Add new category...</option>
            </select>
          </label>

          {(categorySelectValue === '__new') ? (
            <label>
              <span>New Category *</span>
              <input
                type="text"
                value={draft.category}
                onChange={(event) => onChange('category', event.target.value)}
                required
              />
            </label>
          ) : null}

          <label>
            <span>Display Order</span>
            <input
              type="number"
              value={draft.display_order}
              onChange={(event) => onChange('display_order', event.target.value)}
            />
          </label>

          <label>
            <span>Publication *</span>
            <select value={draft.publication_status} onChange={(event) => onChange('publication_status', event.target.value)} required>
              <option value="DRAFT">Draft</option>
              <option value="PUBLISHED">Published</option>
            </select>
          </label>

          <label className="admin-toggle-row">
            <span>Visible</span>
            <input
              type="checkbox"
              checked={draft.is_active}
              onChange={(event) => onChange('is_active', event.target.checked)}
            />
          </label>

          <label className="admin-form-wide admin-gallery-description">
            <span>Description</span>
            <textarea
              value={draft.description}
              onChange={(event) => onChange('description', event.target.value)}
              rows={3}
            />
          </label>
        </div>

        <footer className="admin-gallery-video-actions">
          <button type="submit" className="admin-primary-button" disabled={isSaving}>
            {isSaving ? 'Saving...' : draft.publication_status === 'PUBLISHED' ? 'Save & Publish' : 'Save Draft'}
          </button>
          {draft.id ? (
            <button type="button" className="admin-secondary-button" onClick={onCancel} disabled={isSaving}>
              Cancel Edit
            </button>
          ) : null}
        </footer>
      </form>

      {errors?.length ? (
        <div className="admin-gallery-error-box" role="alert">
          <strong>Video could not be saved</strong>
          {errors.map((error) => (
            <p key={error}>{error}</p>
          ))}
        </div>
      ) : null}

      {isSaving && (draft.video_file || uploadPhase === 'uploading' || uploadPhase === 'processing') ? (
        <div className="admin-upload-modal-backdrop" role="presentation">
          <section className="admin-upload-modal" role="status" aria-live="polite" aria-label="Video upload progress">
            <div className="admin-upload-modal-mark">
              <span></span>
            </div>
            <div>
              <h4>{uploadPhase === 'processing' || uploadPhase === 'saving' ? 'Processing video...' : 'Uploading video...'}</h4>
              <p>
                {uploadPhase === 'processing' || uploadPhase === 'saving'
                  ? 'Please keep this window open while the video is saved.'
                  : 'Please keep this window open while the video is uploaded.'}
              </p>
            </div>
            <div className="admin-upload-progress-shell">
              <span style={{ width: `${uploadProgress ?? 0}%` }}></span>
            </div>
            <strong>
              {uploadPhase === 'processing' || uploadPhase === 'saving'
                ? 'Processing video...'
                : `${uploadProgress ?? 0}%`}
            </strong>
            <button type="button" className="admin-secondary-button" onClick={onCancelUpload}>
              Cancel
            </button>
          </section>
        </div>
      ) : null}

      {status === 'loading' ? <AdminProgress label="Loading gallery videos..." /> : null}
      {status === 'error' ? <div className="admin-empty">Unable to load gallery videos.</div> : null}
      {status !== 'loading' && !videos.length ? <div className="admin-empty">No gallery videos found.</div> : null}

      {videos.length ? (
        <div className="admin-table-wrap">
          <table className="admin-table admin-gallery-video-table">
            <thead>
              <tr>
                <th>Thumbnail</th>
                <th>Title</th>
                <th>Category</th>
                <th>Type</th>
                <th>Display Order</th>
                <th>Publication</th>
                <th>Status</th>
                <th>Published</th>
                <th>Share Link</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {videos.map((video) => (
                <tr key={video.id}>
                  <td>
                    {video.thumbnail_url ? (
                      <img className="admin-gallery-thumb" src={video.thumbnail_url} alt={`${video.title} thumbnail`} />
                    ) : (
                      <span className="admin-muted">No thumbnail</span>
                    )}
                  </td>
                  <td>
                    <strong>{video.title}</strong>
                    {video.description ? <small>{video.description}</small> : null}
                  </td>
                  <td>{video.category || 'General'}</td>
                  <td><span className="admin-badge is-blue">{video.video_type === 'SHORT' ? 'Short' : 'Wide Video'}</span></td>
                  <td>{video.display_order}</td>
                  <td><span className={`admin-badge ${video.publication_status === 'PUBLISHED' ? 'is-green' : 'is-gold'}`}>{video.publication_status === 'PUBLISHED' ? 'Published' : 'Draft'}</span></td>
                  <td><span className={`admin-badge ${video.is_active ? 'is-green' : 'is-muted'}`}>{video.is_active ? 'Visible' : 'Hidden'}</span></td>
                  <td>{formatDate(video.published_at)}</td>
                  <td>
                    <div className="admin-share-link">
                      <input
                        type="text"
                        readOnly
                        value={galleryVideoShareUrl(video)}
                        aria-label={`Share link for ${video.title}`}
                        onFocus={(event) => event.target.select()}
                      />
                      <button
                        type="button"
                        className="admin-share-copy"
                        onClick={() => copyShareLink(video)}
                        aria-label={`Copy share link for ${video.title}`}
                      >
                        {copiedVideoId === video.id ? 'Copied' : 'Copy'}
                      </button>
                    </div>
                    {copiedVideoId === video.id ? <small className="admin-share-copied">Link copied</small> : null}
                  </td>
                  <td>
                    <div className="admin-row-actions">
                      <button type="button" className="admin-row-action" onClick={() => onEdit(video)}>Edit</button>
                      <a className="admin-row-action" href={`/admin/gallery-videos/${video.id}/preview`} target="_blank" rel="noreferrer">Preview</a>
                      <button
                        type="button"
                        className="admin-row-action"
                        onClick={() => onToggle(video)}
                        disabled={updatingId === video.id}
                      >
                        {video.is_active ? 'Hide' : 'Show'}
                      </button>
                      <button
                        type="button"
                        className="admin-row-action is-danger"
                        onClick={() => onDelete(video)}
                        disabled={updatingId === video.id}
                      >
                        Delete
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {videos.length ? (
        <p className="admin-share-hint">
          Share links use the public Gallery URL <code>/videos/VIDEO_ID/title-slug</code> and open that video in the player. Hidden videos stay unavailable to the public.
        </p>
      ) : null}
    </section>
  )
}

function AdminIcon({ name }) {
  const paths = {
    users: 'M16 20v-1.5c0-1.7-1.8-3-4-3s-4 1.3-4 3V20M12 12a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm6.5 8v-1.2c0-1.3-1-2.4-2.5-2.9M17 5.4a3 3 0 0 1 0 5.2',
    sun: 'M12 5V3m0 18v-2m7-7h2M3 12h2m12-5 1.4-1.4M5.6 18.4 7 17m10 0 1.4 1.4M5.6 5.6 7 7m5 1.2a3.8 3.8 0 1 1 0 7.6 3.8 3.8 0 0 1 0-7.6Z',
    moon: 'M19.5 15.2A7.5 7.5 0 0 1 8.8 4.5a8 8 0 1 0 10.7 10.7Z',
    star: 'm12 3 2.5 5.7 6.1.6-4.6 4 1.4 6-5.4-3.1L6.6 19.3l1.4-6-4.6-4 6.1-.6Z',
    rating: 'M12 17.2 6.8 20l1-5.8-4.2-4.1 5.8-.8L12 4l2.6 5.3 5.8.8-4.2 4.1 1 5.8Z',
    clock: 'M12 7v5l3 1.8M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    calendar: 'M7 3v3m10-3v3M4.5 9h15M6 5h12a2 2 0 0 1 2 2v11.5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z',
    video: 'M6 6.5h8.5a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2Zm10.5 3.2 3.5-2v8.6l-3.5-2',
    headset: 'M4 13v-1a8 8 0 0 1 16 0v1M6 12h1.5a1.5 1.5 0 0 1 1.5 1.5V17A2 2 0 0 1 7 19H6a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2Zm12 0h-1.5a1.5 1.5 0 0 0-1.5 1.5V17a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2Zm-2 7c-.8 1.2-2.1 2-4 2',
    temple: 'M3 10h18M5 10l7-5 7 5M6 10v8m4-8v8m4-8v8m4-8v8M4 18h16M3 21h18',
    card: 'M3.5 6.5h17v11h-17Zm0 3h17M6.5 14.5h4',
    qr: 'M4 4h6v6H4Zm10 0h6v6h-6ZM4 14h6v6H4Zm10 0h2v2h-2Zm4 0h2v2h-2Zm-4 4h2v2h-2Zm4 0h2v2h-2Z',
    upload: 'M12 16V5m0 0L8 9m4-4 4 4M5 16v3h14v-3',
    refresh: 'M20 6v5h-5M4 18v-5h5M18.2 10A6.5 6.5 0 0 0 7 6.8L4 10m2 4a6.5 6.5 0 0 0 11.2 3.2L20 14',
  }

  return (
    <svg className="admin-mini-icon" viewBox="0 0 24 24" aria-hidden="true">
      <path d={paths[name] || paths.star} />
    </svg>
  )
}

function Metric({ label, value, icon, tone, suffix = '', detail = '', details = [] }) {
  return (
    <article className={`admin-metric is-${tone || 'blue'}`}>
      <span className="admin-metric-icon"><AdminIcon name={icon} /></span>
      <div>
        <span>{label}</span>
        <strong>{value ?? '-'}{suffix ? <small>{suffix}</small> : null}</strong>
        {details.length ? (
          <div className="admin-metric-detail-grid">
            {details.map((item) => (
              <span key={item.label}>
                <b>{item.label}</b>
                <i>{item.value}</i>
              </span>
            ))}
          </div>
        ) : null}
        {detail ? <em>{detail}</em> : null}
      </div>
    </article>
  )
}

const feedbackCategories = [
  { id: 'all', label: 'All' },
  { id: 'past', label: 'Past' },
  { id: 'future', label: 'Future' },
  { id: 'temple_remedy', label: 'Temple Remedy' },
]

function FeedbackList({ items, counts, category, selectedFeedback, onCategoryChange, onSelect, onClose }) {
  const visibleItems = category === 'all' ? items : items.filter((item) => item.category === category)

  return (
    <>
      <div className="admin-remedy-tabs admin-feedback-tabs" aria-label="Feedback categories">
        {feedbackCategories.map((item) => (
          <button
            key={item.id}
            type="button"
            className={category === item.id ? 'is-active' : ''}
            onClick={() => onCategoryChange(item.id)}
          >
            {item.label}
            <span>{counts[item.id] ?? 0}</span>
          </button>
        ))}
      </div>

      {!visibleItems.length ? <div className="admin-empty">No feedback found for this category.</div> : null}
      {visibleItems.length ? (
        <div className="admin-table-wrap">
          <table className="admin-table admin-feedback-table">
            <thead>
              <tr>
                <th>Category</th>
                <th>User</th>
                <th>Rating</th>
                <th>Comments</th>
                <th>Submitted</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              {visibleItems.map((item) => (
                <tr key={item.id}>
                  <td><span className="admin-badge is-blue">{item.category_label}</span></td>
                  <td>
                    <strong>{item.user?.name || 'User'}</strong>
                    <small>{item.user?.email || '-'}</small>
                  </td>
                  <td><span className="admin-feedback-rating">{item.rating_label}</span></td>
                  <td>{item.comments || '-'}</td>
                  <td>{formatDate(item.submitted_at)}</td>
                  <td><button type="button" className="admin-row-action" onClick={() => onSelect(item)}>View Details</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {selectedFeedback ? <FeedbackDetailModal feedback={selectedFeedback} onClose={onClose} /> : null}
    </>
  )
}

function FeedbackDetailModal({ feedback, onClose }) {
  const details = Object.entries(feedback.context || {}).filter(([, value]) => value !== null && value !== undefined && value !== '')

  return (
    <section className="admin-modal-overlay" role="dialog" aria-modal="true" aria-label="Feedback details">
      <div className="admin-modal admin-feedback-detail-modal">
        <header className="admin-modal-head">
          <div>
            <span>{feedback.category_label}</span>
            <h3>{feedback.user?.name || 'User Feedback'}</h3>
            <p>{feedback.user?.email || '-'}</p>
          </div>
          <div className="admin-modal-status">
            <span className="admin-badge is-gold">{feedback.rating_label}</span>
            <button type="button" onClick={onClose} aria-label="Close feedback details">x</button>
          </div>
        </header>

        <section className="admin-feedback-detail-card">
          <span>Comments</span>
          <p>{feedback.comments || 'No comments shared.'}</p>
          <small>{formatDate(feedback.submitted_at)}</small>
        </section>

        <section className="admin-feedback-detail-grid">
          {details.map(([key, value]) => (
            <div key={key}>
              <span>{labelFromKey(key)}</span>
              <strong>{String(value)}</strong>
            </div>
          ))}
        </section>

        <footer className="admin-modal-actions">
          <button type="button" onClick={onClose}>Close</button>
        </footer>
      </div>
    </section>
  )
}

function UsersTable({ users }) {
  if (!users.length) return <div className="admin-empty">No users found.</div>

  return (
    <div className="admin-table-wrap">
      <table className="admin-table">
        <thead>
          <tr>
            <th>User</th>
            <th>Email</th>
            <th>Status</th>
            <th>Last Login</th>
          </tr>
        </thead>
        <tbody>
          {users.map((user) => (
            <tr key={user.id}>
              <td>{user.name}</td>
              <td>{user.email}</td>
              <td><span className={`admin-badge ${user.is_active ? 'is-green' : 'is-muted'}`}>{user.is_active ? 'Active' : 'Inactive'}</span></td>
              <td>{formatDate(user.last_login_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function AppointmentList({ appointments, group, counts, isLoading, isError, selectedAppointment, draft, slots, slotsSummary, slotsError, slotStatus, month, monthDays, monthStatus, isSaving, onGroupChange, onOpen, onClose, onDraftChange, onMonthChange, onLoadSlots, onSave, onQuickUpdate }) {
  const tabs = [
    { id: 'waiting_payment', label: 'Pending Payment', count: counts.waiting_payment },
    { id: 'payment_received', label: 'Payment Received', count: counts.payment_received },
    { id: 'upcoming', label: 'Upcoming', count: counts.upcoming },
    { id: 'completed', label: 'Completed', count: counts.completed },
    { id: 'cancelled_failed', label: 'Cancelled / Failed', count: counts.cancelled_failed ?? counts.cancelled },
  ]

  return (
    <>
      <div className="admin-remedy-tabs" aria-label="Appointment groups">
        {tabs.map((tab) => (
          <button key={tab.id} type="button" className={group === tab.id ? 'is-active' : ''} onClick={() => onGroupChange(tab.id)}>
            <span>{tab.label}</span>
            <strong>{tab.count ?? 0}</strong>
          </button>
        ))}
      </div>
      {isLoading ? <AdminProgress label="Loading appointments..." /> : null}
      {isError ? <div className="admin-empty is-compact">Unable to load appointments.</div> : null}
      {!isLoading && !isError && !appointments.length ? <div className="admin-empty">No appointments found.</div> : null}
      {!isLoading && !isError && appointments.length && group === 'waiting_payment' ? (
        <div className="admin-table-wrap">
          <table className="admin-table admin-appointment-payment-table">
            <thead>
              <tr>
                <th>Request</th>
                <th>User</th>
                <th>Contact</th>
                <th>Preferred</th>
                <th>Amount</th>
                <th>Payment Reference</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              {appointments.map((appointment) => (
                <AppointmentPaymentReviewRow
                  key={appointment.id}
                  appointment={appointment}
                  isSaving={isSaving}
                  onQuickUpdate={onQuickUpdate}
                />
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
      {!isLoading && !isError && appointments.length && group !== 'waiting_payment' ? (
        <div className="admin-table-wrap">
          <table className="admin-table admin-remedy-grid">
            <thead>
              <tr>
                <th>Request</th>
                <th>User</th>
                <th>Questions</th>
                <th>Preferred</th>
                <th>Confirmed</th>
                <th>Amount</th>
                <th>Payment</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              {appointments.map((appointment) => (
                <tr key={appointment.id}>
                  <td><strong>{appointment.appointment_number}</strong><span>{formatDate(appointment.submitted_at)}</span></td>
                  <td><strong>{appointment.user?.name || 'User'}</strong><span>{appointment.user?.email || '-'}</span></td>
                  <td>{appointment.questions_count}</td>
                  <td>{[appointment.preferred_date, appointment.preferred_time].filter(Boolean).join(' ')}</td>
                  <td>{formatDate(appointment.confirmed_start_at, appointmentTimezone)}</td>
                  <td>{formatMoney(appointment.amount_minor, appointment.currency)}</td>
                  <td><span className="admin-badge is-gold">{appointment.payment_status_label || labelFromKey(appointment.payment_status)}</span></td>
                  <td><span className="admin-badge is-blue">{appointmentStatusLabel(appointment.status)}</span></td>
                  <td><button type="button" className="admin-row-action" onClick={() => onOpen(appointment)}>{group === 'payment_received' ? 'Allocate Slot' : 'Open'}</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {selectedAppointment && group !== 'waiting_payment' ? (
        <AppointmentModal
          appointment={selectedAppointment}
          isReadOnly={['completed', 'cancelled_failed'].includes(group)}
          draft={draft}
          slots={slots}
          slotsSummary={slotsSummary}
          slotsError={slotsError}
          slotStatus={slotStatus}
          month={month}
          monthDays={monthDays}
          monthStatus={monthStatus}
          isSaving={isSaving}
          onClose={onClose}
          onDraftChange={onDraftChange}
          onMonthChange={onMonthChange}
          onLoadSlots={onLoadSlots}
          onSave={onSave}
        />
      ) : null}
    </>
  )
}

function AppointmentPaymentReviewRow({ appointment, isSaving, onQuickUpdate }) {
  const [attachmentStatus, setAttachmentStatus] = useState('')
  const [attachmentPreview, setAttachmentPreview] = useState(null)
  const reference = appointment.manual_payment?.reference

  useEffect(() => () => {
    if (attachmentPreview?.url) URL.revokeObjectURL(attachmentPreview.url)
  }, [attachmentPreview])

  const submitAction = (action) => {
    const payload = {}

    if (action === 'paid') {
      payload.payment_status = 'PAID'
    }

    if (action === 'rejected') {
      payload.payment_status = 'FAILED'
    }

    if (action === 'cancelled') {
      payload.status = 'cancelled'
    }

    onQuickUpdate?.(appointment, payload)
  }

  const openAttachment = async () => {
    setAttachmentStatus('Opening...')
    try {
      const blob = await fetchAdminAppointmentManualPaymentFile(appointment.id)
      if (attachmentPreview?.url) URL.revokeObjectURL(attachmentPreview.url)
      const url = URL.createObjectURL(blob)
      setAttachmentPreview({
        url,
        type: blob.type || appointment.manual_payment?.mime_type || '',
        name: appointment.manual_payment?.original_name || 'Payment attachment',
      })
      setAttachmentStatus('')
    } catch {
      setAttachmentStatus('Unable to open attachment.')
    }
  }

  const closeAttachment = () => {
    if (attachmentPreview?.url) URL.revokeObjectURL(attachmentPreview.url)
    setAttachmentPreview(null)
  }

  return (
    <>
      <tr className="admin-appointment-payment-main">
        <td><strong>{appointment.appointment_number}</strong></td>
        <td><strong>{appointment.user?.name || 'User'}</strong></td>
        <td><strong>{appointment.contact_number || appointment.user?.phone_e164 || '-'}</strong></td>
        <td><strong>{[appointment.preferred_date, appointment.preferred_time].filter(Boolean).join(' ') || '-'}</strong></td>
        <td>
          <strong>{formatMoney(appointment.amount_minor, appointment.currency)}</strong>
          <span>{appointment.payment_method_label || '-'}</span>
        </td>
        <td>
          <strong>{reference || 'Not provided'}</strong>
          {appointment.manual_payment?.file_url ? <button type="button" className="admin-inline-link" onClick={openAttachment}>View attachment</button> : <small>No attachment</small>}
          {attachmentStatus ? <small>{attachmentStatus}</small> : null}
        </td>
        <td>
          <div className="admin-appointment-review-actions">
          <button type="button" className="is-success" disabled={isSaving} onClick={() => submitAction('paid')}>Payment Received</button>
          <button type="button" className="is-danger" disabled={isSaving} onClick={() => submitAction('rejected')}>Rejected</button>
          <button type="button" disabled={isSaving} onClick={() => submitAction('cancelled')}>Cancelled</button>
          </div>
        </td>
      </tr>
      <tr className="admin-appointment-payment-sub">
        <td colSpan="7">
          <span>Submitted: {formatDate(appointment.submitted_at)}</span>
          <span>Email: {appointment.user?.email || '-'}</span>
          <span>Timezone: {appointment.preferred_timezone || '-'}</span>
          <span>Status: {appointment.payment_status_label || labelFromKey(appointment.payment_status)}</span>
          {attachmentPreview ? (
            <section className="admin-lightbox-overlay" role="dialog" aria-modal="true" aria-label="Payment attachment preview" onClick={closeAttachment}>
              <div className="admin-lightbox" onClick={(event) => event.stopPropagation()}>
                <header>
                  <div>
                    <span>Payment Attachment</span>
                    <strong>{attachmentPreview.name}</strong>
                  </div>
                  <button type="button" onClick={closeAttachment} aria-label="Close attachment preview">x</button>
                </header>
                <div className="admin-lightbox-body">
                  {attachmentPreview.type.includes('pdf') ? (
                    <iframe title={attachmentPreview.name} src={attachmentPreview.url} />
                  ) : (
                    <img src={attachmentPreview.url} alt={attachmentPreview.name} />
                  )}
                </div>
              </div>
            </section>
          ) : null}
        </td>
      </tr>
    </>
  )
}

function AppointmentModal({ appointment, isReadOnly = false, draft, slots, slotsSummary, slotsError, slotStatus, month, monthDays, monthStatus, isSaving, onClose, onDraftChange, onMonthChange, onLoadSlots, onSave }) {
  const [isAstrologyOpen, setIsAstrologyOpen] = useState(false)
  const astrologers = appointment.available_astrologers || []
  const preferredLabel = [appointment.preferred_date, appointment.preferred_time, appointment.preferred_timezone].filter(Boolean).join(' ') || '-'
  const selectedKey = slotWallKey(draft.confirmed_start_at, appointmentTimezone)
  const selectedSlot = slots.find((slot) => slotWallKey(slot.start_at, appointmentTimezone) === selectedKey)
  const selectedQuestions = Array.isArray(appointment.questions) ? appointment.questions : []
  const busyLabel = isSaving
    ? 'Saving slot allocation...'
    : monthStatus === 'loading'
      ? 'Loading month slot status...'
      : slotStatus === 'loading'
        ? 'Loading day slots...'
        : ''
  const changeVisibleMonth = (offset) => {
    const nextMonth = addMonths(month || draft.confirmed_date?.slice(0, 7), offset)
    onMonthChange(nextMonth)
    onDraftChange('confirmed_date', defaultDateForMonth(nextMonth))
    onDraftChange('confirmed_start_at', '')
  }

  return (
    <section className="admin-modal-overlay" role="dialog" aria-modal="true" aria-label="Allocate appointment slot">
      <div className="admin-modal admin-slot-allocation-modal">
        <header className="admin-modal-head admin-slot-allocation-head">
          <div className="admin-slot-identity-row">
            <div><span>No.</span><strong>{appointment.appointment_number}</strong></div>
            <div><span>Name</span><strong>{appointment.user?.name || 'User'}</strong></div>
            <div><span>Phone</span><strong>{appointment.contact_number || appointment.user?.phone_e164 || '-'}</strong></div>
            <div><span>Email</span><strong>{appointment.user?.email || '-'}</strong></div>
            <div><span>Preferred</span><strong>{preferredLabel}</strong></div>
          </div>
          <div className="admin-modal-status">
            <span className="admin-badge is-blue">{appointmentStatusLabel(appointment.status)}</span>
            {appointment.viewer_url ? <button type="button" className="admin-appointment-astro-btn" onClick={() => setIsAstrologyOpen(true)}>View Astrology</button> : null}
            <button type="button" onClick={onClose} aria-label="Close appointment">x</button>
          </div>
        </header>

        <div className="admin-slot-allocation-body">
          {busyLabel ? (
            <div className="admin-progress-inline" role="status" aria-live="polite">
              <span>{busyLabel}</span>
              <div><i /></div>
            </div>
          ) : null}
          {isReadOnly ? <div className="admin-slot-readonly-note">This appointment is closed for slot changes.</div> : null}
          <div className="admin-slot-control-grid">
            <label>
              Astrologer
              <select disabled={isReadOnly} value={draft.astrologer_id || ''} onChange={(event) => {
                onDraftChange('astrologer_id', event.target.value)
                onDraftChange('confirmed_start_at', '')
              }}>
                {astrologers.map((astrologer) => <option key={astrologer.id} value={astrologer.id}>{astrologer.name}</option>)}
              </select>
            </label>
            <div className="admin-slot-month-control" aria-label="Appointment month">
              <span>Month</span>
              <div>
                <button type="button" disabled={isReadOnly} onClick={() => changeVisibleMonth(-1)} aria-label="Previous month">&lt;</button>
                <strong>{monthLabel(month || draft.confirmed_date?.slice(0, 7))}</strong>
                <button type="button" disabled={isReadOnly} onClick={() => changeVisibleMonth(1)} aria-label="Next month">&gt;</button>
              </div>
            </div>
            <label>
              Meeting Link
              <input disabled={isReadOnly} value={draft.meeting_link || ''} onChange={(event) => onDraftChange('meeting_link', event.target.value)} placeholder="Meeting Link/Whatsapp Number" />
            </label>
          </div>

          <section className="admin-appointment-question-row" aria-label="Selected consultation questions">
            {selectedQuestions.length ? selectedQuestions.map((item, index) => (
              <div key={`${item.consultation_question_id || index}-${item.sequence || index}`}>
                <span>Question {item.sequence || index + 1}</span>
                <strong>{item.question}</strong>
              </div>
            )) : (
              <div>
                <span>Questions</span>
                <strong>No selected questions found.</strong>
              </div>
            )}
          </section>

          <AppointmentMonthView
            month={month}
            selectedDate={draft.confirmed_date}
            preferredDate={appointment.preferred_date}
            days={monthDays}
            status={monthStatus}
            isReadOnly={isReadOnly}
            onSelectDate={(date) => {
              if (isPastDate(date)) return
              onDraftChange('confirmed_date', date)
              onMonthChange(date.slice(0, 7))
              onDraftChange('confirmed_start_at', '')
            }}
          />

          <AppointmentDayTimeline
            date={draft.confirmed_date}
            timezone={draft.timezone || 'Asia/Kolkata'}
            slots={slots}
            slotStatus={slotStatus}
            slotsSummary={slotsSummary}
            slotsError={slotsError}
            selectedSlot={draft.confirmed_start_at}
            isReadOnly={isReadOnly}
            onSelectSlot={(startAt) => onDraftChange('confirmed_start_at', startAt)}
            onRetry={() => onLoadSlots(draft.confirmed_date, draft.astrologer_id, appointment)}
          />

          <div className="admin-slot-save-strip">
            <span>{selectedSlot ? `Selected: ${selectedSlot.range_label || selectedSlot.start_label}` : 'Select an available slot'}</span>
            <select disabled={isReadOnly} value={draft.meeting_provider || 'manual'} onChange={(event) => onDraftChange('meeting_provider', event.target.value)}>
              <option value="manual">Manual</option>
              <option value="google_meet">Google Meet</option>
            </select>
          </div>
        </div>

        {isAstrologyOpen ? (
          <AstrologyIframeModal
            appointment={appointment}
            onClose={() => setIsAstrologyOpen(false)}
          />
        ) : null}
        <footer className="admin-modal-actions">
          <button type="button" disabled={isSaving} onClick={onClose}>Cancel</button>
          {isReadOnly ? null : <button type="button" disabled={isSaving || !draft.confirmed_start_at} onClick={onSave}>{isSaving ? 'Saving...' : appointment.confirmed_start_at ? 'Update Slot' : 'Save Slot'}</button>}
        </footer>
      </div>
    </section>
  )
}

function AstrologyIframeModal({ appointment, onClose }) {
  return (
    <section className="admin-nested-modal-overlay" role="dialog" aria-modal="true" aria-label="Astrology data">
      <div className="admin-nested-modal">
        <header className="admin-modal-head">
          <div>
            <span>Astrology Data</span>
            <h3>{appointment.appointment_number}</h3>
            <p>{appointment.prediction_request_id ? `Prediction Request #${appointment.prediction_request_id}` : 'Linked prediction request'}</p>
          </div>
          <div className="admin-modal-status">
            <button type="button" onClick={onClose} aria-label="Close astrology data">x</button>
          </div>
        </header>
        {appointment.viewer_url ? (
          <iframe className="admin-nested-astrology-frame" title={`Astrology data for appointment ${appointment.appointment_number}`} src={appointment.viewer_url} />
        ) : (
          <div className="admin-empty">Astrology data is not available.</div>
        )}
      </div>
    </section>
  )
}

function AppointmentMonthView({ month, selectedDate, preferredDate, days, status, isReadOnly = false, onSelectDate }) {
  return (
    <section className="admin-slot-month-view" aria-label="Month slots">
      <div className="admin-slot-section-head">
        <div>
          <span>Month Wise Slot Status</span>
          <strong>{monthLabel(month)}</strong>
        </div>
        {status === 'loading' ? <em>Loading month...</em> : null}
        {status === 'error' ? <em>Unable to load month</em> : null}
      </div>
      <div className="admin-slot-month-grid">
        {days.map((day) => {
          const date = new Date(`${day.date}T00:00:00`)
          const label = Number.isNaN(date.getTime()) ? day.date.slice(-2) : date.getDate()
          const weekday = Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString('en-IN', { weekday: 'short' })
          const isSelected = day.date === selectedDate
          const isPreferred = day.date === preferredDate
          const isPast = isPastDate(day.date)
          const hasOpenSlot = day.available > 0 || day.selected > 0

          return (
            <button
              key={day.date}
              type="button"
              disabled={isPast || isReadOnly}
              className={`admin-slot-month-day ${isSelected ? 'is-selected' : ''} ${isPreferred ? 'is-preferred' : ''} ${isPast ? 'is-past' : ''} ${hasOpenSlot ? 'has-slots' : 'is-full'}`}
              onClick={() => onSelectDate(day.date)}
            >
              <strong>{label}</strong>
              <span>{weekday}</span>
              <small>{isPast ? 'Past' : `${day.available} free`}</small>
            </button>
          )
        })}
      </div>
    </section>
  )
}

function AppointmentDayTimeline({ date, timezone, slots, slotsSummary, slotsError, slotStatus, selectedSlot, isReadOnly = false, onSelectSlot, onRetry }) {
  const availableCount = slotsSummary?.available ?? slots.filter((slot) => slot.available).length
  const slotDuration = slots[0]?.duration_minutes
  const shouldRenderSlots = slots.length > 0
  const daySlots = slots.filter((slot) => slot.period === 'day')
  const nightSlots = slots.filter((slot) => slot.period !== 'day')
  const selectedKey = slotWallKey(selectedSlot, appointmentTimezone)
  const renderSlot = (slot) => {
    const isSelected = slotWallKey(slot.start_at, appointmentTimezone) === selectedKey
    const isReserved = slot.status === 'reserved'
    const isCurrentSlot = slot.status === 'selected' || slot.is_current_appointment_slot
    const isPastSlot = isPastInstant(slot.start_at)
    const isBlocked = slot.status === 'blocked' || isPastSlot
    const statusLabel = slot.status === 'selected'
      ? 'Selected'
      : isReserved
        ? 'Reserved'
        : isBlocked
          ? (slot.blocked_reason || (isPastSlot ? 'Past slot' : 'Blocked'))
          : 'Available'
    const slotStateClass = slot.status === 'selected'
      ? 'is-selected'
      : isBlocked
        ? 'is-blocked'
        : isReserved
          ? 'is-reserved'
          : slot.available
            ? 'is-available'
            : 'is-blocked'

    return (
      <button
        key={`slot-${slot.id || slot.key || slot.start_at}`}
        type="button"
        disabled={isReadOnly || isReserved || isBlocked || isCurrentSlot}
        title={`${slot.range_label || slot.label} - ${statusLabel}`}
        className={`admin-calendar-slot ${slotStateClass} ${isSelected ? 'is-selected' : ''} ${slot.is_user_preferred ? 'is-preferred' : ''}`}
        onClick={() => {
          if (!isReadOnly && !isReserved && !isBlocked && !isCurrentSlot) onSelectSlot(slot.start_at)
        }}
      >
        <strong>{slot.start_label || slot.label}</strong>
        <small>{statusLabel}</small>
        {slot.is_user_preferred ? <em>Preferred</em> : null}
      </button>
    )
  }

  return (
    <section className="admin-appointment-calendar" aria-label="Appointment day slots">
      <div className="admin-slot-section-head">
        <div>
          <span>Day Wise Available Slot Status</span>
          <strong>{date || 'Select a date'} - {timezone}</strong>
        </div>
      </div>
      <div className="admin-calendar-summary">
        <span>{slots.length ? `${slots.length} slots` : 'No slots loaded'}</span>
        {slotDuration ? <em>{slotDuration} minutes appointment, starts every 30 minutes</em> : null}
        <strong>{slots.length ? `${availableCount} available` : 'Select date and astrologer'}</strong>
      </div>
      <div className="admin-calendar-track">
        {slotStatus === 'loading' ? (
          <>
            <div className="admin-slot-message">Loading available slots...</div>
            <div className="admin-calendar-skeleton" aria-hidden="true">
              {Array.from({ length: 6 }).map((_, index) => <span key={`slot-skeleton-${index}`} />)}
            </div>
          </>
        ) : null}
        {slotStatus === 'empty' ? <div className="admin-slot-message">No slots were generated for this astrologer and date.</div> : null}
        {slotStatus === 'error' ? (
          <div className="admin-slot-message is-error">
            <span>{slotsError || 'Unable to load available slots.'}</span>
            <button type="button" onClick={onRetry}>Retry</button>
          </div>
        ) : null}
        {slotStatus === 'idle' ? <div className="admin-slot-message">Choose a day from the month view to load available slots.</div> : null}
        {shouldRenderSlots ? (
          <div className="admin-calendar-two-row-grid">
            <div className="admin-calendar-period-label">IST 9 AM - 9 PM</div>
            <div className="admin-calendar-period-slots">{daySlots.map(renderSlot)}</div>
            <div className="admin-calendar-period-label">IST 9 PM - 9 AM</div>
            <div className="admin-calendar-period-slots">{nightSlots.map(renderSlot)}</div>
          </div>
        ) : null}
      </div>
    </section>
  )
}

function sameInstant(left, right) {
  if (!left || !right) return false

  return new Date(left).getTime() === new Date(right).getTime()
}

function PredictionRequestTable({ type, requests, loadingId, onOpen }) {
  const [filters, setFilters] = useState({ search: '', status: '', rating: '' })
  const filteredRequests = requests.filter((request) => {
    const haystack = [
      request.id,
      request.prediction_request_id,
      request.prediction_result_id,
      request.identity?.name,
      request.identity?.email,
      request.identity?.phone_e164,
    ].filter(Boolean).join(' ').toLowerCase()
    const matchesSearch = !filters.search || haystack.includes(filters.search.toLowerCase())
    const matchesStatus = !filters.status || request.status === filters.status
    const hasRating = Boolean(request.rating?.rating)
    const matchesRating = !filters.rating || (filters.rating === 'rated' ? hasRating : !hasRating)

    return matchesSearch && matchesStatus && matchesRating
  })

  if (!requests.length) return <div className="admin-empty">No {type} prediction requests found.</div>

  return (
    <>
      <div className="admin-report-filters">
        <input value={filters.search} onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))} placeholder="Search name, email, or request ID" />
        <select value={filters.status} onChange={(event) => setFilters((current) => ({ ...current, status: event.target.value }))}>
          <option value="">All Statuses</option>
          <option value="completed">Completed</option>
          <option value="failed">Failed</option>
          <option value="processing">Processing</option>
        </select>
        <select value={filters.rating} onChange={(event) => setFilters((current) => ({ ...current, rating: event.target.value }))}>
          <option value="">All Ratings</option>
          <option value="rated">Rated</option>
          <option value="not_rated">Not Rated</option>
        </select>
      </div>

      {!filteredRequests.length ? <div className="admin-empty is-compact">No matching prediction requests.</div> : null}
      {filteredRequests.length ? (
        <div className="admin-table-wrap">
          <table className="admin-table admin-prediction-grid">
            <thead>
              <tr>
                <th>Request</th>
                <th>User</th>
                <th>Created</th>
                <th>Last Visited</th>
                <th>Rating</th>
                <th>Prediction Period</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              {filteredRequests.map((request) => (
                <tr key={`${type}-${request.prediction_result_id || request.id}`}>
                  <td>
                    <strong>#{request.prediction_request_id || request.id}</strong>
                    {request.prediction_entitlement_id ? <span>Entitlement #{request.prediction_entitlement_id}</span> : null}
                  </td>
                  <td>
                    <strong>{request.identity?.name || 'User'}</strong>
                    <span>{request.identity?.email || request.email || '-'}</span>
                  </td>
                  <td>{formatDate(request.submitted_at || request.created_at || request.generated_at)}</td>
                  <td>{formatDate(request.last_visited_at)}</td>
                  <td>{request.rating?.label || 'Not Rated'}</td>
                  <td>{request.period?.label || 'Not available'}</td>
                  <td><span className="admin-badge is-blue">{labelFromKey(request.status)}</span></td>
                  <td>
                    <button
                      type="button"
                      className="admin-row-action"
                      disabled={loadingId === request.id}
                      onClick={() => onOpen(request, type)}
                    >
                      {loadingId === request.id ? 'Loading...' : 'Open'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </>
  )
}

function PredictionDetailView({ type, detail, status, onClose, onRegeneratePast, regeneratingPast }) {
  const header = detail?.request || {}
  const result = detail?.result || {}
  const identity = detail?.astrology?.identity || header.identity || {}
  const requestId = header.id || result.prediction_request_id || '-'
  const createdAt = formatDate(header.submitted_at || header.created_at || result.generated_at)
  const lastVisitedAt = formatDate(detail?.last_visited_at)
  const ratingLabel = detail?.rating?.label || 'Not Rated'
  const statusLabel = labelFromKey(result.status || header.status)
  const periodLabelText = detail?.period?.label || 'Prediction period unavailable'

  return (
    <section className="admin-modal-overlay" role="dialog" aria-modal="true" aria-label={`${labelFromKey(type)} prediction details`}>
      <div className="admin-modal prediction-detail-shell">
        <header className="admin-modal-head prediction-detail-header">
          <div className="admin-prediction-title-grid">
            <span>{labelFromKey(type)}</span>
            <span>Request #{requestId}</span>
            <strong>{identity.name || 'Prediction request'}</strong>
            <span>{identity.email || '-'}</span>
            <span>{periodLabelText}</span>
            <span>Created: {createdAt}</span>
            <span>Visited: {lastVisitedAt}</span>
            <span>Rating: {ratingLabel}</span>
          </div>
          <div className="admin-modal-status">
            {type === 'past' && detail ? (
              <button
                type="button"
                className="admin-regenerate-past-button"
                onClick={onRegeneratePast}
                disabled={regeneratingPast}
              >
                {regeneratingPast ? 'Regenerating...' : 'Regenerate Past'}
              </button>
            ) : null}
            <span className="admin-badge is-blue">Status: {statusLabel}</span>
            <button type="button" onClick={onClose} aria-label="Close prediction details">x</button>
          </div>
        </header>

        {status === 'loading' ? <div className="admin-detail-loading">Loading Prediction Details...</div> : null}
        {status === 'error' ? <div className="admin-detail-loading">Unable to load prediction request.</div> : null}
        {status === 'ready' && detail ? (
          <>
            <div className="prediction-detail-body">
              <aside className="prediction-detail-left" aria-label="Astrology data">
                <AdminPredictionResponseViewer astrology={detail.astrology} />
              </aside>
              <div className="prediction-detail-right">
                <section className="admin-prediction-summary-bar" aria-label="Prediction summary">
                  <div>
                    <span>Prediction Type</span>
                    <strong>{labelFromKey(type)}</strong>
                  </div>
                  <div>
                    <span>Request ID</span>
                    <strong>#{requestId}</strong>
                  </div>
                  <div>
                    <span>Name</span>
                    <strong>{identity.name || 'Not available'}</strong>
                  </div>
                  <div>
                    <span>Email</span>
                    <strong>{identity.email || '-'}</strong>
                  </div>
                  <div>
                    <span>Year Range</span>
                    <strong>{periodLabelText}</strong>
                  </div>
                  <div>
                    <span>Created</span>
                    <strong>{createdAt}</strong>
                  </div>
                  <div>
                    <span>Last Visited</span>
                    <strong>{lastVisitedAt}</strong>
                  </div>
                  <div>
                    <span>Rating</span>
                    <strong>{ratingLabel}</strong>
                  </div>
                  <div>
                    <span>Status</span>
                    <strong>{statusLabel}</strong>
                  </div>
                </section>
                <IdentityVideoTable rows={detail.identity_video || []} />
                <PredictionDataTable rows={detail.prediction_data || []} />
              </div>
            </div>
          </>
        ) : null}
      </div>
    </section>
  )
}

function AdminPredictionResponseViewer({ astrology }) {
  if (!astrology) {
    return <div className="admin-empty is-compact">Astrology data is unavailable for this request.</div>
  }

  return (
    <div className="admin-prediction-response-viewer">
      <section className="admin-response-section">
        <h4>Birth</h4>
        <DefinitionGrid data={astrology.birth} />
      </section>
      <section className="admin-response-section">
        <h4>Identity</h4>
        <DefinitionGrid data={astrology.identity} />
      </section>
      <section className="admin-response-section">
        <h4>Chart</h4>
        <GenericValue value={astrology.chart} />
      </section>
      <section className="admin-response-section">
        <h4>Planets Table</h4>
        <GenericRowsTable rows={normalizeViewerRows(astrology.planets)} />
      </section>
      <section className="admin-response-section">
        <h4>Dasha Major Table</h4>
        <GenericRowsTable rows={normalizeViewerRows(astrology.dasha_major)} />
      </section>
    </div>
  )
}

function DefinitionGrid({ data }) {
  const entries = Object.entries(data || {}).filter(([, value]) => value !== null && value !== undefined && value !== '')

  if (!entries.length) return <div className="admin-empty is-compact">No data.</div>

  return (
    <dl className="admin-response-definition-grid">
      {entries.map(([key, value]) => (
        <div key={key}>
          <dt>{labelFromKey(key)}</dt>
          <dd><DisplayValue value={value} /></dd>
        </div>
      ))}
    </dl>
  )
}

function GenericValue({ value }) {
  if (value === null || value === undefined || value === '') {
    return <div className="admin-empty is-compact">No data.</div>
  }

  if (Array.isArray(value)) {
    return <GenericRowsTable rows={normalizeViewerRows(value)} />
  }

  if (typeof value === 'object') {
    return <DefinitionGrid data={value} />
  }

  return <p className="admin-response-plain-value"><DisplayValue value={value} /></p>
}

function GenericRowsTable({ rows }) {
  if (!rows.length) return <div className="admin-empty is-compact">No data.</div>

  const columns = rows.reduce((keys, row) => {
    Object.keys(row || {}).forEach((key) => {
      if (!keys.includes(key)) keys.push(key)
    })
    return keys
  }, [])

  if (!columns.length) return <div className="admin-empty is-compact">No data.</div>

  return (
    <div className="admin-table-wrap">
      <table className="admin-table admin-response-table">
        <thead>
          <tr>{columns.map((key) => <th key={key}>{labelFromKey(key)}</th>)}</tr>
        </thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={`viewer-row-${index}`}>
              {columns.map((key) => (
                <td key={key}><DisplayValue value={row?.[key]} /></td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function DisplayValue({ value }) {
  if (value === null || value === undefined || value === '') return '-'
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (Array.isArray(value) || typeof value === 'object') return <code>{JSON.stringify(value)}</code>

  return String(value)
}

function normalizeViewerRows(value) {
  if (!Array.isArray(value)) return []

  return value
    .filter((row) => row && typeof row === 'object')
    .map((row) => ({ ...row }))
}

function IdentityVideoTable({ rows }) {
  return (
    <ReportTable
      title="Identity Video"
      empty="Identity video trace is unavailable for this stored result."
      columns={[
        ['excel_file', 'Excel File'],
        ['sheet_name', 'Sheet'],
        ['rasi', 'Rasi'],
        ['nakshatra', 'Nakshatra'],
        ['lagnam', 'Lagnam'],
        ['excel_row_number', 'Excel Row'],
        ['video_id', 'Video ID'],
        ['status_warning', 'Status / Warning'],
      ]}
      rows={rows}
    />
  )
}

function PredictionDataTable({ rows }) {
  return (
    <ReportTable
      title="Prediction Data"
      empty="Detailed engine trace unavailable for this older result."
      columns={[
        ['age', 'Age / Range'],
        ['system_year', 'System Year / Range'],
        ['dasha', 'Dasha'],
        ['dasha_period', 'Dasha Period'],
        ['excel_file', 'Excel File'],
        ['sheet_name', 'Sheet'],
        ['excel_year', 'Excel Year'],
        ['excel_cell', 'Cell'],
        ['video_id', 'Video ID'],
        ['status_warning', 'Status / Warning'],
      ]}
      rows={rows}
    />
  )
}

function ReportTable({ title, columns, rows, empty }) {
  const tableClassName = `admin-report-card ${title === 'Prediction Data' ? 'is-scroll-table' : ''}`

  return (
    <section className={tableClassName}>
      <h4>{title}</h4>
      {!rows.length ? <div className="admin-empty is-compact">{empty}</div> : (
        <div className="admin-table-wrap">
          <table className="admin-table admin-report-table">
            <thead>
              <tr>{columns.map(([, label]) => <th key={label}>{label}</th>)}</tr>
            </thead>
            <tbody>
              {rows.map((row, index) => (
                <tr key={`${title}-${index}`}>
                  {columns.map(([key]) => <td key={key}>{row[key] ?? '-'}</td>)}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}

function remedyAmountLabel(remedy, group) {
  if (group === 'completed' && remedy.payment_status !== 'PAID' && !remedy.amount_minor) return 'Free'
  if (!remedy.amount_minor) return '-'

  return formatMoney(remedy.amount_minor, remedy.currency)
}

function remedyStateLabel(remedy, group) {
  if (group === 'completed') return 'Completed'
  if (group === 'payment_received') return 'Payment Received'
  if (remedy.payment_method === 'qr_online') return 'GPay QR'

  return 'Gateway Pending'
}

function completionLabel(remedy) {
  if (remedy.payment_status === 'PAID' || remedy.completion_method === 'payment') return 'Paid'
  if (remedy.completion_method === 'manual') return 'Manual'

  return 'Manual'
}

function RemedyList({ remedies, group, counts, isLoading, isError, edits, selectedRemedyId, onGroupChange, onSelect, onClose, onChange, onSave, savingRemedyId }) {
  const selectedRemedy = remedies.find((remedy) => remedy.id === selectedRemedyId) || null
  const [gridPaymentProofPreview, setGridPaymentProofPreview] = useState(null)
  const isCompletedGroup = group === 'completed'
  const emptyMessage = isCompletedGroup
    ? 'No completed Temple Remedies yet.'
    : group === 'payment_received'
      ? 'No Temple Remedy payments received yet.'
      : 'No Temple Remedy payments pending.'

  const tabs = [
    { id: 'payment_pending', label: 'Payment Pending', count: counts.payment_pending },
    { id: 'payment_received', label: 'Payment Received', count: counts.payment_received },
    { id: 'completed', label: 'Completed', count: counts.completed },
  ]

  const closeGridPaymentProof = () => {
    if (gridPaymentProofPreview?.url) {
      URL.revokeObjectURL(gridPaymentProofPreview.url)
    }
    setGridPaymentProofPreview(null)
  }

  const openGridPaymentProof = async (event, remedy) => {
    event.stopPropagation()
    if (!remedy.manual_payment?.file_url) return

    try {
      const blob = await fetchAdminTempleRemedyManualPaymentFile(remedy.id)
      if (gridPaymentProofPreview?.url) {
        URL.revokeObjectURL(gridPaymentProofPreview.url)
      }
      const name = remedy.manual_payment?.original_name || 'Payment proof'
      const type = blob.type || remedy.manual_payment?.mime_type || ''
      setGridPaymentProofPreview({
        url: URL.createObjectURL(blob),
        type,
        name,
        isImage: type.startsWith('image/') || /\.(jpe?g|png|webp)$/i.test(name),
      })
    } catch {
      setGridPaymentProofPreview({
        url: '',
        type: '',
        name: 'Payment proof',
        isImage: false,
        error: 'Unable to load payment proof.',
      })
    }
  }

  useEffect(() => () => {
    if (gridPaymentProofPreview?.url) {
      URL.revokeObjectURL(gridPaymentProofPreview.url)
    }
  }, [gridPaymentProofPreview])

  return (
    <>
      <div className="admin-remedy-tabs" aria-label="Temple Remedy request groups">
        {tabs.map((tab) => (
          <button
            key={tab.id}
            type="button"
            className={group === tab.id ? 'is-active' : ''}
            onClick={() => onGroupChange(tab.id)}
          >
            <span>{tab.label}</span>
            <strong>{tab.count ?? 0}</strong>
          </button>
        ))}
      </div>

      {isLoading ? <AdminProgress label="Loading Temple Remedy requests..." /> : null}
      {isError ? <div className="admin-empty is-compact">Unable to load Temple Remedy requests.</div> : null}
      {!isLoading && !isError && !remedies.length ? <div className="admin-empty">{emptyMessage}</div> : null}

      {!isLoading && !isError && remedies.length ? (
      <div className="admin-table-wrap">
        <table className="admin-table admin-remedy-grid">
          <thead>
            <tr>
              <th>Request</th>
              <th>User</th>
              <th>Contact</th>
              <th>Problem</th>
              <th>{isCompletedGroup ? 'Completion' : 'Status'}</th>
              <th>Amount</th>
              <th>{group === 'payment_pending' ? 'Payment Proof' : 'Remedy'}</th>
              <th>{isCompletedGroup ? 'Completed On' : 'Submitted'}</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            {remedies.map((remedy) => (
              <tr key={remedy.id} onClick={() => onSelect(remedy.id)}>
                <td>
                  <strong>#{remedy.id}</strong>
                  <span>{remedy.completion_method ? labelFromKey(remedy.completion_method) : 'Open request'}</span>
                </td>
                <td>
                  <strong>{remedy.user?.name || 'User'}</strong>
                  <span>{remedy.user?.email || '-'}</span>
                </td>
                <td>
                  <strong>{remedy.contact_number || '-'}</strong>
                  <span>{remedy.alternate_contact_number || remedy.preferred_language || '-'}</span>
                </td>
                <td className="admin-remedy-problem-cell">{remedy.problem_text}</td>
                <td>
                  <span className="admin-badge is-blue">{remedy.payment_status === 'PAID' ? 'Payment Received' : 'Payment Pending'}</span>
                  {isCompletedGroup ? <small>{completionLabel(remedy)}</small> : null}
                </td>
                <td>{remedyAmountLabel(remedy, group)}</td>
                <td>
                  {group === 'payment_pending' ? (
                    remedy.manual_payment?.file_url ? (
                      <button type="button" className="admin-row-action" onClick={(event) => openGridPaymentProof(event, remedy)}>
                        View Proof
                      </button>
                    ) : (
                      <span className="admin-badge is-muted">No Proof</span>
                    )
                  ) : (
                    <>
                      <span className={`admin-badge ${remedy.remedy_file_path || remedy.remedy_url ? 'is-green' : 'is-muted'}`}>
                        {remedyStateLabel(remedy, group)}
                      </span>
                      {remedy.remedy_file_name ? <small>{fileKind(remedy.remedy_file_name, remedy.remedy_mime_type)}</small> : null}
                    </>
                  )}
                </td>
                <td>{formatDate(isCompletedGroup ? remedy.completed_at : remedy.submitted_at)}</td>
                <td><button type="button" className="admin-row-action" onClick={(event) => { event.stopPropagation(); onSelect(remedy.id) }}>Open</button></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      ) : null}

      {selectedRemedy ? (
        <RemedyModal
          remedy={selectedRemedy}
          draft={edits[selectedRemedy.id] || remedyToEdit(selectedRemedy)}
          onChange={onChange}
          onSave={onSave}
          onClose={onClose}
          isSaving={savingRemedyId === selectedRemedy.id}
        />
      ) : null}
      {gridPaymentProofPreview ? (
        <TemplePaymentProofLightbox
          preview={gridPaymentProofPreview}
          isImage={gridPaymentProofPreview.isImage}
          onClose={closeGridPaymentProof}
        />
      ) : null}
    </>
  )
}

function RemedyModal({ remedy, draft, onChange, onSave, onClose, isSaving }) {
  const isCompleted = draft.status === 'COMPLETED'
  const needsPreparedRemedy = isCompleted
  const isGatewayPaid = remedy.payment_method !== 'qr_online' && remedy.payment_status === 'PAID'
  const isQrPayment = remedy.payment_method === 'qr_online'
  const availableStatusOptions = remedy.status === 'COMPLETED'
    ? ['COMPLETED']
    : draft.payment_status === 'PAID'
      ? statusOptions
      : ['PAYMENT_PENDING']
  const hasExistingFile = Boolean(remedy.remedy_file_path || remedy.remedy_url)
  const [astrologyState, setAstrologyState] = useState({ status: 'loading', payload: null, message: '' })
  const [fileError, setFileError] = useState('')
  const [paymentProofPreview, setPaymentProofPreview] = useState({ status: 'idle', url: '', type: '', name: '' })
  const [isPaymentProofOpen, setIsPaymentProofOpen] = useState(false)

  const loadAstrology = async () => {
    setAstrologyState({ status: 'loading', payload: null, message: '' })
    try {
      const response = await fetchAdminTempleRemedyAstrology(remedy.id)
      setAstrologyState({
        status: response.available ? 'ready' : 'empty',
        payload: response,
        message: response.message || '',
      })
    } catch {
      setAstrologyState({ status: 'error', payload: null, message: 'Unable to load astrology data.' })
    }
  }

  useEffect(() => {
    loadAstrology()
    // Reload only when the selected request changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [remedy.id])

  useEffect(() => {
    let isMounted = true
    let objectUrl = ''

    async function loadPaymentProofPreview() {
      if (!remedy.manual_payment?.file_url) {
        setPaymentProofPreview({ status: 'empty', url: '', type: '', name: '' })
        return
      }

      setPaymentProofPreview({
        status: 'loading',
        url: '',
        type: remedy.manual_payment?.mime_type || '',
        name: remedy.manual_payment?.original_name || 'Payment proof',
      })

      try {
        const blob = await fetchAdminTempleRemedyManualPaymentFile(remedy.id)
        if (!isMounted) return
        objectUrl = URL.createObjectURL(blob)
        setPaymentProofPreview({
          status: 'ready',
          url: objectUrl,
          type: blob.type || remedy.manual_payment?.mime_type || '',
          name: remedy.manual_payment?.original_name || 'Payment proof',
        })
      } catch {
        if (!isMounted) return
        setPaymentProofPreview({
          status: 'error',
          url: '',
          type: remedy.manual_payment?.mime_type || '',
          name: remedy.manual_payment?.original_name || 'Payment proof',
        })
      }
    }

    loadPaymentProofPreview()

    return () => {
      isMounted = false
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [remedy.id, remedy.manual_payment?.file_url, remedy.manual_payment?.mime_type, remedy.manual_payment?.original_name])

  const validateAndSetFile = (file) => {
    if (!file) {
      onChange(remedy.id, 'remedy_file', null)
      setFileError('')
      return
    }

    const allowedTypes = ['application/pdf']
    const allowedExtensions = /\.pdf$/i

    if (!allowedTypes.includes(file.type) && !allowedExtensions.test(file.name)) {
      setFileError('Unsupported file type. Upload the remedy as a PDF.')
      return
    }

    if (file.size > 50 * 1024 * 1024) {
      setFileError('File is too large. Maximum size is 50 MB.')
      return
    }

    setFileError('')
    onChange(remedy.id, 'remedy_file', file)
  }

  const paymentProofIsImage = paymentProofPreview.type?.startsWith('image/') || /\.(jpe?g|png|webp)$/i.test(paymentProofPreview.name || '')

  return (
    <section className="admin-modal-overlay" role="dialog" aria-modal="true" aria-label="Temple remedy request details">
      <form className="admin-modal admin-remedy-workspace" onSubmit={(event) => { event.preventDefault(); if (!isSaving) onSave(remedy.id) }}>
        <header className="admin-modal-head">
          <div>
            <span>Request #{remedy.id}</span>
            <h3>{remedy.user?.name || 'Temple remedy request'}</h3>
            <p>{remedy.user?.email || '-'}</p>
            <p>{formatDate(remedy.submitted_at)}</p>
          </div>
          <div className="admin-modal-status">
            <button type="button" disabled={isSaving} onClick={onClose} aria-label="Close temple remedy details">x</button>
          </div>
        </header>

        <div className="admin-remedy-workspace-body">
          <div className="admin-remedy-form-panel">
            <section className="admin-remedy-request-card">
              <span>Selected Remedy</span>
              <strong>{remedy.remedy_option?.title || 'Temple Remedy'}</strong>
              {remedy.problem_text ? (
                <div>
                  <small>User Comments</small>
                  <p>{remedy.problem_text}</p>
                </div>
              ) : null}
            </section>

            <section className="admin-remedy-info-card">
              <div>
                <span>Contact</span>
                <strong>{remedy.contact_number || '-'}</strong>
              </div>
              <div>
                <span>Alternate</span>
                <strong>{remedy.alternate_contact_number || '-'}</strong>
              </div>
              <div>
                <span>Language</span>
                <strong>{languageLabel(remedy.preferred_language)}</strong>
              </div>
              <div>
                <span>Payment Method</span>
                <strong>{isQrPayment ? 'GPay QR' : 'Razorpay Gateway'}</strong>
              </div>
              <div>
                <span>Payment Status</span>
                <strong>{remedy.payment_status === 'PAID' ? 'Payment Received' : 'Payment Pending'}</strong>
              </div>
              <div>
                <span>Amount</span>
                <strong>{remedyAmountLabel(remedy, 'payment_received')}</strong>
              </div>
              {isQrPayment ? (
                <div className="admin-payment-proof-cell">
                  <span>Payment Proof</span>
                  <TemplePaymentProofPreview
                    preview={paymentProofPreview}
                    isImage={paymentProofIsImage}
                    onOpen={() => setIsPaymentProofOpen(true)}
                  />
                </div>
              ) : null}
            </section>

            <div className={`admin-remedy-form is-modal-form ${needsPreparedRemedy ? 'has-remedy-fields' : ''}`}>
              {needsPreparedRemedy ? (
                <>
                  <label className="is-wide">
                    Remedy Details Title
                    <input disabled={isSaving} required value={draft.remedy_title} onChange={(event) => onChange(remedy.id, 'remedy_title', event.target.value)} placeholder="Temple visit and prayer guidance" />
                  </label>
                  <RemedyFileDropzone
                    remedyId={remedy.id}
                    disabled={isSaving}
                    selectedFile={draft.remedy_file}
                    existingFileName={remedy.remedy_file_name}
                    existingFileSize={remedy.remedy_file_size}
                    existingMimeType={remedy.remedy_mime_type}
                    hasExistingFile={hasExistingFile}
                    required={!hasExistingFile}
                    error={fileError}
                    onFile={validateAndSetFile}
                  />
                </>
              ) : null}
              <label className="is-wide">
                Remedy Details (optional)
                <textarea disabled={isSaving} value={draft.admin_note} onChange={(event) => onChange(remedy.id, 'admin_note', event.target.value)} rows="5" placeholder="Optional details that will be shown in My Account and emailed to the user." />
              </label>
            </div>
          </div>

          <AstrologyPanel state={astrologyState} onRetry={loadAstrology} />
        </div>

        <footer className="admin-modal-actions">
          <label className="admin-footer-status-control">
            Status
            <select disabled={isSaving} value={draft.status} onChange={(event) => onChange(remedy.id, 'status', event.target.value)}>
              {availableStatusOptions.map((option) => <option key={option} value={option}>{labelFromKey(option)}</option>)}
            </select>
          </label>
          {!isGatewayPaid && isQrPayment ? (
            <label className="admin-footer-status-control">
              Payment
              <select disabled={isSaving || isCompleted} value={draft.payment_status} onChange={(event) => onChange(remedy.id, 'payment_status', event.target.value)}>
                <option value="PENDING">Payment Pending</option>
                <option value="PAID">Payment Received</option>
              </select>
            </label>
          ) : null}
          <button type="button" disabled={isSaving} onClick={onClose}>Cancel</button>
          <button type="submit" disabled={isSaving}>
            {isSaving ? <span className="admin-save-spinner" aria-hidden="true" /> : null}
            {isSaving ? 'Saving Remedy...' : 'Save Remedy'}
          </button>
        </footer>
      </form>
      {isPaymentProofOpen ? (
        <TemplePaymentProofLightbox
          preview={paymentProofPreview}
          isImage={paymentProofIsImage}
          onClose={() => setIsPaymentProofOpen(false)}
        />
      ) : null}
    </section>
  )
}

function TemplePaymentProofPreview({ preview, isImage, onOpen }) {
  if (preview.status === 'loading') return <small>Loading payment proof...</small>
  if (preview.status === 'error') return <small>Unable to load payment proof.</small>
  if (preview.status !== 'ready' || !preview.url) return <small>No payment proof uploaded.</small>

  if (!isImage) {
    return (
      <a className="admin-inline-link" href={preview.url} target="_blank" rel="noreferrer">
        View Payment Proof
      </a>
    )
  }

  return (
    <button type="button" className="admin-payment-proof-thumb" onClick={onOpen}>
      <img src={preview.url} alt="Client uploaded Temple Remedy payment proof" />
      <strong>View Payment Proof</strong>
    </button>
  )
}

function TemplePaymentProofLightbox({ preview, isImage, onClose }) {
  return (
    <div className="admin-nested-modal-overlay" role="dialog" aria-modal="true" aria-label="Temple Remedy payment proof preview">
      <section className="admin-nested-modal admin-payment-proof-lightbox">
        <header>
          <div>
            <span>Client Payment Proof</span>
            <h3>{preview.name || 'Payment proof'}</h3>
          </div>
          <button type="button" onClick={onClose} aria-label="Close payment proof preview">x</button>
        </header>
        <div className="admin-payment-proof-large">
          {preview.error ? (
            <p>{preview.error}</p>
          ) : isImage && preview.url ? (
            <img src={preview.url} alt="Client uploaded Temple Remedy payment proof" />
          ) : preview.url ? (
            <a className="admin-inline-link" href={preview.url} target="_blank" rel="noreferrer">Open Payment Proof</a>
          ) : (
            <p>No payment proof uploaded.</p>
          )}
        </div>
      </section>
    </div>
  )
}

function RemedyFileDropzone({ remedyId, disabled, selectedFile, existingFileName, existingFileSize, existingMimeType, hasExistingFile, required, error, onFile }) {
  const [isDragging, setIsDragging] = useState(false)
  const [previewUrl, setPreviewUrl] = useState('')
  const [existingPreviewState, setExistingPreviewState] = useState('idle')
  const inputId = 'admin-remedy-file-input'
  const displayName = selectedFile?.name || existingFileName || 'prepared remedy'
  const displaySize = selectedFile ? formatFileSize(selectedFile.size) : formatFileSize(existingFileSize)
  const displayKind = fileKind(displayName, selectedFile?.type || existingMimeType)

  useEffect(() => {
    let isMounted = true
    let objectUrl = ''

    if (selectedFile) {
      objectUrl = URL.createObjectURL(selectedFile)
      setPreviewUrl(objectUrl)
      setExistingPreviewState('idle')

      return () => {
        isMounted = false
        URL.revokeObjectURL(objectUrl)
      }
    }

    if (!hasExistingFile || !remedyId) {
      setPreviewUrl('')
      setExistingPreviewState('idle')
      return undefined
    }

    setPreviewUrl('')
    setExistingPreviewState('loading')

    fetchAdminTempleRemedyFile(remedyId)
      .then((blob) => {
        if (!isMounted) return
        objectUrl = URL.createObjectURL(blob)
        setPreviewUrl(objectUrl)
        setExistingPreviewState('ready')
      })
      .catch(() => {
        if (!isMounted) return
        setPreviewUrl('')
        setExistingPreviewState('error')
      })

    return () => {
      isMounted = false
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [hasExistingFile, remedyId, selectedFile])

  const handleDrop = (event) => {
    event.preventDefault()
    setIsDragging(false)
    if (disabled) return
    onFile(event.dataTransfer.files?.[0] || null)
  }

  return (
    <div className="admin-file-field is-wide">
      <label htmlFor={inputId}>Remedy File</label>
      <div className="admin-file-browser-shell">
        <div
          className={`admin-file-dropzone ${isDragging ? 'is-dragging' : ''} ${error ? 'has-error' : ''}`}
          onDragOver={(event) => { event.preventDefault(); if (!disabled) setIsDragging(true) }}
          onDragLeave={() => setIsDragging(false)}
          onDrop={handleDrop}
        >
          <input
            id={inputId}
            type="file"
            required={required && !selectedFile}
            disabled={disabled}
            accept=".pdf,application/pdf"
            onChange={(event) => onFile(event.target.files?.[0] || null)}
          />
          {selectedFile || hasExistingFile ? (
            <div className="admin-file-selected">
              <span className="admin-file-icon">{displayKind.slice(0, 1)}</span>
              <div>
                <strong>{selectedFile ? displayName : 'Existing remedy'}</strong>
                <p>{selectedFile ? displayName : displayName}</p>
                <small>{[displayKind, displaySize].filter(Boolean).join(' - ')}</small>
              </div>
              {selectedFile ? (
                <button type="button" disabled={disabled} onClick={() => onFile(null)}>Remove</button>
              ) : (
                <span>Replace by browsing</span>
              )}
            </div>
          ) : (
            <div className="admin-file-empty">
              <strong>Drag and drop the remedy file here</strong>
              <span>or click to browse</span>
              <small>PDF, JPG, JPEG, PNG, WEBP, MP4 up to 50 MB</small>
            </div>
          )}
        </div>

        <div className="admin-file-preview" aria-label="Selected remedy preview">
          {previewUrl ? (
            displayKind === 'Image' ? (
              <img src={previewUrl} alt={displayName} />
            ) : displayKind === 'Video' ? (
              <video src={previewUrl} controls />
            ) : displayKind === 'PDF' ? (
              <iframe src={previewUrl} title={`Preview ${displayName}`} />
            ) : (
              <div className="admin-file-preview-empty">
                <strong>{displayKind}</strong>
                <span>Preview is not available for this file type.</span>
              </div>
            )
          ) : hasExistingFile ? (
            <div className="admin-file-preview-empty">
              <strong>{existingPreviewState === 'loading' ? 'Loading existing remedy...' : 'Existing remedy retained'}</strong>
              <span>{existingPreviewState === 'error' ? 'Unable to load the saved preview. You can still replace the file.' : 'Select a replacement file to preview it here.'}</span>
            </div>
          ) : (
            <div className="admin-file-preview-empty">
              <strong>Preview</strong>
              <span>Your selected file will appear here.</span>
            </div>
          )}
        </div>
      </div>
      {error ? <p className="admin-file-error">{error}</p> : null}
    </div>
  )
}

function ChatbotAdminPanel({ view, faqs, topics, intents, settings, cacheCount, status, editor, isSaving, onViewChange, onNew, onEdit, onClose, onSave, onDeactivate, onSaveSettings, onClearCache }) {
  const isLoading = status === 'loading'
  const isError = status === 'error'
  const countForView = (tabId) => {
    if (tabId === 'topics') return topics.length
    if (tabId === 'intents') return intents.length
    if (tabId === 'settings') return settings ? 1 : 0
    return faqs.length
  }

  return (
    <>
      <div className="admin-chatbot-toolbar">
        <div className="admin-remedy-tabs" aria-label="Chatbot sections">
          {chatbotViews.map((tab) => (
            <button
              key={tab.id}
              type="button"
              className={view === tab.id ? 'is-active' : ''}
              onClick={() => onViewChange(tab.id)}
            >
              <span>{tab.label}</span>
              <strong>{countForView(tab.id)}</strong>
            </button>
          ))}
        </div>
        {view !== 'settings' ? (
          <button type="button" className="admin-row-action" onClick={() => onNew(view)}>
            Add {chatbotViews.find((tab) => tab.id === view)?.label.slice(0, -1) || 'Record'}
          </button>
        ) : null}
      </div>

      {isLoading ? <AdminProgress label="Loading chatbot records..." /> : null}
      {isError ? <div className="admin-empty is-compact">Unable to load chatbot records.</div> : null}
      {!isLoading && !isError && view === 'faqs' ? <ChatbotFaqTable faqs={faqs} onEdit={(record) => onEdit('faqs', record)} /> : null}
      {!isLoading && !isError && view === 'topics' ? <ChatbotTopicTable topics={topics} onEdit={(record) => onEdit('topics', record)} /> : null}
      {!isLoading && !isError && view === 'intents' ? <ChatbotIntentTable intents={intents} onEdit={(record) => onEdit('intents', record)} /> : null}
      {!isLoading && !isError && view === 'settings' ? (
        <ChatbotSettingsPanel
          settings={settings}
          cacheCount={cacheCount}
          isSaving={isSaving}
          onSave={onSaveSettings}
          onClearCache={onClearCache}
        />
      ) : null}

      {editor ? (
        <ChatbotEditorModal
          view={editor.view}
          record={editor.record}
          topics={topics}
          intents={intents}
          isSaving={isSaving}
          onClose={onClose}
          onSave={onSave}
          onDeactivate={onDeactivate}
        />
      ) : null}
    </>
  )
}

function FutureVideoPaymentVerificationPanel({
  payments,
  counts,
  filter,
  search,
  status,
  savingPaymentId,
  onFilterChange,
  onSearchChange,
  onSearchSubmit,
  onRefresh,
  onViewProof,
  onVerify,
  onReject,
}) {
  const isLoading = status === 'loading'
  const isError = status === 'error'

  return (
    <div className="admin-future-payment-verification">
      <div className="admin-verification-toolbar">
        <div className="admin-remedy-tabs admin-verification-tabs" aria-label="Future Video payment status filters">
          {futureManualPaymentFilters.map((tab) => (
            <button
              key={tab.id}
              type="button"
              className={filter === tab.id ? 'is-active' : ''}
              onClick={() => onFilterChange(tab.id)}
            >
              <span>{tab.label}</span>
              <strong>{counts?.[tab.id] ?? 0}</strong>
            </button>
          ))}
        </div>

        <form className="admin-verification-search" onSubmit={onSearchSubmit}>
          <input
            type="search"
            value={search}
            placeholder="Search reference, user, or email"
            onChange={(event) => onSearchChange(event.target.value)}
          />
          <button type="submit" className="admin-row-action">Search</button>
          <button type="button" className="admin-row-action" onClick={onRefresh}>Refresh</button>
        </form>
      </div>

      {isLoading ? <AdminProgress label="Loading payment submissions..." /> : null}
      {isError ? <div className="admin-empty is-compact">Unable to load payment submissions.</div> : null}
      {!isLoading && !isError && !payments.length ? <div className="admin-empty">No Future Video manual payments found.</div> : null}

      {!isLoading && !isError && payments.length ? (
        <div className="admin-table-wrap">
          <table className="admin-table admin-future-payment-table">
            <thead>
              <tr>
                <th>User</th>
                <th>Future Video</th>
                <th>Amount</th>
                <th>Payment Method</th>
                <th>Submitted</th>
                <th>Status</th>
                <th>Payment Proof</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {payments.map((payment) => (
                <tr key={payment.id}>
                  <td>
                    <strong>{payment.user?.name || payment.prediction_request?.full_name || 'User'}</strong>
                    <small>{payment.user?.email || payment.prediction_request?.email || 'No email'}</small>
                  </td>
                  <td>
                    <strong>{payment.product?.name || 'Future Video'}</strong>
                    <small>{payment.purchase_reference}</small>
                  </td>
                  <td>{payment.amount_label || formatMoney(payment.amount_minor, payment.currency)}</td>
                  <td>{payment.payment_method_label || 'GPay QR'}</td>
                  <td>{formatDate(payment.submitted_at)}</td>
                  <td><FutureManualPaymentStatusBadge status={payment.status} /></td>
                  <td>
                    <button type="button" className="admin-row-action" onClick={() => onViewProof(payment)}>
                      View Proof
                    </button>
                    <small>{payment.payment_proof?.file_name || 'Uploaded image'}</small>
                  </td>
                  <td>
                    {payment.status === 'PENDING' ? (
                      <div className="admin-verification-actions">
                        <button
                          type="button"
                          className="admin-row-action is-success"
                          disabled={savingPaymentId === payment.id}
                          onClick={() => onVerify(payment)}
                        >
                          {savingPaymentId === payment.id ? 'Saving...' : 'Verify Payment'}
                        </button>
                        <button
                          type="button"
                          className="admin-row-action is-danger"
                          disabled={savingPaymentId === payment.id}
                          onClick={() => onReject(payment)}
                        >
                          Reject
                        </button>
                      </div>
                    ) : (
                      <span className="admin-muted-cell">{payment.status === 'VERIFIED' ? `Verified ${formatDate(payment.verified_at)}` : 'No action'}</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </div>
  )
}

function FutureManualPaymentStatusBadge({ status }) {
  const normalized = String(status || '').toUpperCase()
  const badgeClass = normalized === 'VERIFIED' ? 'is-green' : normalized === 'REJECTED' ? 'is-muted' : 'is-gold'

  return <span className={`admin-badge ${badgeClass}`}>{labelFromKey(normalized || 'PENDING')}</span>
}

function PredictionRegenerationPanel({ settings, markingType, onMark }) {
  const byType = Object.fromEntries((settings || []).map((setting) => [setting.prediction_type, setting]))

  return (
    <section className="admin-regeneration-settings">
      <div className="admin-regeneration-head">
        <span className="admin-payment-service-icon"><AdminIcon name="refresh" /></span>
        <div>
          <h3>Prediction Regeneration</h3>
          <p>Mark stored predictions to recalculate lazily on next access</p>
        </div>
      </div>

      <div className="admin-regeneration-grid">
        {['past', 'future'].map((predictionType) => {
          const setting = byType[predictionType] || { prediction_type: predictionType, regeneration_version: 0, is_enabled: false }
          const title = predictionType === 'future' ? 'Future Prediction' : 'Past Prediction'
          const isSaving = markingType === predictionType

          return (
            <article className="admin-regeneration-card" key={predictionType}>
              <div>
                <h4>{title}</h4>
                <p>Current regeneration version: <strong>{setting.regeneration_version ?? 0}</strong></p>
                <small>{setting.is_enabled ? `Enabled${setting.updated_at ? ` since ${formatDate(setting.updated_at)}` : ''}` : 'No regeneration cycle is active'}</small>
              </div>
              <button type="button" disabled={Boolean(markingType)} onClick={() => onMark(predictionType)}>
                {isSaving ? <span className="admin-save-spinner" aria-hidden="true" /> : null}
                {isSaving ? 'Marking...' : `Mark All ${predictionType === 'future' ? 'Future' : 'Past'} Predictions for Regeneration`}
              </button>
            </article>
          )
        })}
      </div>
    </section>
  )
}

function FuturePaymentProofModal({ proof, onClose }) {
  const isImage = proof.mimeType?.startsWith('image/') || /\.(jpe?g|png|webp)$/i.test(proof.fileName || '')

  return (
    <div className="admin-modal-overlay" role="dialog" aria-modal="true" aria-label="Future Video payment proof">
      <section className="admin-modal admin-proof-modal">
        <header className="admin-modal-head">
          <div>
            <span>Future Video Payment</span>
            <h3>Payment Proof</h3>
            <p>{proof.fileName}</p>
          </div>
          <button type="button" onClick={onClose} aria-label="Close payment proof">x</button>
        </header>
        <div className="admin-proof-preview">
          {isImage ? (
            <img src={proof.url} alt="Uploaded payment proof" />
          ) : (
            <a href={proof.url} target="_blank" rel="noreferrer">Open payment proof</a>
          )}
        </div>
      </section>
    </div>
  )
}

function PaymentMethodSettingsPanel({ settings, drafts, status, savingService, onChange, onSave }) {
  const isLoading = status === 'loading'
  const isError = status === 'error'

  if (isLoading) return <AdminProgress label="Loading payment method settings..." />
  if (isError) return <div className="admin-empty is-compact">Unable to load payment method settings.</div>
  if (!settings.length) return <div className="admin-empty">No payment method settings found.</div>

  return (
    <div className="admin-payment-settings">
      {settings.map((setting) => {
        const draft = drafts[setting.service_type] || paymentSettingDraft(setting)
        const isSaving = savingService === setting.service_type
        const meta = paymentServiceMeta[setting.service_type] || { icon: 'star', tone: 'blue' }
        const title = paymentServiceLabels[setting.service_type] || labelFromKey(setting.service_type)
        const qrEnabled = Boolean(draft.gpay_qr_enabled)
        const hasQrImage = Boolean(setting.qr_image_url)

        return (
          <section className={`admin-payment-settings-card is-${meta.tone}`} key={setting.service_type}>
            <div className="admin-payment-settings-head">
              <span className="admin-payment-service-icon"><AdminIcon name={meta.icon} /></span>
              <div>
                <h3>{title}</h3>
                <p>Configure available payment methods</p>
              </div>
            </div>

            <div className="admin-payment-options">
              <PaymentToggle
                icon="card"
                label="Payment Gateway"
                checked={Boolean(draft.payment_gateway_enabled)}
                disabled={isSaving}
                onChange={(checked) => onChange(setting.service_type, 'payment_gateway_enabled', checked)}
              />

              <PaymentToggle
                icon="qr"
                label="GPay QR Payment"
                checked={qrEnabled}
                disabled={isSaving}
                onChange={(checked) => onChange(setting.service_type, 'gpay_qr_enabled', checked)}
              />
            </div>

            <div className={`admin-payment-qr-section ${qrEnabled ? '' : 'is-muted'}`}>
              <h4>GPay QR Code</h4>

              <div className="admin-payment-qr-body">
                <div className="admin-payment-qr-preview">
                  {hasQrImage ? <img src={setting.qr_image_url} alt={`${title} QR code`} /> : <span>No QR uploaded</span>}
                </div>

                <div className="admin-payment-file-control">
                  <label className="admin-payment-upload-button">
                    <AdminIcon name="upload" />
                    <span>{hasQrImage ? 'Change QR' : 'Upload QR'}</span>
                    <input
                      disabled={isSaving || !qrEnabled}
                      type="file"
                      accept="image/png,image/jpeg,image/webp"
                      onChange={(event) => onChange(setting.service_type, 'qr_image', event.target.files?.[0] || null)}
                    />
                  </label>
                  <small>{draft.qr_image?.name || (hasQrImage ? 'QR image uploaded' : 'PNG, JPG, or WEBP')}</small>
                </div>
              </div>
            </div>

            <button className="admin-payment-save-button" type="button" disabled={isSaving} onClick={() => onSave(setting.service_type)}>
              {isSaving ? <span className="admin-save-spinner" aria-hidden="true" /> : null}
              {isSaving ? 'Saving...' : 'Save Changes'}
            </button>
          </section>
        )
      })}
    </div>
  )
}

function PaymentToggle({ icon, label, checked, disabled, onChange }) {
  return (
    <div className="admin-payment-toggle-row">
      <span className="admin-payment-option-icon"><AdminIcon name={icon} /></span>
      <strong>{label}</strong>
      <label className="admin-payment-switch">
        <input type="checkbox" checked={checked} disabled={disabled} onChange={(event) => onChange(event.target.checked)} />
        <span />
      </label>
      <em>{checked ? 'ON' : 'OFF'}</em>
    </div>
  )
}

function ChatbotSettingsPanel({ settings, cacheCount, isSaving, onSave, onClearCache }) {
  const [draft, setDraft] = useState(() => chatbotSettingsDraft(settings))

  useEffect(() => {
    setDraft(chatbotSettingsDraft(settings))
  }, [settings])

  const updateDraft = (key, value) => {
    setDraft((current) => ({ ...current, [key]: value }))
  }

  const submit = (event) => {
    event.preventDefault()
    onSave({
      chatbot_model: draft.chatbot_model,
      openai_model: draft.openai_model.trim(),
      openai_api_key: draft.openai_api_key.trim(),
      openai_fallback_ygt: Number(draft.openai_fallback_ygt),
      openai_cache_enabled: Number(draft.openai_cache_enabled),
      openai_knowledge_version: Number(draft.openai_knowledge_version || 1),
    })
  }

  return (
    <form className="admin-chatbot-settings" onSubmit={submit}>
      <section className="admin-chatbot-settings-card">
        <div>
          <span>Chatbot Model</span>
          <h3>Response Engine</h3>
          <p>Switch between the current YGT rule-based FAQ model and prepared OpenAI mode.</p>
        </div>
        <label>
          Active Model
          <select disabled={isSaving} value={draft.chatbot_model} onChange={(event) => updateDraft('chatbot_model', event.target.value)}>
            <option value="ygt">YGT Model</option>
            <option value="openai">OpenAI Model</option>
          </select>
        </label>
      </section>

      <section className="admin-chatbot-settings-card">
        <div>
          <span>OpenAI</span>
          <h3>Configuration</h3>
          <p>{settings?.openai_api_key_configured ? `Configured: ${settings.openai_api_key_masked || 'Configured'}` : 'API key is not configured.'}</p>
        </div>
        <label>
          OpenAI Model
          <input disabled={isSaving} value={draft.openai_model} maxLength="100" placeholder="Example: gpt-4.1-mini" onChange={(event) => updateDraft('openai_model', event.target.value)} />
        </label>
        <label>
          OpenAI API Key
          <input disabled={isSaving} type="password" value={draft.openai_api_key} maxLength="500" placeholder={settings?.openai_api_key_configured ? 'Enter new key to replace' : 'Enter API key'} onChange={(event) => updateDraft('openai_api_key', event.target.value)} />
        </label>
      </section>

      <section className="admin-chatbot-settings-card">
        <div>
          <span>Fallback & Cache</span>
          <h3>Cost Controls</h3>
          <p>Knowledge Version: {draft.openai_knowledge_version || 1}. Cached answers: {cacheCount}.</p>
        </div>
        <label>
          Fallback to YGT Model
          <select disabled={isSaving} value={String(draft.openai_fallback_ygt)} onChange={(event) => updateDraft('openai_fallback_ygt', Number(event.target.value))}>
            <option value="1">ON</option>
            <option value="0">OFF</option>
          </select>
        </label>
        <label>
          Cache OpenAI Answers
          <select disabled={isSaving} value={String(draft.openai_cache_enabled)} onChange={(event) => updateDraft('openai_cache_enabled', Number(event.target.value))}>
            <option value="1">ON</option>
            <option value="0">OFF</option>
          </select>
        </label>
        <label>
          Knowledge Version
          <input disabled={isSaving} type="number" min="1" value={draft.openai_knowledge_version} onChange={(event) => updateDraft('openai_knowledge_version', event.target.value)} />
        </label>
      </section>

      <footer className="admin-chatbot-settings-actions">
        <button type="button" className="admin-row-action" disabled={isSaving} onClick={onClearCache}>Clear OpenAI Cache</button>
        <button type="submit" disabled={isSaving}>
          {isSaving ? <span className="admin-save-spinner" aria-hidden="true" /> : null}
          {isSaving ? 'Saving...' : 'Save Settings'}
        </button>
      </footer>
    </form>
  )
}

function ChatbotFaqTable({ faqs, onEdit }) {
  if (!faqs.length) return <div className="admin-empty">No chatbot FAQs yet.</div>

  return (
    <div className="admin-table-wrap">
      <table className="admin-table admin-chatbot-table">
        <thead>
          <tr>
            <th>FAQ</th>
            <th>Topic</th>
            <th>Intent</th>
            <th>Priority</th>
            <th>Keywords</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          {faqs.map((faq) => (
            <tr key={faq.id}>
              <td>
                <strong>{faq.question}</strong>
                <span>{faq.answer}</span>
              </td>
              <td>{faq.topic_name || faq.topic_code || '-'}</td>
              <td>{faq.intent_name || faq.intent_code || '-'}</td>
              <td>{faq.priority}</td>
              <td>{activeKeywordCount(faq.keywords)}</td>
              <td><StatusBadge status={faq.status} /></td>
              <td><button type="button" className="admin-row-action" onClick={() => onEdit(faq)}>Edit</button></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function ChatbotTopicTable({ topics, onEdit }) {
  if (!topics.length) return <div className="admin-empty">No chatbot topics yet.</div>

  return (
    <div className="admin-table-wrap">
      <table className="admin-table admin-chatbot-table">
        <thead>
          <tr>
            <th>Topic</th>
            <th>Code</th>
            <th>Description</th>
            <th>Keywords</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          {topics.map((topic) => (
            <tr key={topic.id}>
              <td><strong>{topic.topic_name}</strong></td>
              <td>{topic.topic_code}</td>
              <td>{topic.description || '-'}</td>
              <td>{activeKeywordCount(topic.keywords)}</td>
              <td><StatusBadge status={topic.status} /></td>
              <td><button type="button" className="admin-row-action" onClick={() => onEdit(topic)}>Edit</button></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function ChatbotIntentTable({ intents, onEdit }) {
  if (!intents.length) return <div className="admin-empty">No chatbot intents yet.</div>

  return (
    <div className="admin-table-wrap">
      <table className="admin-table admin-chatbot-table">
        <thead>
          <tr>
            <th>Intent</th>
            <th>Code</th>
            <th>Description</th>
            <th>Keywords</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          {intents.map((intent) => (
            <tr key={intent.id}>
              <td><strong>{intent.intent_name}</strong></td>
              <td>{intent.intent_code}</td>
              <td>{intent.description || '-'}</td>
              <td>{activeKeywordCount(intent.keywords)}</td>
              <td><StatusBadge status={intent.status} /></td>
              <td><button type="button" className="admin-row-action" onClick={() => onEdit(intent)}>Edit</button></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function ChatbotEditorModal({ view, record, topics, intents, isSaving, onClose, onSave, onDeactivate }) {
  const [draft, setDraft] = useState(() => chatbotDraft(view, record, topics, intents))
  const title = record?.id ? `Edit ${chatbotLabel(view)}` : `Add ${chatbotLabel(view)}`

  const updateDraft = (key, value) => {
    setDraft((current) => ({ ...current, [key]: value }))
  }

  const updateKeyword = (index, key, value) => {
    setDraft((current) => ({
      ...current,
      keywords: current.keywords.map((keyword, keywordIndex) => (
        keywordIndex === index ? { ...keyword, [key]: value } : keyword
      )),
    }))
  }

  const addKeyword = () => {
    setDraft((current) => ({
      ...current,
      keywords: [...current.keywords, { keyword: '', weight: 5, language: 'en', status: 1 }],
    }))
  }

  const removeKeyword = (index) => {
    setDraft((current) => ({
      ...current,
      keywords: current.keywords
        .map((keyword, keywordIndex) => {
          if (keywordIndex !== index) return keyword
          return keyword.id ? { ...keyword, _delete: true, status: 0 } : null
        })
        .filter(Boolean),
    }))
  }

  const submit = (event) => {
    event.preventDefault()
    onSave(view, normalizeChatbotDraft(view, draft))
  }

  const visibleKeywords = draft.keywords.filter((keyword) => !keyword._delete)

  return (
    <section className="admin-modal-overlay" role="dialog" aria-modal="true" aria-label={title}>
      <form className="admin-modal admin-chatbot-modal" onSubmit={submit}>
        <header className="admin-modal-head">
          <div>
            <span>Chatbot</span>
            <h3>{title}</h3>
            <p>{record?.id ? `Record #${record.id}` : 'New record'}</p>
          </div>
          <div className="admin-modal-status">
            <button type="button" disabled={isSaving} onClick={onClose} aria-label="Close chatbot editor">x</button>
          </div>
        </header>

        <div className="admin-chatbot-form">
          {view === 'faqs' ? (
            <>
              <label>
                Topic
                <select required disabled={isSaving} value={draft.topic_id} onChange={(event) => updateDraft('topic_id', event.target.value)}>
                  <option value="">Select topic</option>
                  {topics.map((topic) => <option key={topic.id} value={topic.id}>{topic.topic_name}</option>)}
                </select>
              </label>
              <label>
                Intent
                <select required disabled={isSaving} value={draft.intent_id} onChange={(event) => updateDraft('intent_id', event.target.value)}>
                  <option value="">Select intent</option>
                  {intents.map((intent) => <option key={intent.id} value={intent.id}>{intent.intent_name}</option>)}
                </select>
              </label>
              <label className="is-wide">
                Question
                <input required disabled={isSaving} value={draft.question} maxLength="500" onChange={(event) => updateDraft('question', event.target.value)} />
              </label>
              <label className="is-wide">
                Answer
                <textarea required disabled={isSaving} value={draft.answer} rows="5" onChange={(event) => updateDraft('answer', event.target.value)} />
              </label>
              <label>
                Priority
                <input required disabled={isSaving} type="number" min="0" value={draft.priority} onChange={(event) => updateDraft('priority', event.target.value)} />
              </label>
            </>
          ) : (
            <>
              <label>
                {view === 'topics' ? 'Topic Code' : 'Intent Code'}
                <input required disabled={isSaving} value={view === 'topics' ? draft.topic_code : draft.intent_code} maxLength="100" onChange={(event) => updateDraft(view === 'topics' ? 'topic_code' : 'intent_code', event.target.value)} />
              </label>
              <label>
                {view === 'topics' ? 'Topic Name' : 'Intent Name'}
                <input required disabled={isSaving} value={view === 'topics' ? draft.topic_name : draft.intent_name} maxLength="150" onChange={(event) => updateDraft(view === 'topics' ? 'topic_name' : 'intent_name', event.target.value)} />
              </label>
              <label className="is-wide">
                Description
                <textarea disabled={isSaving} value={draft.description} rows="4" onChange={(event) => updateDraft('description', event.target.value)} />
              </label>
            </>
          )}

          {view === 'faqs' ? (
            <label>
              Language
              <input required disabled={isSaving} value={draft.language} maxLength="10" onChange={(event) => updateDraft('language', event.target.value)} />
            </label>
          ) : null}
          <label>
            Status
            <select required disabled={isSaving} value={String(draft.status)} onChange={(event) => updateDraft('status', Number(event.target.value))}>
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </label>

          <section className="admin-chatbot-keywords is-wide">
            <div className="admin-chatbot-keywords-head">
              <h4>Keywords</h4>
              <button type="button" disabled={isSaving} onClick={addKeyword}>Add Keyword</button>
            </div>
            {!visibleKeywords.length ? <div className="admin-empty is-compact">No keywords added.</div> : null}
            {visibleKeywords.map((keyword, visibleIndex) => {
              const index = draft.keywords.findIndex((item) => item === keyword)

              return (
                <div className="admin-chatbot-keyword-row" key={keyword.id || `new-${visibleIndex}`}>
                  <label>
                    Keyword
                    <input required disabled={isSaving} value={keyword.keyword} maxLength="255" onChange={(event) => updateKeyword(index, 'keyword', event.target.value)} />
                  </label>
                  <label>
                    Weight
                    <input required disabled={isSaving} type="number" min="0" value={keyword.weight} onChange={(event) => updateKeyword(index, 'weight', event.target.value)} />
                  </label>
                  <label>
                    Language
                    <input required disabled={isSaving} value={keyword.language || 'en'} maxLength="10" onChange={(event) => updateKeyword(index, 'language', event.target.value)} />
                  </label>
                  <label>
                    Status
                    <select disabled={isSaving} value={String(keyword.status ?? 1)} onChange={(event) => updateKeyword(index, 'status', Number(event.target.value))}>
                      <option value="1">Active</option>
                      <option value="0">Inactive</option>
                    </select>
                  </label>
                  <button type="button" disabled={isSaving} onClick={() => removeKeyword(index)}>Remove</button>
                </div>
              )
            })}
          </section>
        </div>

        <footer className="admin-modal-actions">
          {record?.id ? <button type="button" disabled={isSaving || Number(draft.status) === 0} onClick={() => onDeactivate(view, record)}>Deactivate</button> : null}
          <button type="button" disabled={isSaving} onClick={onClose}>Cancel</button>
          <button type="submit" disabled={isSaving}>
            {isSaving ? <span className="admin-save-spinner" aria-hidden="true" /> : null}
            {isSaving ? 'Saving...' : 'Save'}
          </button>
        </footer>
      </form>
    </section>
  )
}

function StatusBadge({ status }) {
  return <span className={`admin-badge ${Number(status) === 1 ? 'is-green' : 'is-muted'}`}>{Number(status) === 1 ? 'Active' : 'Inactive'}</span>
}

function activeKeywordCount(keywords = []) {
  return keywords.filter((keyword) => Number(keyword.status) === 1).length
}

function chatbotLabel(view) {
  if (view === 'topics') return 'Topic'
  if (view === 'intents') return 'Intent'

  return 'FAQ'
}

function chatbotDraft(view, record, topics, intents) {
  const base = {
    id: record?.id,
    language: record?.language || 'en',
    status: record?.status ?? 1,
    keywords: (record?.keywords || []).map((keyword) => ({ ...keyword })),
  }

  if (view === 'topics') {
    return {
      ...base,
      topic_code: record?.topic_code || '',
      topic_name: record?.topic_name || '',
      description: record?.description || '',
    }
  }

  if (view === 'intents') {
    return {
      ...base,
      intent_code: record?.intent_code || '',
      intent_name: record?.intent_name || '',
      description: record?.description || '',
    }
  }

  return {
    ...base,
    topic_id: record?.topic_id || topics.find((topic) => Number(topic.status) === 1)?.id || '',
    intent_id: record?.intent_id || intents.find((intent) => Number(intent.status) === 1)?.id || '',
    question: record?.question || '',
    answer: record?.answer || '',
    priority: record?.priority ?? 0,
  }
}

function chatbotSettingsDraft(settings = {}) {
  return {
    chatbot_model: settings?.chatbot_model || 'ygt',
    openai_model: settings?.openai_model || '',
    openai_api_key: '',
    openai_fallback_ygt: Number(settings?.openai_fallback_ygt ?? 1),
    openai_cache_enabled: Number(settings?.openai_cache_enabled ?? 1),
    openai_knowledge_version: Number(settings?.openai_knowledge_version ?? 1),
  }
}

function paymentSettingDraft(setting = {}) {
  return {
    service_type: setting?.service_type || '',
    payment_gateway_enabled: Boolean(setting?.payment_gateway_enabled),
    gpay_qr_enabled: Boolean(setting?.gpay_qr_enabled),
    qr_image: null,
  }
}

function normalizeChatbotDraft(view, draft) {
  const keywords = draft.keywords.map((keyword) => ({
    ...keyword,
    keyword: keyword.keyword?.trim() || '',
    weight: Number(keyword.weight || 0),
    language: keyword.language?.trim() || 'en',
    status: Number(keyword.status ?? 1),
    _delete: Boolean(keyword._delete),
  }))

  if (view === 'topics') {
    return {
      id: draft.id,
      topic_code: draft.topic_code.trim(),
      topic_name: draft.topic_name.trim(),
      description: draft.description?.trim() || '',
      status: Number(draft.status),
      keywords,
    }
  }

  if (view === 'intents') {
    return {
      id: draft.id,
      intent_code: draft.intent_code.trim(),
      intent_name: draft.intent_name.trim(),
      description: draft.description?.trim() || '',
      status: Number(draft.status),
      keywords,
    }
  }

  return {
    id: draft.id,
    topic_id: Number(draft.topic_id),
    intent_id: Number(draft.intent_id),
    question: draft.question.trim(),
    answer: draft.answer.trim(),
    priority: Number(draft.priority || 0),
    language: draft.language?.trim() || 'en',
    status: Number(draft.status),
    keywords,
  }
}

function AstrologyPanel({ state, onRetry }) {
  const viewerUrl = state.payload?.viewer_url
  const predictionRequestId = state.payload?.prediction_request_id

  return (
    <aside className="admin-astrology-panel" aria-label="Astrology Data">
      <div className="admin-astrology-head">
        <h4>Astrology Data</h4>
        <p>{predictionRequestId ? `Prediction Request #${predictionRequestId}` : 'Reference data for preparing the remedy'}</p>
      </div>
      {state.status === 'loading' ? <div className="admin-astro-loading">Loading astrology data...</div> : null}
      {state.status === 'empty' ? <div className="admin-astro-empty">Astrology data is not available for this request.</div> : null}
      {state.status === 'error' ? (
        <div className="admin-astro-empty">
          Unable to load astrology data.
          <button type="button" onClick={onRetry}>Retry</button>
        </div>
      ) : null}
      {state.status === 'ready' && viewerUrl ? (
        <iframe
          className="admin-astrology-frame"
          title={`Astrology data for prediction request ${predictionRequestId}`}
          src={viewerUrl}
        />
      ) : null}
    </aside>
  )
}

function remedyToEdit(remedy) {
  const normalizedStatus = ['RECEIVED', 'REVIEWING', 'PAYMENT_PENDING', 'COMPLETED'].includes(remedy.status)
    ? remedy.status
    : remedy.status === 'REMEDY_READY' || remedy.status === 'PAID'
      ? 'COMPLETED'
      : 'REVIEWING'

  return {
    status: normalizedStatus,
    payment_enabled: Boolean(remedy.payment_enabled),
    amount_inr: amountToRupees(remedy.amount_minor),
    currency: remedy.currency || 'INR',
    payment_status: remedy.payment_status || 'NOT_REQUIRED',
    remedy_type: remedy.remedy_type || '',
    remedy_title: remedy.remedy_title || '',
    remedy_url: remedy.remedy_url || '',
    remedy_file: null,
    admin_note: remedy.admin_note || '',
  }
}

export default AdminPage
