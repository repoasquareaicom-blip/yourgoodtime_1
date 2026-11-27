import { useEffect, useMemo, useRef, useState } from 'react'
import AuthenticationLoader from './AuthenticationLoader'
import PredictionRegistrationForm from './PredictionRegistrationForm'
import ygtCircle from '../../assets/ygt_circle.png'
import templeImage from '../../assets/temple-transparent.png'
import { loginWithPassword, sendOtp, validateOtp, verifyOtp } from '../../services/authService'
import { saveToken, saveUser } from '../../utils/auth'
import { dateOfBirthEligibilityError, dateYearsAgo, MIN_SIGNUP_AGE } from '../../utils/birthDetails'
import '../../pages/LoginPage.css'

const emptyOtp = ['', '', '', '', '', '']
const resendSeconds = 180

function getErrorMessage(error, fallback) {
  const data = error?.response?.data
  const firstValidationMessage = data?.errors
    ? Object.values(data.errors).flat().find(Boolean)
    : ''

  return firstValidationMessage || data?.message || error?.message || fallback
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

function AuthPanel({ initialMode = 'login', onAuthenticated, onClose }) {
  const otpRefs = useRef([])
  const [mode, setMode] = useState(initialMode)
  const [step, setStep] = useState('details')
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [dateOfBirth, setDateOfBirth] = useState('')
  const [timeOfBirth, setTimeOfBirth] = useState('')
  const [marriageStatus, setMarriageStatus] = useState('')
  const [placeOfBirth, setPlaceOfBirth] = useState('')
  const [placeOfBirthDetails, setPlaceOfBirthDetails] = useState(null)
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [otp, setOtp] = useState(emptyOtp)
  const [status, setStatus] = useState('idle')
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [resendCountdown, setResendCountdown] = useState(0)
  const [otpShakeCount, setOtpShakeCount] = useState(0)
  const [successMode, setSuccessMode] = useState('login')
  const [successName, setSuccessName] = useState('')
  const [otpPurpose, setOtpPurpose] = useState(initialMode === 'forgot' ? 'password_reset' : initialMode === 'register' ? 'signup' : 'login')
  const [accountState, setAccountState] = useState(null)
  const [isCompactForm, setIsCompactForm] = useState(() => (
    typeof window !== 'undefined' ? window.matchMedia('(max-width: 759px)').matches : false
  ))
  const otpValue = otp.join('')
  const isLoading = status === 'sending' || status === 'verifying' || status === 'loggingIn'
  const isCenteredSending = status === 'sending' && mode === 'register' && step === 'details'
  const isOtpMode = mode === 'otp-login' || mode === 'register' || mode === 'forgot'
  const firstName = useMemo(() => {
    const value = name.trim() || email.trim()
    return value.split(/\s|@/).filter(Boolean)[0] || 'there'
  }, [email, name])

  useEffect(() => {
    if (!isOtpMode || step === 'details' || resendCountdown <= 0) return undefined

    const timer = window.setInterval(() => {
      setResendCountdown((seconds) => Math.max(seconds - 1, 0))
    }, 1000)

    return () => window.clearInterval(timer)
  }, [isOtpMode, resendCountdown, step])

  useEffect(() => {
    const mediaQuery = window.matchMedia('(max-width: 759px)')
    const syncCompactForm = () => setIsCompactForm(mediaQuery.matches)

    syncCompactForm()
    mediaQuery.addEventListener('change', syncCompactForm)
    return () => mediaQuery.removeEventListener('change', syncCompactForm)
  }, [])

  const heading = useMemo(() => {
    if (step === 'success') return 'Welcome to Your Good Time'
    if (accountState === 'EXISTING_WITH_PASSWORD' && step === 'otp') return 'Welcome Back'
    if (accountState === 'EXISTING_WITHOUT_PASSWORD' && step === 'password') return 'Create Password'
    if (mode === 'register') return step === 'password' ? 'Create Password' : step === 'otp' ? 'Enter OTP' : 'Share your details'
    if (mode === 'forgot') return step === 'password' ? 'Reset Password' : step === 'otp' ? 'Enter OTP' : 'Forgot Password'
    if (mode === 'otp-login') return step === 'otp' ? 'Enter OTP' : 'Login Using OTP'
    return 'Login'
  }, [mode, step])

  const purpose = otpPurpose

  const resetRegistrationFields = () => {
    setName('')
    setDateOfBirth('')
    setTimeOfBirth('')
    setMarriageStatus('')
    setPlaceOfBirth('')
    setPlaceOfBirthDetails(null)
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

  const buildRegistrationPayload = () => {
    const trimmedName = name.trim()
    const trimmedEmail = email.trim()

    if (!trimmedName || !trimmedEmail || !dateOfBirth || !timeOfBirth || !marriageStatus || !placeOfBirth.trim()) {
      setError('Please complete all details.')
      return null
    }

    const dobError = dateOfBirthEligibilityError(dateOfBirth)
    if (dobError) {
      setError(dobError)
      return null
    }

    if (!hasSelectedBirthplace()) {
      setError('Please select your birthplace from the suggestions.')
      return null
    }

    const timezone = resolveBirthTimezone(placeOfBirthDetails)
    if (!timezone) {
      setError('Please select a birthplace with a valid timezone.')
      return null
    }

    return {
      full_name: trimmedName,
      email: trimmedEmail,
      purpose: 'signup',
      date_of_birth: dateOfBirth,
      time_of_birth: timeOfBirth,
      birth_place: placeOfBirth.trim(),
      place_of_birth_details: placeOfBirthDetails,
      latitude: Number(placeOfBirthDetails.latitude),
      longitude: Number(placeOfBirthDetails.longitude),
      timezone,
      country: placeOfBirthDetails.country || null,
      country_code: String(placeOfBirthDetails.country_code || '').toUpperCase() || null,
      marriage_status: marriageStatus || null,
    }
  }

  const finishRegistration = (response) => {
    if (response?.code !== 'REGISTRATION_COMPLETED') {
      setError(response?.message || 'Registration response is missing details.')
      setStatus('error')
      return
    }

    setSuccessMode('register')
    setSuccessName(name.trim() || firstName)
    setStatus('verified')
    setStep('success')
    setMessage('')
    window.setTimeout(() => {
      resetMode('login')
      setEmail('')
      resetRegistrationFields()
      setMessage('Registration successful. Please log in to continue.')
    }, 950)
  }

  const completeAuth = (response) => {
    if (!response?.token || !response?.user) {
      setError(response?.message || 'Authentication response is missing details.')
      setStatus('error')
      return
    }

    saveToken(response.token)
    saveUser(response.user)
    setSuccessMode(mode === 'register' ? 'register' : 'login')
    setSuccessName(response.user?.name || firstName)
    setStatus('verified')
    setStep('success')
    setMessage('')
    window.setTimeout(() => onAuthenticated?.(response), 850)
  }

  const resetMode = (nextMode) => {
    setMode(nextMode)
    setStep('details')
    setStatus('idle')
    setError('')
    setMessage('')
    setPassword('')
    setPasswordConfirmation('')
    setOtp(emptyOtp)
    setResendCountdown(0)
    setAccountState(null)
    setOtpPurpose(nextMode === 'forgot' ? 'password_reset' : nextMode === 'register' ? 'signup' : 'login')
  }

  const usePasswordInstead = () => {
    setMode('login')
    setStep('details')
    setStatus('idle')
    setError('')
    setMessage('Enter your password to continue.')
    setPassword('')
    setPasswordConfirmation('')
    setOtp(emptyOtp)
    setOtpPurpose('login')
  }

  const changeOtpEmail = () => {
    setStep('details')
    setStatus('idle')
    setError('')
    setMessage('')
    setPassword('')
    setPasswordConfirmation('')
    setOtp(emptyOtp)
    setResendCountdown(0)

    if (mode === 'forgot') {
      setOtpPurpose('password_reset')
      return
    }

    if (mode === 'otp-login') {
      setOtpPurpose('login')
      return
    }

    setMode('register')
    setOtpPurpose('signup')
    setAccountState(null)
  }

  const sendModeOtp = async () => {
    const trimmedEmail = email.trim()
    if (!trimmedEmail) {
      setError('Please enter your email address.')
      return
    }

    setStatus('sending')
    setError('')
    setMessage('')

    try {
      const registrationPayload = mode === 'register' ? buildRegistrationPayload() : null
      if (mode === 'register' && !registrationPayload) {
        setStatus('idle')
        return
      }

      const payload = registrationPayload ?? {
        email: trimmedEmail,
        purpose,
      }
      const response = await sendOtp(payload)
      const nextAction = response?.next_action
      const nextAccountState = response?.account_state || null

      if (nextAction === 'VERIFY_LOGIN_OTP') {
        setMode('otp-login')
        setOtpPurpose('login')
        setAccountState(nextAccountState || 'EXISTING_WITH_PASSWORD')
        setMessage('We found your existing account. Verify the OTP sent to your email to continue.')
      } else if (nextAction === 'VERIFY_PASSWORD_SETUP_OTP') {
        setMode('register')
        setOtpPurpose('password_reset')
        setAccountState(nextAccountState || 'EXISTING_WITHOUT_PASSWORD')
        setMessage('We found your existing account. Verify the OTP sent to your email, then create your password.')
      } else {
        setOtpPurpose(payload.purpose)
        setAccountState(null)
        setMessage(response?.message || 'OTP sent successfully.')
      }
      setOtp(emptyOtp)
      setResendCountdown(resendSeconds)
      setStatus('idle')
      setStep('otp')
      window.setTimeout(() => otpRefs.current[0]?.focus(), 80)
    } catch (sendError) {
      setStatus('error')
      setError(getErrorMessage(sendError, 'Unable to send OTP. Please try again.'))
    }
  }

  const verifyModeOtp = async () => {
    if (otpValue.length !== 6) {
      setError('Please enter the 6 digit OTP.')
      return
    }

    if (mode === 'register' || mode === 'forgot') {
      setStatus('verifying')
      setError('')
      setMessage('')

      try {
        await validateOtp({ email: email.trim(), otp: otpValue, purpose })
        setStatus('idle')
        setMessage(accountState === 'EXISTING_WITHOUT_PASSWORD' ? 'OTP verified. Set your password to continue.' : 'OTP verified. Set your password to continue.')
        setStep('password')
      } catch (verifyError) {
        setStatus('error')
        setOtp(emptyOtp)
        setOtpShakeCount((count) => count + 1)
        setError(getErrorMessage(verifyError, 'Invalid or expired OTP. Please try again.'))
      }
      return
    }

    setStatus('verifying')
    setError('')
    setMessage('')
    try {
      completeAuth(await verifyOtp({ email: email.trim(), otp: otpValue, purpose }))
    } catch (verifyError) {
      setStatus('error')
      setOtp(emptyOtp)
      setOtpShakeCount((count) => count + 1)
      setError(getErrorMessage(verifyError, 'Invalid or expired OTP. Please try again.'))
    }
  }

  const completePasswordMode = async () => {
    if (password.length < 8) {
      setError('Password must be at least 8 characters.')
      return
    }

    if (password !== passwordConfirmation) {
      setError('Passwords do not match.')
      return
    }

    setStatus('verifying')
    setError('')
    setMessage('')
    try {
      const response = await verifyOtp({
        email: email.trim(),
        otp: otpValue,
        purpose,
        password,
        password_confirmation: passwordConfirmation,
      })

      if (mode === 'register' && purpose === 'signup') {
        finishRegistration(response)
        return
      }

      completeAuth(response)
    } catch (verifyError) {
      setStatus('error')
      setError(getErrorMessage(verifyError, 'Unable to complete authentication. Please try again.'))
    }
  }

  const loginPassword = async () => {
    if (!email.trim() || !password) {
      setError('Email and password are required.')
      return
    }

    setStatus('loggingIn')
    setError('')
    setMessage('')
    try {
      completeAuth(await loginWithPassword({ email: email.trim(), password }))
    } catch (loginError) {
      if (loginError?.response?.data?.code === 'PASSWORD_NOT_SET') {
        setMode('forgot')
        setOtpPurpose('password_reset')
        setAccountState('EXISTING_WITHOUT_PASSWORD')
        setPassword('')
        setPasswordConfirmation('')
        setMessage('Verify the OTP sent to your email, then create your password.')
        try {
          await sendOtp({ email: email.trim(), purpose: 'password_reset' })
          setOtp(emptyOtp)
          setResendCountdown(resendSeconds)
          setStep('otp')
          setStatus('idle')
          window.setTimeout(() => otpRefs.current[0]?.focus(), 80)
        } catch (otpError) {
          setStatus('error')
          setError(getErrorMessage(otpError, 'Unable to send OTP. Please try again.'))
        }
        return
      }

      setStatus('error')
      setError(getErrorMessage(loginError, 'Invalid email or password.'))
    }
  }

  const handleSubmit = (event) => {
    event.preventDefault()

    if (mode === 'login') {
      loginPassword()
      return
    }

    if (step === 'details') {
      sendModeOtp()
      return
    }

    if (step === 'otp') {
      verifyModeOtp()
      return
    }

    if (step === 'password') {
      completePasswordMode()
    }
  }

  const handleOtpChange = (index, value) => {
    const digit = value.replace(/\D/g, '').slice(-1)
    const nextOtp = [...otp]
    nextOtp[index] = digit
    setOtp(nextOtp)
    setError('')

    if (digit && index < otpRefs.current.length - 1) {
      otpRefs.current[index + 1]?.focus()
    }
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
  }

  return (
    <section className={`auth-card auth-state-${status}`} aria-label="Authentication">
      <img src={templeImage} alt="" className="auth-temple" />
      {onClose ? (
        <button type="button" className="auth-modal-close" aria-label="Close" onClick={onClose}>
          <span className="modal-close-icon" aria-hidden="true" />
        </button>
      ) : null}
      <h1 className={mode === 'register' && step === 'details' ? 'auth-share-heading' : undefined}>{heading}</h1>
      {mode === 'register' && step === 'details' ? (
        <p className="auth-share-subtitle">We'll use these to set up your private account.</p>
      ) : (
        <p>{step === 'otp' ? `We sent a 6 digit OTP to ${email}.` : step === 'password' ? 'Choose a secure password to continue.' : 'Access your private guidance and predictions.'}</p>
      )}

      {mode === 'register' && step === 'details' ? null : (
        <div className="auth-benefits" aria-label="Authentication options">
          <span>Private</span>
          <span>Secure</span>
          <span>Reusable</span>
        </div>
      )}

      <form onSubmit={handleSubmit} className="auth-form" autoComplete={mode === 'register' ? 'off' : undefined}>
        {step === 'details' ? (
          <>
            {mode === 'register' ? (
              <PredictionRegistrationForm
                values={{
                  fullName: name,
                  email,
                  dateOfBirth,
                  timeOfBirth,
                  marriageStatus,
                  placeOfBirth,
                }}
                disabled={isLoading}
                isSubmitting={false}
                submitLabel="Send OTP"
                loadingLabel="Sending OTP..."
                error=""
                fieldErrors={{}}
                requireMinimumAge
                dateInputMax={dateYearsAgo(MIN_SIGNUP_AGE)}
                renderAsForm={false}
                compactLabels={isCompactForm}
                onSubmit={(event) => {
                  event.preventDefault()
                  sendModeOtp()
                }}
                onFieldChange={(field, value) => {
                  if (field === 'fullName') setName(value)
                  if (field === 'email') setEmail(value)
                  if (field === 'dateOfBirth') setDateOfBirth(value)
                  if (field === 'timeOfBirth') setTimeOfBirth(value)
                  if (field === 'marriageStatus') setMarriageStatus(value)
                  setError('')
                }}
                onPlaceChange={(nextValue) => {
                  setPlaceOfBirth(nextValue)
                  setError('')

                  const selectedName = placeOfBirthDetails?.place_name?.trim().toLowerCase()
                  if (selectedName && selectedName !== nextValue.trim().toLowerCase()) {
                    setPlaceOfBirthDetails(null)
                  }
                }}
                onPlaceSelect={setPlaceOfBirthDetails}
              />
            ) : null}
            {mode !== 'register' ? (
              <>
                <label>
                  <span className="auth-field-label">Email</span>
                  <input type="email" value={email} disabled={isLoading} onChange={(event) => setEmail(event.target.value)} placeholder="name@example.com" autoComplete="email" required />
                </label>
                {mode === 'login' ? (
                  <label>
                    <span className="auth-field-label">Password</span>
                    <input type="password" value={password} disabled={isLoading} onChange={(event) => setPassword(event.target.value)} placeholder="Password" autoComplete="current-password" required />
                  </label>
                ) : null}
              </>
            ) : null}
          </>
        ) : null}

        {step === 'otp' ? (
          <div className={`auth-otp-wrap ${otpShakeCount > 0 ? 'is-shaking' : ''}`} key={`otp-${otpShakeCount}`}>
            <span>6 digit OTP</span>
            <div className="auth-otp-boxes">
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
                  disabled={isLoading}
                  onChange={(event) => handleOtpChange(index, event.target.value)}
                  onKeyDown={(event) => {
                    if (event.key === 'Backspace' && !otp[index] && index > 0) otpRefs.current[index - 1]?.focus()
                  }}
                  onPaste={handleOtpPaste}
                  aria-label={`OTP digit ${index + 1}`}
                  autoComplete={index === 0 ? 'one-time-code' : 'off'}
                />
              ))}
            </div>
          </div>
        ) : null}

        {step === 'password' ? (
          <>
            <label>
              <span className="auth-field-label">Password</span>
              <input type="password" value={password} disabled={isLoading} minLength={8} onChange={(event) => setPassword(event.target.value)} placeholder="Minimum 8 characters" autoComplete="new-password" required />
            </label>
            <label>
              <span className="auth-field-label">Retype Password</span>
              <input type="password" value={passwordConfirmation} disabled={isLoading} minLength={8} onChange={(event) => setPasswordConfirmation(event.target.value)} placeholder="Retype password" autoComplete="new-password" required />
            </label>
          </>
        ) : null}

        {step === 'success' ? (
          <div className="auth-success" role="status">
            <img src={ygtCircle} alt="" />
            <AuthenticationLoader
              compact
              title={successMode === 'register' ? 'Account created successfully' : `Welcome back, ${successName || firstName}`}
              message={successMode === 'register' ? 'Redirecting to login...' : 'Preparing your dashboard...'}
            />
          </div>
        ) : null}

        {isLoading && !isCenteredSending ? (
          <AuthenticationLoader
            compact
            title={status === 'sending' ? 'Sending OTP...' : status === 'verifying' ? 'Verifying OTP...' : 'Signing you in...'}
            message={status === 'sending' ? 'Please wait while we send your secure code.' : status === 'verifying' ? 'Please wait while we verify your code.' : 'Please wait while we prepare your experience.'}
          />
        ) : null}

        {error ? <p className="auth-error">{error}</p> : null}
        {message ? <p className="auth-message" role="status">{message}</p> : null}

        {step !== 'success' && !(mode === 'register' && step === 'details') ? (
          <button type="submit" className="auth-submit" disabled={isLoading}>
            {status === 'sending' ? 'Sending OTP...' : status === 'verifying' ? 'Verifying...' : status === 'loggingIn' ? 'Logging in...' : mode === 'login' ? 'Login' : step === 'password' ? 'Continue' : step === 'otp' ? 'Verify OTP' : 'Send OTP'}
          </button>
        ) : null}
      </form>

      <div className="auth-actions">
        {accountState === 'EXISTING_WITH_PASSWORD' && step === 'otp' ? (
          <button type="button" className="auth-link" disabled={isLoading} onClick={usePasswordInstead}>Use Password Instead</button>
        ) : (
          <button type="button" className="auth-link" disabled={isLoading} onClick={() => resetMode('otp-login')}>Login using OTP</button>
        )}
        <button type="button" className="auth-link" disabled={isLoading} onClick={() => resetMode('forgot')}>Forgot Password</button>
        <button type="button" className="auth-link" disabled={isLoading} onClick={() => (step === 'otp' ? changeOtpEmail() : resetMode(mode === 'register' ? 'login' : 'register'))}>
          {step === 'otp' ? 'Change Email' : mode === 'register' ? 'Login' : 'Create Account'}
        </button>
        {step === 'otp' ? (
          <button type="button" className="auth-link" disabled={resendCountdown > 0 || isLoading} onClick={sendModeOtp}>
            {resendCountdown > 0 ? `Resend OTP in ${resendCountdown}s` : 'Resend OTP'}
          </button>
        ) : null}
      </div>

      {isCenteredSending ? (
        <div className="auth-card-loader-overlay" role="status" aria-live="polite">
          <AuthenticationLoader
            compact
            title="Sending OTP..."
            message="Please wait while we send your secure code."
          />
        </div>
      ) : null}
    </section>
  )
}

export default AuthPanel
