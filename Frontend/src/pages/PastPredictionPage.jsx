import { useEffect, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import ActionModal from '../components/common/ActionModal'
import PastPredictionProcessing, { predictionStageSequence } from '../components/common/PastPredictionProcessing'
import PredictionPlayer from '../components/common/PredictionPlayer'
import PredictionRegistrationForm from '../components/common/PredictionRegistrationForm'
import { logout as logoutUser, sendOtp, validateOtp, verifyOtp } from '../services/authService'
import { submitPastPrediction } from '../services/astrologyApi'
import {
  buildPredictionPlaylist,
  fetchPredictionSessionStatus,
  fetchPredictionResult,
  fetchPastPredictionResult,
  fetchStoredPredictionResult,
} from '../services/predictionResultService'
import {
  clearPredictionSession,
  loadPredictionSession,
  savePredictionSession,
} from '../services/predictionSessionService'
import {
  createFuturePredictionOrder,
  fetchFutureAccess,
  loadRazorpayScript,
  verifyRazorpayPayment,
} from '../services/paymentService'
import { getUser, isLoggedIn, removeToken, removeUser, saveToken, saveUser } from '../utils/auth'
import templeImage from '../assets/temple-transparent.png'
import './PastPredictionPage.css'

const emptyOtp = ['', '', '', '', '', '']
const resendSeconds = 180
const otpSentDelay = 520
const otpVerifiedDelay = 720
const pendingPredictionActionKey = 'ygt-pending-prediction-action'
const startupStates = {
  LOADING_SESSION: 'LOADING_SESSION',
  RETURNING_USER_PROMPT: 'RETURNING_USER_PROMPT',
  NEW_PREDICTION_FORM: 'NEW_PREDICTION_FORM',
  LOADING_EXISTING_RESULT: 'LOADING_EXISTING_RESULT',
  READY: 'READY',
  ERROR: 'ERROR',
}

function toTitleCase(value) {
  return value
    .toLowerCase()
    .replace(/(^|\s)\S/g, (letter) => letter.toUpperCase())
}

function isValidTimezone(timezone) {
  if (!timezone || typeof timezone !== 'string') return false

  try {
    Intl.DateTimeFormat('en-US', { timeZone: timezone }).format(new Date())
    return true
  } catch {
    return false
  }
}

function resolveBirthTimezone(place) {
  if (isValidTimezone(place?.timezone)) return place.timezone

  const countryCode = String(place?.country_code || '').toUpperCase()
  const country = String(place?.country || '').trim().toLowerCase()
  const numericOffset = Number(place?.timezone || place?.time_zone || place?.tzone)

  if (countryCode === 'IN' || country === 'india' || numericOffset === 5.5) {
    return 'Asia/Kolkata'
  }

  const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone
  return isValidTimezone(browserTimezone) ? browserTimezone : ''
}

function normalizeValidationErrors(error) {
  const errors = error?.errors || {}

  return Object.entries(errors).reduce((normalized, [field, messages]) => {
    normalized[field] = Array.isArray(messages) ? messages[0] : String(messages)
    return normalized
  }, {})
}

function getErrorMessage(error, fallback) {
  const responseData = error?.response?.data || error?.responseBody || {}
  const messagesByCode = {
    ALREADY_REGISTERED: 'This email is already registered.',
    EMAIL_ALREADY_REGISTERED: 'This email is already registered. Please log in to continue.',
    INVALID_OTP: 'The OTP you entered is invalid.',
    OTP_EXPIRED: 'The OTP has expired. Please request a new OTP.',
    OTP_ALREADY_USED: 'This OTP has already been used. Please request a new OTP.',
  }

  return messagesByCode[responseData.code] || responseData.message || error?.message || fallback
}

function isValidEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
}

function loadPendingPredictionAction(expectedIntent) {
  try {
    const pendingAction = JSON.parse(window.sessionStorage.getItem(pendingPredictionActionKey) || 'null')
    const expectedAction = expectedIntent === 'future' ? 'GET_FUTURE_PREDICTION' : 'GET_PAST_PREDICTION'

    if (pendingAction?.action !== expectedAction || !pendingAction?.birth_details) {
      return null
    }

    return pendingAction
  } catch {
    return null
  }
}

function clearPendingPredictionAction() {
  try {
    window.sessionStorage.removeItem(pendingPredictionActionKey)
  } catch {
    // Ignore storage cleanup failures.
  }
}

function PastPredictionPage({ forceModal = false, onClose, predictionIntent = 'past' }) {
  const navigate = useNavigate()
  const location = useLocation()
  const otpRefs = useRef([])
  const emailInputRef = useRef(null)
  const predictionAbortRef = useRef(null)
  const latestPredictionPayloadRef = useRef(null)
  const submissionInFlightRef = useRef(false)
  const flowTimerRef = useRef(null)
  const predictionTimersRef = useRef([])
  const isModal = forceModal || Boolean(location.state?.backgroundLocation)
  const normalizedPredictionIntent = predictionIntent === 'future' ? 'future' : 'past'
  const isFutureIntent = normalizedPredictionIntent === 'future'
  const pendingPredictionActionRef = useRef(loadPendingPredictionAction(normalizedPredictionIntent))
  const pendingBirthDetails = pendingPredictionActionRef.current?.birth_details || {}
  const [authFlowStage, setAuthFlowStage] = useState('email')
  const [fullName, setFullName] = useState(pendingBirthDetails.full_name || '')
  const [dateOfBirth, setDateOfBirth] = useState(pendingBirthDetails.date_of_birth || '')
  const [timeOfBirth, setTimeOfBirth] = useState(pendingBirthDetails.time_of_birth || '')
  const [placeOfBirth, setPlaceOfBirth] = useState(pendingBirthDetails.birth_place || pendingBirthDetails.place_of_birth || '')
  const [placeOfBirthDetails, setPlaceOfBirthDetails] = useState(pendingBirthDetails.place_of_birth_details || (pendingBirthDetails.birth_place && pendingBirthDetails.latitude && pendingBirthDetails.longitude ? {
    place_name: pendingBirthDetails.birth_place,
    latitude: pendingBirthDetails.latitude,
    longitude: pendingBirthDetails.longitude,
    timezone: pendingBirthDetails.timezone,
    country_code: pendingBirthDetails.country_code,
    country: pendingBirthDetails.country,
  } : null))
  const [marriageStatus, setMarriageStatus] = useState(pendingBirthDetails.marriage_status || '')
  const [email, setEmail] = useState(pendingBirthDetails.email || getUser()?.email || '')
  const [otp, setOtp] = useState(emptyOtp)
  const [registrationPassword, setRegistrationPassword] = useState('')
  const [registrationPasswordConfirmation, setRegistrationPasswordConfirmation] = useState('')
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const [processingError, setProcessingError] = useState('')
  const [predictionStage, setPredictionStage] = useState('preparing')
  const [predictionResult, setPredictionResult] = useState(null)
  const [predictionPlaylist, setPredictionPlaylist] = useState([])
  const [predictionRequestAccess, setPredictionRequestAccess] = useState(null)
  const [preparationStatus, setPreparationStatus] = useState('OTP_VERIFIED')
  const [isPlayerOpen, setIsPlayerOpen] = useState(false)
  const [postReadingStage, setPostReadingStage] = useState('idle')
  const [futureAccess, setFutureAccess] = useState(null)
  const [checkoutError, setCheckoutError] = useState('')
  const [checkoutStatus, setCheckoutStatus] = useState('idle')
  const [futureResult, setFutureResult] = useState(null)
  const [futurePlaylist, setFuturePlaylist] = useState([])
  const [pastFeedbackSubmitted, setPastFeedbackSubmitted] = useState(false)
  const [startupState, setStartupState] = useState(startupStates.LOADING_SESSION)
  const [returningSession, setReturningSession] = useState(null)
  const [sessionMessage, setSessionMessage] = useState('')
  const [isSubmittingPrediction, setIsSubmittingPrediction] = useState(false)
  const [isSendingOtp, setIsSendingOtp] = useState(false)
  const [isVerifyingOtp, setIsVerifyingOtp] = useState(false)
  const [isLoggingOut, setIsLoggingOut] = useState(false)
  const [resendCountdown, setResendCountdown] = useState(0)
  const [isClaimedModalOpen, setIsClaimedModalOpen] = useState(false)
  const [otpShakeCount, setOtpShakeCount] = useState(0)
  const otpValue = otp.join('')
  const isAuthTransitioning = authFlowStage === 'otpVerified'
  const isAuthLoading = isSendingOtp || isVerifyingOtp || isAuthTransitioning
  const isHydratingSession = startupState === startupStates.LOADING_SESSION
  const isReturningUserPrompt = startupState === startupStates.RETURNING_USER_PROMPT
  const isLoadingExistingResult = startupState === startupStates.LOADING_EXISTING_RESULT
  const isEmailStage = startupState === startupStates.NEW_PREDICTION_FORM && (authFlowStage === 'email' || authFlowStage === 'sendingOtp' || authFlowStage === 'error')
  const isOtpStage = authFlowStage === 'otpSent' || authFlowStage === 'passwordSetup' || authFlowStage === 'verifyingOtp' || authFlowStage === 'otpVerified'
  const isPreparingPrediction = authFlowStage === 'preparingPrediction'
  const isPredictionReady = authFlowStage === 'predictionReady' && !isFutureIntent
  const isExistingEmailError = error === 'This email is already registered. Please log in to continue.'
  const gender = ''

  useEffect(() => () => {
    predictionAbortRef.current?.abort()
    if (flowTimerRef.current) {
      window.clearTimeout(flowTimerRef.current)
    }
    clearPredictionTimers()
  }, [])

  useEffect(() => {
    const lockScroll = isPreparingPrediction || isPredictionReady || isPlayerOpen || postReadingStage !== 'idle'
    if (!lockScroll) return undefined

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.body.style.overflow = previousOverflow
    }
  }, [isPlayerOpen, isPreparingPrediction, postReadingStage])

  useEffect(() => {
    const storedPrediction = loadPredictionSession()

    if (!storedPrediction?.predictionRequestId || !storedPrediction?.requestUuid) {
      setStartupState(startupStates.NEW_PREDICTION_FORM)
      return undefined
    }

    const controller = new AbortController()

    fetchPredictionSessionStatus({
      predictionRequestId: storedPrediction.predictionRequestId,
      requestUuid: storedPrediction.requestUuid,
      signal: controller.signal,
    })
      .then((status) => {
        if (!status?.valid) {
          resetPredictionFlowState({ clearIdentity: true })
          setSessionMessage('Your previous prediction session has expired. Please start a new prediction.')
          return
        }

        const session = {
          ...storedPrediction,
          ...status,
          predictionRequestId: status.prediction_request_id,
          requestUuid: storedPrediction.requestUuid,
          resultReady: Boolean(status.result_ready),
          otpVerified: Boolean(status.otp_verified),
        }

        setPredictionRequestAccess({
          predictionRequestId: session.predictionRequestId,
          requestUuid: session.requestUuid,
        })
        setFullName(status.name || storedPrediction.fullName || '')
        setEmail('')
        setPreparationStatus(status.result_ready ? 'READY' : 'OTP_VERIFIED')

        if (isFutureIntent) {
          setStartupState(startupStates.READY)
          loadFutureOffer({
            predictionRequestId: session.predictionRequestId,
            requestUuid: session.requestUuid,
          })
          return
        }

        setReturningSession(session)
        setStartupState(startupStates.RETURNING_USER_PROMPT)
      })
      .catch((sessionError) => {
        if (sessionError.name === 'AbortError' || sessionError.code === 'ERR_CANCELED') return

        resetPredictionFlowState({ clearIdentity: true })
        setSessionMessage('We could not verify your previous prediction. Please start again.')
      })

    return () => controller.abort()
  }, [isFutureIntent])

  useEffect(() => {
    if (!isOtpStage || resendCountdown <= 0) return undefined

    const timer = window.setInterval(() => {
      setResendCountdown((seconds) => Math.max(seconds - 1, 0))
    }, 1000)

    return () => window.clearInterval(timer)
  }, [isOtpStage, resendCountdown])

  const handleClose = () => {
    if (onClose) {
      onClose()
      return
    }

    if (isModal) {
      navigate(-1)
      return
    }

    navigate('/home')
  }

  const savePredictionRequest = (requestUuid, predictionRequestId, status = 'prepared') => {
    savePredictionSession({
      fullName,
      gender,
      dateOfBirth,
      timeOfBirth,
      placeOfBirth,
      placeOfBirthDetails,
      marriageStatus,
      email,
      requestUuid,
      predictionRequestId,
      predictionType: normalizedPredictionIntent,
      otpVerified: status === 'verified' || status === 'ready',
      resultReady: status === 'ready',
      status,
    })
  }

  const resetPredictionFlowState = ({ clearIdentity = false } = {}) => {
    predictionAbortRef.current?.abort()
    clearPredictionTimers()
    clearPredictionSession()
    setPredictionRequestAccess(null)
    setPredictionResult(null)
    setPredictionPlaylist([])
    setPredictionStage('preparing')
    setPreparationStatus('OTP_VERIFIED')
    setIsPlayerOpen(false)
    setPostReadingStage('idle')
    setFutureAccess(null)
    setCheckoutError('')
    setCheckoutStatus('idle')
    setFutureResult(null)
    setFuturePlaylist([])
    setOtp(emptyOtp)
    setRegistrationPassword('')
    setRegistrationPasswordConfirmation('')
    setResendCountdown(0)
    setError('')
    setMessage('')
    setFieldErrors({})
    setProcessingError('')
    setReturningSession(null)
    setAuthFlowStage('email')
    setStartupState(startupStates.NEW_PREDICTION_FORM)

    if (clearIdentity) {
      setFullName('')
      setEmail('')
      setDateOfBirth('')
      setTimeOfBirth('')
      setPlaceOfBirth('')
      setPlaceOfBirthDetails(null)
      setMarriageStatus('')
    } else {
      setEmail('')
    }
  }

  const clearPredictionTimers = () => {
    predictionTimersRef.current.forEach((timerId) => window.clearTimeout(timerId))
    predictionTimersRef.current = []
  }

  const waitForPredictionStep = (duration) => new Promise((resolve) => {
    const timerId = window.setTimeout(resolve, duration)
    predictionTimersRef.current.push(timerId)
  })

  const runSimulatedPredictionStages = async () => {
    clearPredictionTimers()
    setPredictionStage('preparing')

    for (const stage of predictionStageSequence) {
      setPredictionStage(stage)
      await waitForPredictionStep(520)
    }
  }

  const preparePredictionResult = async ({ predictionRequestId, requestUuid }) => {
    const access = { predictionRequestId, requestUuid }
    setPredictionRequestAccess(access)
    setPredictionResult(null)
    setPredictionPlaylist([])
    setPreparationStatus('FETCHING_PREDICTION')
    setProcessingError('')
    setPredictionStage('preparing')
    setAuthFlowStage('preparingPrediction')

    predictionAbortRef.current?.abort()
    const controller = new AbortController()
    predictionAbortRef.current = controller

    try {
      const resultRequest = fetchPastPredictionResult({
        ...access,
        signal: controller.signal,
      })

      const [result] = await Promise.all([
        resultRequest,
        runSimulatedPredictionStages(),
      ])

      const playlist = buildPredictionPlaylist(result)
      setPreparationStatus('PREPARING_PLAYLIST')

      if (!playlist.length) {
        throw new Error('No playable prediction videos were returned.')
      }

      setPredictionResult(result)
      setPredictionPlaylist(playlist)
      savePredictionRequest(requestUuid, predictionRequestId, 'ready')
      setPreparationStatus('READY')
      setPredictionStage('ready')
      setAuthFlowStage('predictionReady')
      setStartupState(startupStates.READY)
    } catch (prepareError) {
      clearPredictionTimers()

      if (prepareError.name === 'AbortError' || prepareError.code === 'ERR_CANCELED') return

      setProcessingError(getErrorMessage(
        prepareError,
        'We could not prepare your prediction right now. Your verification is complete, so you can safely try again.',
      ))
      setPreparationStatus('ERROR')
      setPredictionStage('error')
      setAuthFlowStage('preparingPrediction')
    } finally {
      if (predictionAbortRef.current === controller) {
        predictionAbortRef.current = null
      }
    }
  }

  const handleBirthSubmit = async (event) => {
    event.preventDefault()

    if (isSubmittingPrediction || isAuthLoading || submissionInFlightRef.current) return

    const trimmedEmail = email.trim()

    if (!fullName.trim() || !trimmedEmail || !dateOfBirth || !timeOfBirth || !marriageStatus || !placeOfBirth.trim()) {
      setError('Please complete all details.')
      return
    }

    if (!isValidEmail(trimmedEmail)) {
      setError('Please enter a valid email address.')
      emailInputRef.current?.focus()
      return
    }

    if (!hasSelectedBirthplace()) {
      setError('Please select your birthplace from the suggestions.')
      return
    }

    setError('')
    setMessage('')
    setFieldErrors({})

    const payload = buildPredictionPayload()
    if (!payload) return

    latestPredictionPayloadRef.current = payload

    if (isLoggedIn()) {
      await submitPredictionPayload(payload)
      return
    }

    await sendPastPredictionOtp()
  }

  const hasSelectedBirthplace = () => {
    const selectedName = placeOfBirthDetails?.place_name?.trim().toLowerCase()
    const typedName = placeOfBirth.trim().toLowerCase()

    return Boolean(
      selectedName
      && typedName
      && selectedName === typedName
      && Number.isFinite(Number(placeOfBirthDetails.latitude))
      && Number.isFinite(Number(placeOfBirthDetails.longitude))
    )
  }

  const handlePlaceChange = (nextValue) => {
    setPlaceOfBirth(nextValue)
    setFieldErrors((current) => ({ ...current, place_of_birth: undefined, birth_place: undefined }))

    const selectedName = placeOfBirthDetails?.place_name?.trim().toLowerCase()
    if (selectedName && selectedName !== nextValue.trim().toLowerCase()) {
      setPlaceOfBirthDetails(null)
    }
  }

  const handlePlaceSelect = (place) => {
    setPlaceOfBirthDetails(place)
    setFieldErrors((current) => ({
      ...current,
      place_of_birth: undefined,
      birth_place: undefined,
      latitude: undefined,
      longitude: undefined,
    }))

  }

  const handleOtpSubmit = async (event) => {
    event.preventDefault()

    if (isVerifyingOtp || isSubmittingPrediction || submissionInFlightRef.current) return

    if (otpValue.length !== 6) {
      setError('Please enter the 6 digit OTP.')
      return
    }

    if (authFlowStage === 'otpSent') {
      setIsVerifyingOtp(true)
      setAuthFlowStage('verifyingOtp')
      setError('')
      setMessage('')

      try {
        await validateOtp({
          email: email.trim(),
          otp: otpValue,
          purpose: 'signup',
        })
        setMessage('OTP verified. Create your password to continue.')
        setAuthFlowStage('passwordSetup')
      } catch (validateError) {
        setAuthFlowStage('otpSent')
        setOtp(emptyOtp)
        setOtpShakeCount((count) => count + 1)
        setError(getErrorMessage(validateError, 'Invalid or expired OTP. Please try again.'))
        window.setTimeout(() => otpRefs.current[0]?.focus(), 80)
      } finally {
        setIsVerifyingOtp(false)
      }

      return
    }

    if (registrationPassword.length < 8) {
      setError('Password must be at least 8 characters.')
      return
    }

    if (registrationPassword !== registrationPasswordConfirmation) {
      setError('Passwords do not match.')
      return
    }

    setIsVerifyingOtp(true)
    setAuthFlowStage('verifyingOtp')
    setError('')
    setMessage('')
    setFieldErrors({})

    try {
      const response = await verifyOtp({
        email: email.trim(),
        otp: otpValue,
        purpose: 'signup',
        password: registrationPassword,
        password_confirmation: registrationPasswordConfirmation,
      })

      if (response?.code !== 'REGISTRATION_COMPLETED' || !response?.prediction_request_id) {
        setError(response?.message || 'Registration response is missing prediction request details.')
        setAuthFlowStage('otpSent')
        return
      }

      setAuthFlowStage('otpVerified')
      setMessage(response?.message || 'Registration completed successfully.')
      if (response?.token && response?.user) {
        saveToken(response.token)
        saveUser(response.user)
      }

      flowTimerRef.current = window.setTimeout(() => {
        if (response.request_uuid) {
          savePredictionRequest(response.request_uuid, response.prediction_request_id, 'verified')
        }

        continueAfterAstrologyReady({
          predictionRequestId: response.prediction_request_id,
          requestUuid: response.request_uuid,
        })
      }, otpVerifiedDelay)
    } catch (verifyError) {
      const responseCode = verifyError?.response?.data?.code
      setAuthFlowStage(responseCode === 'ALREADY_REGISTERED' || responseCode === 'EMAIL_ALREADY_REGISTERED' ? 'email' : 'otpSent')
      setOtp(emptyOtp)
      setRegistrationPassword('')
      setRegistrationPasswordConfirmation('')
      setOtpShakeCount((count) => count + 1)
      setError(getErrorMessage(verifyError, 'Invalid or expired OTP. Please try again.'))
      window.setTimeout(() => otpRefs.current[0]?.focus(), 80)
    } finally {
      setIsVerifyingOtp(false)
    }
  }

  const sendPastPredictionOtp = async () => {
    if (isSendingOtp || resendCountdown > 0) return

    setIsSendingOtp(true)
    setAuthFlowStage('sendingOtp')
    setError('')
    setMessage('')
    setOtp(emptyOtp)

    try {
      const response = await sendOtp({
        ...latestPredictionPayloadRef.current,
        email: email.trim(),
        purpose: 'signup',
      })

      setMessage(response?.message || 'OTP sent successfully')
      setResendCountdown(resendSeconds)
      setAuthFlowStage('otpSent')
      flowTimerRef.current = window.setTimeout(() => otpRefs.current[0]?.focus(), otpSentDelay)
    } catch (sendError) {
      setAuthFlowStage('email')
      setError(getErrorMessage(sendError, 'Unable to send OTP. Please try again.'))
    } finally {
      setIsSendingOtp(false)
    }
  }

  const handleExistingEmailLogin = () => {
    const payload = latestPredictionPayloadRef.current || buildPredictionPayload()
    if (payload) {
      try {
        window.sessionStorage.setItem(pendingPredictionActionKey, JSON.stringify({
          action: isFutureIntent ? 'GET_FUTURE_PREDICTION' : 'GET_PAST_PREDICTION',
          source: 'home',
          requested_at: new Date().toISOString(),
          birth_details: payload,
        }))
      } catch {
        // The form still keeps the entered values in memory if storage is unavailable.
      }
    }

    navigate('/login', {
      state: {
        redirectTo: isFutureIntent ? '/future' : '/past-prediction',
      },
    })
  }

  const handleResendOtp = () => {
    if (resendCountdown > 0 || isAuthLoading) return

    sendPastPredictionOtp()
  }

  const handleChangeEmail = () => {
    if (isAuthLoading || isSubmittingPrediction) return

    setAuthFlowStage('email')
    setError('')
    setMessage('')
    setResendCountdown(0)
    window.setTimeout(() => emailInputRef.current?.focus(), 80)
  }

  const handleViewPrediction = () => {
    setIsClaimedModalOpen(false)
    navigate('/account')
  }

  const handleUseAnotherEmail = async () => {
    if (isLoggingOut) return

    setIsLoggingOut(true)

    try {
      if (isLoggedIn()) {
        await logoutUser()
      }
    } catch (logoutError) {
      if (import.meta.env.DEV) {
        console.warn('Logout failed while switching past prediction email', logoutError)
      }
    } finally {
      removeToken()
      removeUser()
      setEmail('')
      setOtp(emptyOtp)
      setResendCountdown(0)
      setError('')
      setMessage('')
      setFieldErrors({})
      setProcessingError('')
      setPredictionStage('preparing')
      setPredictionResult(null)
      setPredictionPlaylist([])
      setPredictionRequestAccess(null)
      setPreparationStatus('OTP_VERIFIED')
      setIsPlayerOpen(false)
      setPostReadingStage('idle')
      setFutureAccess(null)
      setCheckoutStatus('idle')
      setIsClaimedModalOpen(false)
      setAuthFlowStage('email')
      setIsLoggingOut(false)
      window.setTimeout(() => emailInputRef.current?.focus(), 80)
    }
  }

  const buildPredictionPayload = () => {
    if (!fullName.trim() || !email.trim() || !dateOfBirth || !timeOfBirth || !marriageStatus) {
      setAuthFlowStage('email')
      setError('Please complete all details.')
      return null
    }

    if (!hasSelectedBirthplace()) {
      setAuthFlowStage('email')
      setError('Please select your birthplace from the suggestions.')
      return null
    }

    const timezone = resolveBirthTimezone(placeOfBirthDetails)
    if (!timezone) {
      setAuthFlowStage('email')
      setError('Please select a birthplace with a valid timezone.')
      return null
    }

    const countryCode = String(placeOfBirthDetails.country_code || '').toUpperCase()

    return {
      full_name: fullName.trim(),
      gender,
      date_of_birth: dateOfBirth,
      time_of_birth: timeOfBirth,
      birth_place: placeOfBirth.trim(),
      latitude: Number(placeOfBirthDetails.latitude),
      longitude: Number(placeOfBirthDetails.longitude),
      timezone,
      country_code: countryCode || null,
      marriage_status: marriageStatus || null,
      email: email.trim(),
    }
  }

  const submitPredictionPayload = async (payload) => {
    if (isSubmittingPrediction || submissionInFlightRef.current) return

    latestPredictionPayloadRef.current = payload
    submissionInFlightRef.current = true
    predictionAbortRef.current?.abort()
    const controller = new AbortController()
    predictionAbortRef.current = controller
    setIsSubmittingPrediction(true)
    setProcessingError('')
    setPredictionStage('preparing')
    setAuthFlowStage('preparingPrediction')

    try {
      const predictionRequest = submitPastPrediction(payload, {
        signal: controller.signal,
        timeoutMs: 120000,
      })

      const [result] = await Promise.all([
        predictionRequest,
        runSimulatedPredictionStages(),
      ])

      if (result?.request_uuid && result?.prediction_request_id) {
        savePredictionRequest(result.request_uuid, result.prediction_request_id, 'ready')
        clearPendingPredictionAction()
      }

      if (isFutureIntent && result?.request_uuid && result?.prediction_request_id) {
        setPredictionRequestAccess({
          predictionRequestId: result.prediction_request_id,
          requestUuid: result.request_uuid,
        })
        setAuthFlowStage('predictionReady')
        setStartupState(startupStates.READY)
        await loadFutureOffer({
          predictionRequestId: result.prediction_request_id,
          requestUuid: result.request_uuid,
        })
        return
      }

      setPredictionStage('ready')
      setAuthFlowStage('predictionReady')
      setStartupState(startupStates.READY)
    } catch (submitError) {
      clearPredictionTimers()

      if (submitError.name === 'AbortError' || submitError.code === 'ERR_CANCELED') return

      if (submitError.code === 'FREE_PREDICTION_ALREADY_USED') {
        setIsClaimedModalOpen(true)
        setAuthFlowStage('email')
        return
      }

      const validationErrors = normalizeValidationErrors(submitError)
      if (submitError.status === 422 && Object.keys(validationErrors).length) {
        setFieldErrors(validationErrors)
        setError('Please review the highlighted details.')
        setAuthFlowStage('email')
        return
      }

      setProcessingError('We could not prepare your prediction. Please try again.')
      setPredictionStage('error')
      setAuthFlowStage('preparingPrediction')
    } finally {
      if (predictionAbortRef.current === controller) {
        predictionAbortRef.current = null
      }
      submissionInFlightRef.current = false
      setIsSubmittingPrediction(false)
    }
  }

  const handleRetryPrediction = () => {
    if (isSubmittingPrediction || submissionInFlightRef.current) return

    if (predictionRequestAccess?.predictionRequestId && predictionRequestAccess?.requestUuid) {
      preparePredictionResult(predictionRequestAccess)
      return
    }

    const payload = latestPredictionPayloadRef.current || buildPredictionPayload()
    if (payload) {
      submitPredictionPayload(payload)
    }
  }

  const continueAfterAstrologyReady = ({ predictionRequestId, requestUuid }) => {
    if (isFutureIntent) {
      setPredictionRequestAccess({ predictionRequestId, requestUuid })
      savePredictionRequest(requestUuid, predictionRequestId, 'ready')
      clearPendingPredictionAction()
      setStartupState(startupStates.READY)
      setAuthFlowStage('predictionReady')
      loadFutureOffer({ predictionRequestId, requestUuid })
      return
    }

    preparePredictionResult({ predictionRequestId, requestUuid })
  }

  const handleBackToForm = () => {
    if (isSubmittingPrediction) return

    setProcessingError('')
    setError('')
    setPredictionStage('preparing')
    setAuthFlowStage('email')
    setStartupState(startupStates.NEW_PREDICTION_FORM)
  }

  const handleContinue = () => {
    setIsPlayerOpen(true)
  }

  const handleContinueExistingPrediction = async () => {
    if (!returningSession?.predictionRequestId || !returningSession?.requestUuid) return

    setStartupState(startupStates.LOADING_EXISTING_RESULT)
    setSessionMessage('')
    setProcessingError('')

    try {
      if (!returningSession.resultReady) {
        setStartupState(startupStates.NEW_PREDICTION_FORM)
        await preparePredictionResult({
          predictionRequestId: returningSession.predictionRequestId,
          requestUuid: returningSession.requestUuid,
        })
        return
      }

      const storedResult = await fetchStoredPredictionResult({
        predictionRequestId: returningSession.predictionRequestId,
        requestUuid: returningSession.requestUuid,
      })
      const playlist = buildPredictionPlaylist(storedResult)

      if (!storedResult || !playlist.length) {
        throw new Error('Stored prediction result is not playable.')
      }

      setPredictionRequestAccess({
        predictionRequestId: returningSession.predictionRequestId,
        requestUuid: returningSession.requestUuid,
      })
      setPredictionResult(storedResult)
      setPredictionPlaylist(playlist)
      setPreparationStatus('READY')
      setPredictionStage('ready')
      setAuthFlowStage('predictionReady')
      setStartupState(startupStates.READY)
      savePredictionSession({
        ...returningSession,
        resultReady: true,
        otpVerified: true,
        status: 'ready',
      })
    } catch {
      resetPredictionFlowState({ clearIdentity: true })
      setSessionMessage('We could not load your previous prediction. Please start a new prediction.')
      window.setTimeout(() => emailInputRef.current?.focus(), 80)
    }
  }

  const handleStartNewPrediction = () => {
    resetPredictionFlowState({ clearIdentity: true })
    window.setTimeout(() => emailInputRef.current?.focus(), 80)
  }

  const handlePlayerComplete = () => {
    setIsPlayerOpen(false)
    loadFutureOffer()
  }

  const loadFutureOffer = async (accessOverride = null) => {
    const accessRequest = accessOverride || predictionRequestAccess
    if (!accessRequest) return

    setIsPlayerOpen(false)
    setCheckoutError('')
    setCheckoutStatus('loading')
    setPostReadingStage('futureOffer')

    try {
      const access = await fetchFutureAccess(accessRequest)
      setFutureAccess(access)
      setPostReadingStage(access?.future_prediction_enabled ? 'futureReadyConfirm' : 'futureOffer')
      setCheckoutStatus('idle')
    } catch {
      setCheckoutError('We could not load the future prediction offer right now.')
      setCheckoutStatus('idle')
      setPostReadingStage('futureOffer')
    }
  }

  const handlePrepareFuturePrediction = async () => {
    if (!predictionRequestAccess) return

    setCheckoutError('')
    setCheckoutStatus('preparingFuture')

    try {
      const result = await fetchPredictionResult({
        ...predictionRequestAccess,
        type: 'future',
      })
      const playlist = buildPredictionPlaylist(result, 'future')
      setFutureResult(result)
      setFuturePlaylist(playlist)
      setPostReadingStage('futureReady')
      setCheckoutStatus('idle')
    } catch {
      setCheckoutError('Your payment is verified, but we could not prepare the future prediction yet. Please try again.')
      setCheckoutStatus('paid')
    }
  }

  const handleContinueExistingFuturePrediction = async () => {
    if (!predictionRequestAccess) return

    setCheckoutError('')
    setCheckoutStatus('loadingFuture')

    try {
      const result = await fetchStoredPredictionResult({
        ...predictionRequestAccess,
        type: 'future',
      })
      const playlist = buildPredictionPlaylist(result, 'future')
      setFutureResult(result)
      setFuturePlaylist(playlist)
      setPostReadingStage('futurePlaying')
      setCheckoutStatus('idle')
    } catch {
      setCheckoutStatus('idle')
      setCheckoutError('We could not load your existing future prediction right now.')
    }
  }

  const handleFuturePayment = async () => {
    if (!predictionRequestAccess) return

    setCheckoutError('')
    setCheckoutStatus('creatingOrder')

    try {
      const checkout = await createFuturePredictionOrder({
        ...predictionRequestAccess,
        productId: futureAccess?.product?.product_id,
      })
      setFutureAccess((current) => ({ ...(current || {}), product: checkout.product }))
      setPostReadingStage('checkout')

      const razorpayLoaded = await loadRazorpayScript()
      if (!razorpayLoaded || !window.Razorpay || !checkout.razorpay_key_id) {
        setCheckoutStatus('checkoutReady')
        setCheckoutError('Secure payment checkout is not configured in this environment.')
        return
      }

      const options = {
        key: checkout.razorpay_key_id,
        amount: checkout.product.amount_minor,
        currency: checkout.product.currency,
        name: 'Your Good Time',
        description: checkout.product.name,
        order_id: checkout.razorpay_order_id,
        prefill: checkout.prefill || undefined,
        handler: async (response) => {
          setCheckoutStatus('verifying')
          const verification = await verifyRazorpayPayment(response)
          if (verification.future_prediction_enabled) {
            setCheckoutStatus('paid')
            setFutureAccess((current) => ({ ...(current || {}), entitlement: verification.entitlement }))
            handlePrepareFuturePrediction()
            return
          }
          setCheckoutStatus('failed')
        },
        modal: {
          ondismiss: () => {
            setCheckoutStatus('dismissed')
            setCheckoutError('Payment was not completed. You can try again whenever you are ready.')
          },
        },
        theme: {
          color: '#ff9d2e',
        },
      }

      const razorpay = new window.Razorpay(options)
      razorpay.open()
      setCheckoutStatus('checkoutOpen')
    } catch {
      setCheckoutStatus('failed')
      setCheckoutError('Payment could not be completed. No future access has been activated.')
    }
  }

  useEffect(() => {
    if (
      startupState !== startupStates.NEW_PREDICTION_FORM
      || !isLoggedIn()
      || !pendingPredictionActionRef.current
      || submissionInFlightRef.current
      || isSubmittingPrediction
    ) {
      return
    }

    const payload = buildPredictionPayload()
    if (!payload) return

    pendingPredictionActionRef.current = null
    submitPredictionPayload(payload)
    // This effect intentionally consumes the one browser-flow pending action.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [startupState, isSubmittingPrediction])

  const handleOtpChange = (index, value) => {
    const digit = value.replace(/\D/g, '').slice(-1)
    const nextOtp = [...otp]
    nextOtp[index] = digit
    setOtp(nextOtp)
    setError('')
    setMessage('')

    if (digit && index < otpRefs.current.length - 1) {
      otpRefs.current[index + 1]?.focus()
    }
  }

  const handleOtpKeyDown = (index, event) => {
    if (event.key !== 'Backspace' || otp[index] || index === 0) return
    otpRefs.current[index - 1]?.focus()
  }

  const handleOtpPaste = (event) => {
    const pastedOtp = event.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6)

    if (!pastedOtp) return

    event.preventDefault()

    const nextOtp = [...emptyOtp]
    pastedOtp.split('').forEach((digit, index) => {
      nextOtp[index] = digit
    })

    setOtp(nextOtp)
    setError('')
    setMessage('')
    otpRefs.current[Math.min(pastedOtp.length, otpRefs.current.length - 1)]?.focus()
  }

  const renderAuthStatusVisual = () => {
    if (authFlowStage === 'sendingOtp') {
      return (
        <div className="past-auth-visual past-auth-visual--mail" aria-hidden="true">
          <span className="past-mail-trail" />
          <span className="past-mail-icon">
            <span />
          </span>
        </div>
      )
    }

    if (authFlowStage === 'otpSent' || authFlowStage === 'otpVerified') {
      return (
        <div className="past-auth-visual past-auth-visual--success" aria-hidden="true">
          <span className="past-check-icon" />
        </div>
      )
    }

    if (authFlowStage === 'verifyingOtp') {
      return (
        <div className="past-auth-visual past-auth-visual--verify" aria-hidden="true">
          <span className="past-verify-ring" />
          <span className="past-shield-icon" />
        </div>
      )
    }

    return null
  }

  const formattedReturningDate = returningSession?.generated_at || returningSession?.created_at
    ? new Intl.DateTimeFormat('en-IN', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
    }).format(new Date(returningSession.generated_at || returningSession.created_at))
    : 'Not generated yet'

  const renderStartupMessage = () => (
    <div className="past-session-loading" role="status" aria-live="polite">
      <img src={templeImage} alt="" />
      <h1>{isLoadingExistingResult ? 'Loading your prediction...' : 'Checking your previous prediction...'}</h1>
      <p>{isLoadingExistingResult ? 'We are safely opening your stored reading.' : 'Please wait while we verify your saved session.'}</p>
    </div>
  )

  const renderReturningUserPrompt = () => (
    <div className="past-returning-card">
      <span className="past-returning-badge" aria-hidden="true">YGT</span>
      <h1 id="past-returning-title">Welcome back</h1>
      <p>We found your previous past prediction.</p>

      <dl className="past-returning-summary">
        <div>
          <dt>Name</dt>
          <dd>{returningSession?.name || fullName || 'Previous user'}</dd>
        </div>
        <div>
          <dt>Email</dt>
          <dd>{returningSession?.masked_email || 'Saved privately'}</dd>
        </div>
        <div>
          <dt>Status</dt>
          <dd>{returningSession?.result_ready ? 'Ready' : returningSession?.otp_verified ? 'Verified' : 'Saved'}</dd>
        </div>
        <div>
          <dt>Generated</dt>
          <dd>{formattedReturningDate}</dd>
        </div>
      </dl>

      {sessionMessage ? <p className="past-note" role="status">{sessionMessage}</p> : null}

      <div className="past-returning-actions">
        <button type="button" className="past-submit" onClick={handleContinueExistingPrediction}>
          Continue My Prediction
        </button>
        <button type="button" className="past-returning-secondary" onClick={handleStartNewPrediction}>
          Start a New Prediction
        </button>
      </div>
    </div>
  )

  return (
    <main className={`past-modal-page ${isModal ? 'is-modal' : 'is-standalone'}`}>
      <section
        className={`past-modal-card ${isPreparingPrediction || isPredictionReady || isHydratingSession || isLoadingExistingResult ? 'is-processing' : ''} ${isReturningUserPrompt ? 'is-returning' : ''} past-flow-${authFlowStage}`}
        role={isModal || isPredictionReady ? 'dialog' : undefined}
        aria-modal={isModal || isPredictionReady ? 'true' : undefined}
        aria-labelledby={isReturningUserPrompt ? 'past-returning-title' : isPredictionReady ? 'past-ready-title' : undefined}
        aria-label={isReturningUserPrompt || isPredictionReady ? undefined : 'Get Past Prediction'}
      >
        <img src={templeImage} alt="" className="past-modal-temple" />

        {isHydratingSession || isLoadingExistingResult ? renderStartupMessage() : null}

        {isReturningUserPrompt ? renderReturningUserPrompt() : null}

        {isEmailStage ? (
          <>
            <h1>{isFutureIntent ? 'Get Future Prediction' : 'Get Past Prediction'}</h1>
            {sessionMessage ? <p className="past-note" role="status">{sessionMessage}</p> : null}
            <p>{authFlowStage === 'sendingOtp' ? 'Sending your secure OTP...' : `Share your birth details so we can prepare your personalized ${isFutureIntent ? 'future checkout' : 'past video'}.`}</p>
            {renderAuthStatusVisual()}

            <PredictionRegistrationForm
              values={{
                fullName,
                email,
                dateOfBirth,
                timeOfBirth,
                marriageStatus,
                placeOfBirth,
              }}
              disabled={isSendingOtp}
              isSubmitting={isSubmittingPrediction || isSendingOtp}
              loadingLabel={isSendingOtp ? 'Sending OTP...' : 'Submitting...'}
              submitLabel={isFutureIntent ? 'Continue to Future' : 'See My Past'}
              error={error}
              fieldErrors={fieldErrors}
              emailInputRef={emailInputRef}
              onSubmit={handleBirthSubmit}
              onFieldChange={(field, value) => {
                if (field === 'fullName') {
                  setFullName(toTitleCase(value))
                  setFieldErrors((current) => ({ ...current, full_name: undefined }))
                }
                if (field === 'email') {
                  setEmail(value)
                  setFieldErrors((current) => ({ ...current, email: undefined }))
                }
                if (field === 'dateOfBirth') {
                  setDateOfBirth(value)
                  setFieldErrors((current) => ({ ...current, date_of_birth: undefined }))
                }
                if (field === 'timeOfBirth') {
                  setTimeOfBirth(value)
                  setFieldErrors((current) => ({ ...current, time_of_birth: undefined }))
                }
                if (field === 'marriageStatus') {
                  setMarriageStatus(value)
                  setFieldErrors((current) => ({ ...current, marriage_status: undefined }))
                }
                setError('')
              }}
              onPlaceChange={handlePlaceChange}
              onPlaceSelect={handlePlaceSelect}
            >
              {isExistingEmailError ? (
                <button type="button" className="past-auth-link" onClick={handleExistingEmailLogin}>
                  Login to continue
                </button>
              ) : null}
            </PredictionRegistrationForm>
          </>
        ) : null}

        {isOtpStage ? (
          <>
            <h1>{authFlowStage === 'passwordSetup' ? 'Create Password' : authFlowStage === 'otpVerified' ? 'Verified' : 'Enter OTP'}</h1>
            <p>
              {authFlowStage === 'verifyingOtp'
                ? 'Verifying your OTP...'
                : authFlowStage === 'otpVerified'
                  ? 'OTP verified successfully'
                  : authFlowStage === 'passwordSetup'
                    ? 'Set a password to create your account and continue your prediction.'
                  : `We sent a 6 digit OTP to ${email}.`}
            </p>
            {renderAuthStatusVisual()}

            <form className="past-form" onSubmit={handleOtpSubmit}>
              {authFlowStage === 'passwordSetup' ? (
                <>
                  <label>
                    Password
                    <input
                      type="password"
                      value={registrationPassword}
                      disabled={isAuthLoading}
                      minLength={8}
                      onChange={(event) => {
                        setRegistrationPassword(event.target.value)
                        setError('')
                      }}
                      placeholder="Minimum 8 characters"
                      autoComplete="new-password"
                      required
                    />
                  </label>
                  <label>
                    Retype Password
                    <input
                      type="password"
                      value={registrationPasswordConfirmation}
                      disabled={isAuthLoading}
                      minLength={8}
                      onChange={(event) => {
                        setRegistrationPasswordConfirmation(event.target.value)
                        setError('')
                      }}
                      placeholder="Retype password"
                      autoComplete="new-password"
                      required
                    />
                  </label>
                </>
              ) : (
                <div className={`past-otp-boxes ${otpShakeCount > 0 ? 'is-shaking' : ''}`} key={`past-otp-${otpShakeCount}`}>
                  {otp.map((digit, index) => (
                    <input
                      // eslint-disable-next-line react/no-array-index-key
                      key={index}
                      ref={(element) => {
                        otpRefs.current[index] = element
                      }}
                      type="text"
                      inputMode="numeric"
                      value={digit}
                      disabled={isAuthLoading}
                      onChange={(event) => handleOtpChange(index, event.target.value)}
                      onKeyDown={(event) => handleOtpKeyDown(index, event)}
                      onPaste={handleOtpPaste}
                      aria-label={`OTP digit ${index + 1}`}
                      autoComplete={index === 0 ? 'one-time-code' : 'off'}
                    />
                  ))}
                </div>
              )}

              {error ? <p className="past-error">{error}</p> : null}
              {message ? <p className="past-note" role="status">{message}</p> : null}

              <button type="submit" className="past-submit" disabled={isAuthLoading || isSubmittingPrediction}>
                {isVerifyingOtp ? (registrationPassword ? 'Creating Account...' : 'Verifying OTP...') : isSubmittingPrediction ? 'Submitting...' : authFlowStage === 'passwordSetup' ? 'Create Account' : 'Verify OTP'}
              </button>
            </form>

            <div className="past-auth-actions">
              <button type="button" className="past-auth-link" disabled={isAuthLoading} onClick={handleChangeEmail}>
                Change email
              </button>
              <button type="button" className="past-auth-link" disabled={resendCountdown > 0 || isAuthLoading || authFlowStage === 'passwordSetup'} onClick={handleResendOtp}>
                {resendCountdown > 0 ? `Resend OTP in ${resendCountdown}s` : 'Resend OTP'}
              </button>
            </div>
          </>
        ) : null}

        {isPreparingPrediction || isPredictionReady ? (
          <PastPredictionProcessing
            predictionStage={predictionStage}
            userName={fullName}
            errorMessage={processingError}
            preparationStatus={preparationStatus}
            resultSummary={predictionResult?.summary}
            playlist={predictionPlaylist}
            onRetry={handleRetryPrediction}
            onBackToForm={handleBackToForm}
            onContinue={handleContinue}
          />
        ) : null}

        {!isHydratingSession && !isLoadingExistingResult && (!isPreparingPrediction || predictionStage === 'error') ? (
          <button type="button" className="past-close-link" onClick={handleClose}>Close</button>
        ) : null}
      </section>

      {isClaimedModalOpen ? (
        <ActionModal
          title="Free Past Prediction Already Claimed"
          message="This email has already received its complimentary Past Prediction."
          primaryLabel="View My Prediction"
          secondaryLabel="Use Another Email"
          onPrimary={handleViewPrediction}
          onSecondary={handleUseAnotherEmail}
          isSecondaryLoading={isLoggingOut}
        />
      ) : null}

      {isPlayerOpen ? (
        <PredictionPlayer
          playlist={predictionPlaylist}
          onClose={() => setIsPlayerOpen(false)}
          onComplete={handlePlayerComplete}
          requireFeedbackOnFinish={!pastFeedbackSubmitted}
          onFeedbackSaved={() => setPastFeedbackSubmitted(true)}
          onFeedbackSubmitted={() => {
            setPastFeedbackSubmitted(true)
            handlePlayerComplete()
          }}
          predictionRequestAccess={predictionRequestAccess}
          predictionResultId={predictionResult?.result_id}
          predictionType="past"
        />
      ) : null}

      {postReadingStage === 'futureReadyConfirm' ? (
        <section className="post-reading-overlay" role="dialog" aria-modal="true" aria-label="Future prediction ready confirmation">
          <div className="post-reading-panel">
            <h2>Your Future Prediction Is Ready</h2>
            <p>Your future prediction is already available. Would you like to continue now?</p>
            {checkoutError ? <p className="post-reading-error">{checkoutError}</p> : null}
            <div className="post-reading-actions">
              <button type="button" onClick={handleClose}>Later</button>
              <button type="button" disabled={checkoutStatus === 'loadingFuture'} onClick={handleContinueExistingFuturePrediction}>
                {checkoutStatus === 'loadingFuture' ? 'Loading...' : 'Continue to Future'}
              </button>
            </div>
          </div>
        </section>
      ) : null}

      {postReadingStage === 'futureOffer' ? (
        <section className="post-reading-overlay" role="dialog" aria-modal="true" aria-label="Future prediction offer">
          <div className="post-reading-panel">
            <h2>Know Your Future</h2>
            <p>Unlock your personalised future prediction videos and discover the important periods ahead.</p>
            {futureAccess?.product ? (
              <div className="checkout-summary">
                <span>Product</span>
                <strong>{futureAccess.product.name}</strong>
                <span>Duration</span>
                <strong>{futureAccess.product.duration_label || `${futureAccess.product.future_years || ''} years`}</strong>
                <span>Price</span>
                <strong>{futureAccess.product.display_price}</strong>
                <small>Inclusive of all applicable taxes</small>
                {futureAccess.product.description ? (
                  <>
                    <span>Description</span>
                    <strong>{futureAccess.product.description}</strong>
                  </>
                ) : null}
              </div>
            ) : null}
            {checkoutError ? <p className="post-reading-error">{checkoutError}</p> : null}
            <div className="post-reading-actions">
              <button type="button" onClick={handleClose}>Not Now</button>
              <button type="button" disabled={checkoutStatus === 'loading' || !futureAccess?.product} onClick={() => setPostReadingStage('checkout')}>
                Unlock Future
              </button>
            </div>
          </div>
        </section>
      ) : null}

      {postReadingStage === 'checkout' ? (
        <section className="post-reading-overlay" role="dialog" aria-modal="true" aria-label="Future prediction checkout">
          <div className="post-reading-panel">
            <h2>Future Prediction</h2>
            <div className="checkout-summary">
              <span>Product</span>
              <strong>{futureAccess?.product?.name || 'Future Prediction'}</strong>
              <span>Prediction period</span>
              <strong>{futureAccess?.product?.duration_label || `${futureAccess?.product?.future_years || ''} years`}</strong>
              <span>Price</span>
              <strong>{futureAccess?.product?.display_price || 'Loading...'}</strong>
              <small>Inclusive of tax</small>
              <span>Total payable</span>
              <strong>{futureAccess?.product?.display_price || 'Loading...'}</strong>
            </div>
            {checkoutStatus === 'paid' ? (
              <p className="past-note">Payment Successful. Your future prediction has been unlocked.</p>
            ) : null}
            {checkoutError ? <p className="post-reading-error">{checkoutError}</p> : null}
            <div className="post-reading-actions">
              {checkoutStatus === 'paid' ? (
                <button type="button" onClick={handlePrepareFuturePrediction}>Prepare My Future Prediction</button>
              ) : (
                <button type="button" disabled={checkoutStatus === 'creatingOrder' || checkoutStatus === 'verifying'} onClick={handleFuturePayment}>
                  {checkoutStatus === 'creatingOrder' ? 'Creating Order...' : checkoutStatus === 'verifying' ? 'Verifying...' : 'Proceed to Secure Payment'}
                </button>
              )}
              <button type="button" onClick={() => setPostReadingStage('futureOffer')}>Back</button>
            </div>
          </div>
        </section>
      ) : null}

      {postReadingStage === 'futureReady' ? (
        <section className="post-reading-overlay" role="dialog" aria-modal="true" aria-label="Future prediction ready">
          <div className="post-reading-panel">
            <h2>Your Future Prediction Is Ready</h2>
            <p>Your future journey has been prepared.</p>
            <div className="post-reading-actions">
              <button type="button" onClick={() => setPostReadingStage('futurePlaying')}>Play My Future Prediction</button>
              <button type="button" onClick={() => setPostReadingStage('complete')}>Back to Home</button>
            </div>
          </div>
        </section>
      ) : null}

      {postReadingStage === 'futurePlaying' ? (
        <PredictionPlayer
          playlist={futurePlaylist}
          onClose={() => setPostReadingStage('futureReady')}
          completionTitle="Your Future Reading Is Complete"
          predictionRequestAccess={predictionRequestAccess}
          predictionResultId={futureResult?.result_id}
          predictionType="future"
          onComplete={() => setPostReadingStage('complete')}
        />
      ) : null}

      {postReadingStage === 'complete' ? (
        <section className="post-reading-overlay" role="dialog" aria-modal="true" aria-label="Reading complete">
          <div className="post-reading-panel">
            <h2>Your Past Reading Is Complete</h2>
            <p>Thank you for experiencing your journey with Your Good Time.</p>
            <div className="post-reading-actions">
              <button type="button" onClick={() => setPostReadingStage('idle')}>Back to Home</button>
            </div>
          </div>
        </section>
      ) : null}
    </main>
  )
}

export default PastPredictionPage
