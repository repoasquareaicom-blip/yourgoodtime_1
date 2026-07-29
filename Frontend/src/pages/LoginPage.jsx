import { useEffect, useMemo, useRef, useState } from 'react'
import { useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import templeImage from '../assets/temple-transparent.png'
import ygtCircle from '../assets/ygt_circle.png'
import { sendOtp, verifyOtp } from '../services/authService'
import { saveToken, saveUser } from '../utils/auth'
import './LoginPage.css'

const emptyOtp = ['', '', '', '', '', '']
const resendSeconds = 30
const otpRevealDelay = 520
const verifiedDelay = 850

function getErrorMessage(error, fallback) {
  return error?.response?.data?.message || error?.message || fallback
}

function LoginPage() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const location = useLocation()
  const otpRefs = useRef([])
  const transitionTimerRef = useRef(null)
  const [authMode, setAuthMode] = useState(searchParams.get('mode') === 'signup' ? 'signup' : 'login')
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [otp, setOtp] = useState(emptyOtp)
  const [step, setStep] = useState('details')
  const [authStatus, setAuthStatus] = useState('idle')
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [isSendingOtp, setIsSendingOtp] = useState(false)
  const [isVerifyingOtp, setIsVerifyingOtp] = useState(false)
  const [resendCountdown, setResendCountdown] = useState(0)
  const [otpShakeCount, setOtpShakeCount] = useState(0)
  const isModal = Boolean(location.state?.backgroundLocation)

  const otpValue = otp.join('')
  const isTransitioning = authStatus === 'sent' || authStatus === 'verified'
  const isLoading = isSendingOtp || isVerifyingOtp || isTransitioning

  const heading = useMemo(() => {
    if (authStatus === 'verified' || step === 'success') return 'Welcome to Your Good Time'
    if (step === 'otp') return 'Enter OTP'
    return authMode === 'signup' ? 'Create your account' : 'Login with email'
  }, [authMode, authStatus, step])

  const supportingCopy = useMemo(() => {
    if (authStatus === 'sending') return 'Sending your secure OTP...'
    if (authStatus === 'sent') return 'OTP sent successfully'
    if (authStatus === 'verifying') return 'Verifying your OTP...'
    if (authStatus === 'verified' || step === 'success') return 'OTP verified successfully'
    if (step === 'otp') return `We sent a 6 digit OTP to ${email}.`
    if (authMode === 'signup') return 'New here? Tell us your name and email to continue.'
    return 'Already registered? Enter your email to receive a secure OTP.'
  }, [authMode, authStatus, step, email])

  useEffect(() => () => {
    if (transitionTimerRef.current) {
      window.clearTimeout(transitionTimerRef.current)
    }
  }, [])

  useEffect(() => {
    if (step !== 'otp' || resendCountdown <= 0) return undefined

    const timer = window.setInterval(() => {
      setResendCountdown((seconds) => Math.max(seconds - 1, 0))
    }, 1000)

    return () => window.clearInterval(timer)
  }, [resendCountdown, step])

  const resetAuthMode = (nextMode) => {
    setAuthMode(nextMode)
    setStep('details')
    setAuthStatus('idle')
    setError('')
    setMessage('')
    setOtp(emptyOtp)
    setResendCountdown(0)
  }

  const handleSendOtp = async () => {
    const trimmedEmail = email.trim()

    if (authMode === 'signup' && !name.trim()) {
      setError('Please enter your name.')
      return
    }

    if (!trimmedEmail) {
      setError('Please enter your email address.')
      return
    }

    setIsSendingOtp(true)
    setAuthStatus('sending')
    setError('')
    setMessage('')

    try {
      const response = await sendOtp({
        email: trimmedEmail,
        purpose: 'login',
        ...(authMode === 'signup' && name.trim() ? { name: name.trim() } : {}),
      })

      setMessage(response?.message || 'OTP sent successfully')
      setAuthStatus('sent')
      setOtp(emptyOtp)
      setResendCountdown(resendSeconds)
      transitionTimerRef.current = window.setTimeout(() => {
        setAuthStatus('idle')
        setStep('otp')
        transitionTimerRef.current = window.setTimeout(() => otpRefs.current[0]?.focus(), 80)
      }, otpRevealDelay)
    } catch (sendError) {
      setError(getErrorMessage(sendError, 'Unable to send OTP. Please try again.'))
      setAuthStatus('error')
    } finally {
      setIsSendingOtp(false)
    }
  }

  const handleVerifyOtp = async () => {
    const trimmedEmail = email.trim()

    if (otpValue.length !== 6) {
      setError('Please enter the 6 digit OTP.')
      return
    }

    setIsVerifyingOtp(true)
    setAuthStatus('verifying')
    setError('')
    setMessage('')

    try {
      const response = await verifyOtp({
        email: trimmedEmail,
        otp: otpValue,
        purpose: 'login',
      })

      if (!response?.token || !response?.user) {
        setError(response?.message || 'Login response is missing authentication details.')
        setAuthStatus('error')
        return
      }

      saveToken(response.token)
      saveUser(response.user)
      setAuthStatus('verified')
      setMessage(response?.message || 'OTP verified successfully')
      setStep('success')
      transitionTimerRef.current = window.setTimeout(() => {
        navigate('/dashboard', { replace: true })
      }, verifiedDelay)
    } catch (verifyError) {
      setError(getErrorMessage(verifyError, 'Invalid or expired OTP. Please try again.'))
      setAuthStatus('error')
      setOtp(emptyOtp)
      setOtpShakeCount((count) => count + 1)
      window.setTimeout(() => otpRefs.current[0]?.focus(), 80)
    } finally {
      setIsVerifyingOtp(false)
    }
  }

  const handleSubmit = (event) => {
    event.preventDefault()

    if (step === 'details') {
      handleSendOtp()
      return
    }

    if (step === 'otp') {
      handleVerifyOtp()
    }
  }

  const handleOtpChange = (index, value) => {
    const digit = value.replace(/\D/g, '').slice(-1)
    const nextOtp = [...otp]
    nextOtp[index] = digit
    setOtp(nextOtp)
    setError('')
    setMessage('')
    setAuthStatus('idle')

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
    setAuthStatus('idle')

    const focusIndex = Math.min(pastedOtp.length, otpRefs.current.length - 1)
    otpRefs.current[focusIndex]?.focus()
  }

  const handleClose = () => {
    if (isModal) {
      navigate(-1)
      return
    }

    navigate('/home')
  }

  const handleResendOtp = () => {
    if (resendCountdown > 0 || isLoading) return

    handleSendOtp()
  }

  const renderStatusVisual = () => {
    if (authStatus === 'sending') {
      return (
        <div className="auth-status-visual auth-status-visual--sending" aria-hidden="true">
          <span className="auth-mail-trail" />
          <span className="auth-mail-icon">
            <span />
          </span>
        </div>
      )
    }

    if (authStatus === 'sent' || authStatus === 'verified') {
      return (
        <div className="auth-status-visual auth-status-visual--success" aria-hidden="true">
          <span className="auth-check-icon" />
        </div>
      )
    }

    if (authStatus === 'verifying') {
      return (
        <div className="auth-status-visual auth-status-visual--verifying" aria-hidden="true">
          <span className="auth-verify-ring" />
          <span className="auth-shield-icon" />
        </div>
      )
    }

    return null
  }

  return (
    <main className={`auth-page ${isModal ? 'is-modal' : 'is-standalone'}`}>
      <section className={`auth-card auth-state-${authStatus}`} role={isModal ? 'dialog' : undefined} aria-modal={isModal ? 'true' : undefined} aria-label="Login">
        <img src={templeImage} alt="" className="auth-temple" />

        <h1>{heading}</h1>
        <p>{supportingCopy}</p>

        {renderStatusVisual()}

        <div className="auth-benefits" aria-label="Login benefits">
          <span>Private</span>
          <span>No password</span>
          <span>Fast access</span>
        </div>

        <form onSubmit={handleSubmit} className="auth-form">
          {step === 'details' ? (
            <>
              {authMode === 'signup' ? (
                <label>
                  <span className="auth-field-label">Name</span>
                  <input
                    type="text"
                    value={name}
                    disabled={isLoading}
                    onChange={(event) => {
                      setError('')
                      setMessage('')
                      setAuthStatus('idle')
                      setName(event.target.value)
                    }}
                    placeholder="Your name"
                    autoComplete="name"
                    required
                  />
                </label>
              ) : null}

              <label>
                <span className="auth-field-label">Email address</span>
                <input
                  type="email"
                  value={email}
                  disabled={isLoading || step === 'otp'}
                  onChange={(event) => {
                    setError('')
                    setMessage('')
                    setAuthStatus('idle')
                    setEmail(event.target.value)
                  }}
                  placeholder="name@example.com"
                  autoComplete="email"
                  required
                />
              </label>
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
                    onKeyDown={(event) => handleOtpKeyDown(index, event)}
                    onPaste={handleOtpPaste}
                    aria-label={`OTP digit ${index + 1}`}
                    autoComplete={index === 0 ? 'one-time-code' : 'off'}
                  />
                ))}
              </div>
            </div>
          ) : null}

          {step === 'success' ? (
            <div className="auth-success" role="status">
              <img src={ygtCircle} alt="" />
              <strong>OTP verified successfully</strong>
            </div>
          ) : null}

          {error ? <p className="auth-error">{error}</p> : null}
          {message ? <p className="auth-message" role="status">{message}</p> : null}

          {step !== 'success' ? (
            <button type="submit" className="auth-submit" disabled={isLoading}>
              {step === 'details'
                ? isSendingOtp ? 'Sending OTP...' : 'Send OTP'
                : isVerifyingOtp ? 'Verifying OTP...' : 'Verify OTP'}
            </button>
          ) : null}
        </form>

        {step === 'details' ? (
          <p className="auth-switch">
            {authMode === 'login' ? 'Not registered yet?' : 'Already registered?'}
            <button type="button" onClick={() => resetAuthMode(authMode === 'login' ? 'signup' : 'login')}>
              {authMode === 'login' ? 'Sign up' : 'Login'}
            </button>
          </p>
        ) : null}

        {step === 'otp' ? (
          <div className="auth-actions">
            <button
              type="button"
              className="auth-link"
              disabled={isLoading}
              onClick={() => {
                setAuthStatus('idle')
                setStep('details')
              }}
            >
              Change details
            </button>
            <button type="button" className="auth-link" disabled={resendCountdown > 0 || isLoading} onClick={handleResendOtp}>
              {resendCountdown > 0 ? `Resend OTP in ${resendCountdown}s` : 'Resend OTP'}
            </button>
          </div>
        ) : null}

        <button type="button" className="auth-close-link" onClick={handleClose}>
          Close
        </button>
      </section>
    </main>
  )
}

export default LoginPage
