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
  fetchAdminFeedback,
  fetchAdminTempleRemedies,
  fetchAdminUsers,
  logoutAdmin,
  updateAdminTempleRemedy,
  updateAdminAppointment,
  confirmAdminAppointmentSlot,
  fetchAdminTempleRemedyFile,
  fetchAdminTempleRemedyAstrology,
  fetchAdminTempleRemedyManualPaymentFile,
} from '../services/adminService'
import { getAdminUser, removeAdminToken, removeAdminUser } from '../utils/auth'
import './AdminPage.css'

const tabs = [
  { id: 'users', label: 'Users' },
  { id: 'past', label: 'Past Requests' },
  { id: 'future', label: 'Future Requests' },
  { id: 'remedies', label: 'Temple Remedies' },
  { id: 'appointments', label: 'Appointments' },
  { id: 'feedback', label: 'Feedback' },
]

const statusOptions = ['PAYMENT_PENDING', 'COMPLETED']
const appointmentFallbackGroups = ['payment_received', 'upcoming', 'completed', 'cancelled_failed']
const appointmentTimezone = 'Asia/Kolkata'

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

    if (tabId === 'remedies' && remedyStatus === 'idle') {
      loadTempleRemedies(remedyGroup)
    }

    if (tabId === 'feedback' && feedbackItems.length === 0) {
      loadFeedback()
    }
  }

  const activeCount = useMemo(() => {
    if (activeTab === 'users') return users.length
    if (activeTab === 'past') return pastRequests.length
    if (activeTab === 'future') return futureRequests.length
    if (activeTab === 'feedback') return feedbackCategory === 'all' ? feedbackCounts.all : feedbackCounts[feedbackCategory] ?? 0
    if (activeTab === 'appointments') return appointmentCounts[appointmentGroup] ?? appointments.length
    return remedyCounts[remedyGroup] ?? remedies.length
  }, [activeTab, appointmentCounts, appointmentGroup, appointments.length, feedbackCategory, feedbackCounts, futureRequests.length, pastRequests.length, remedies.length, remedyCounts, remedyGroup, users.length])
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
          />
        ) : null}
      </section>
    </main>
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

function PredictionDetailView({ type, detail, status, onClose }) {
  const header = detail?.request || {}
  const result = detail?.result || {}
  const identity = detail?.astrology?.identity || header.identity || {}
  const requestId = header.id || result.prediction_request_id || '-'
  const createdAt = formatDate(header.submitted_at || header.created_at || result.generated_at)
  const lastVisitedAt = formatDate(detail?.last_visited_at)
  const ratingLabel = detail?.rating?.label || 'Not Rated'
  const statusLabel = labelFromKey(result.status || header.status)
  const periodLabelText = detail?.period?.label || 'Prediction period unavailable'
  const viewerUrl = detail?.viewer_url

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
                {viewerUrl ? (
                  <iframe
                    src={viewerUrl}
                    className="prediction-response-frame"
                    title="Astrology Data"
                  />
                ) : (
                  <div className="admin-empty is-compact">Astrology data is unavailable for this request.</div>
                )}
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
  if (remedy.payment_method === 'qr_online') return 'QR Reference'

  return 'Gateway Pending'
}

function completionLabel(remedy) {
  if (remedy.payment_status === 'PAID' || remedy.completion_method === 'payment') return 'Paid'
  if (remedy.completion_method === 'manual') return 'Manual'

  return 'Manual'
}

function RemedyList({ remedies, group, counts, isLoading, isError, edits, selectedRemedyId, onGroupChange, onSelect, onClose, onChange, onSave, savingRemedyId }) {
  const selectedRemedy = remedies.find((remedy) => remedy.id === selectedRemedyId) || null
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
              <th>{group === 'payment_pending' ? 'Payment Reference' : 'Remedy'}</th>
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
                  <span className={`admin-badge ${remedy.remedy_file_path || remedy.remedy_url ? 'is-green' : 'is-muted'}`}>
                    {remedyStateLabel(remedy, group)}
                  </span>
                  {group === 'payment_pending' && remedy.manual_payment?.reference ? <small>{remedy.manual_payment.reference}</small> : null}
                  {group !== 'payment_pending' && remedy.remedy_file_name ? <small>{fileKind(remedy.remedy_file_name, remedy.remedy_mime_type)}</small> : null}
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

  const openPaymentProof = async () => {
    if (!remedy.manual_payment?.file_url) return

    try {
      const blob = await fetchAdminTempleRemedyManualPaymentFile(remedy.id)
      const url = URL.createObjectURL(blob)
      window.open(url, '_blank', 'noopener,noreferrer')
      window.setTimeout(() => URL.revokeObjectURL(url), 30000)
    } catch {
      setFileError('Unable to load the payment proof.')
    }
  }

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
                <strong>{isQrPayment ? 'QR Code' : 'Razorpay Gateway'}</strong>
              </div>
              <div>
                <span>Payment Status</span>
                <strong>{remedy.payment_status === 'PAID' ? 'Payment Received' : 'Payment Pending'}</strong>
              </div>
              <div>
                <span>Amount</span>
                <strong>{remedyAmountLabel(remedy, 'payment_received')}</strong>
              </div>
              {isQrPayment && remedy.manual_payment?.reference ? (
                <div>
                  <span>Reference</span>
                  <strong>{remedy.manual_payment.reference}</strong>
                </div>
              ) : null}
              {isQrPayment && remedy.manual_payment?.file_url ? (
                <button type="button" className="admin-inline-link" onClick={openPaymentProof}>View payment proof</button>
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
    </section>
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
