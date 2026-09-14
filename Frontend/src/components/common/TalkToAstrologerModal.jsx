import { useEffect, useState } from 'react'
import { fetchAppointmentConfig, submitAppointmentRequest, uploadAppointmentManualPayment } from '../../services/appointmentService'
import { createAppointmentOrder, loadRazorpayScript, verifyRazorpayPayment } from '../../services/paymentService'
import { isLoggedIn } from '../../utils/auth'
import './TalkToAstrologerModal.css'

const appointmentTimezone = 'Asia/Kolkata'

function formatMoney(amountMinor, currency = 'INR') {
  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency,
    maximumFractionDigits: 2,
  }).format((Number(amountMinor) || 0) / 100)
}

function todayValue() {
  return new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString().slice(0, 10)
}

function halfHourTimeOptions() {
  return Array.from({ length: 48 }, (_, index) => {
    const hour = Math.floor(index / 2)
    const minute = index % 2 === 0 ? '00' : '30'
    const value = `${String(hour).padStart(2, '0')}:${minute}`
    const label = new Date(2000, 0, 1, hour, Number(minute)).toLocaleTimeString('en-IN', {
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
    })

    return { value, label }
  })
}

function TalkToAstrologerModal({ open, onClose, onRequireLogin, onSubmitted }) {
  const [config, setConfig] = useState(null)
  const [step, setStep] = useState('intro')
  const [wizardStep, setWizardStep] = useState('questions')
  const [selectedQuestionIds, setSelectedQuestionIds] = useState([])
  const [contactNumber, setContactNumber] = useState('')
  const [preferredDate, setPreferredDate] = useState(todayValue())
  const [preferredTime, setPreferredTime] = useState('10:00')
  const [status, setStatus] = useState('')
  const [error, setError] = useState('')
  const [confirmation, setConfirmation] = useState(null)
  const [paymentMethod, setPaymentMethod] = useState('payment_gateway')
  const [appointmentLanguageId, setAppointmentLanguageId] = useState('')
  const [manualAppointment, setManualAppointment] = useState(null)
  const [manualProof, setManualProof] = useState(null)
  const signedIn = isLoggedIn()

  useEffect(() => {
    if (!open) return

    setStep('intro')
    setWizardStep('questions')
    setSelectedQuestionIds([])
    setContactNumber('')
    setStatus('Loading consultation details...')
    setError('')
    setPaymentMethod('payment_gateway')
    setAppointmentLanguageId('')
    setManualAppointment(null)
    setManualProof(null)
    fetchAppointmentConfig()
      .then((payload) => {
        setConfig(payload)
        const methods = Array.isArray(payload?.payment_methods) ? payload.payment_methods : []
        const firstMethod = methods[0]?.code || payload?.payment_method || ''
        const languages = Array.isArray(payload?.languages) ? payload.languages : []
        setPaymentMethod(firstMethod === 'gateway' ? 'payment_gateway' : firstMethod)
        setAppointmentLanguageId(languages[0]?.id ? String(languages[0].id) : '')
        setStatus('')
      })
      .catch(() => {
        setError('Consultation details are not available right now.')
        setStatus('')
      })
  }, [open])

  if (!open) return null

  const maximumQuestions = Math.max(1, Number(config?.maximum_questions) || 3)
  const configuredQuestions = Array.isArray(config?.questions) ? config.questions : []
  const availablePaymentMethods = Array.isArray(config?.payment_methods) && config.payment_methods.length
    ? config.payment_methods
    : [{ code: config?.payment_method || 'payment_gateway', name: config?.payment_method === 'qr_online' ? 'Pay through QR / Online' : 'Payment Gateway' }]
  const availableLanguages = Array.isArray(config?.languages) ? config.languages : []
  const isQrOnlinePayment = paymentMethod === 'qr_online'
  const wizardSteps = [
    { id: 'questions', label: 'Questions' },
    { id: 'schedule', label: 'Time' },
    { id: 'payment', label: 'Payment' },
    { id: 'review', label: 'Review' },
  ]
  const activeWizardIndex = Math.max(0, wizardSteps.findIndex((item) => item.id === wizardStep))
  const selectedLanguage = availableLanguages.find((language) => String(language.id) === String(appointmentLanguageId))
  const selectedPaymentMethod = availablePaymentMethods.find((method) => method.code === paymentMethod)
  const preferredTimeOptions = halfHourTimeOptions()

  const toggleQuestion = (questionId) => {
    setSelectedQuestionIds((current) => {
      if (current.includes(questionId)) return current.filter((id) => id !== questionId)
      if (current.length >= maximumQuestions) return current
      return [...current, questionId]
    })
  }

  const goToNextWizardStep = () => {
    setError('')

    if (wizardStep === 'questions' && !selectedQuestionIds.length) {
      setError('Select at least one question.')
      return
    }

    if (wizardStep === 'schedule') {
      if (!appointmentLanguageId) {
        setError('Select the consultation language.')
        return
      }
      if (!preferredDate || !preferredTime) {
        setError('Select preferred date and time.')
        return
      }
      if (!contactNumber.trim()) {
        setError('Enter your contact number.')
        return
      }
    }

    if (wizardStep === 'payment' && !availablePaymentMethods.length) {
      setError('Payment options are currently unavailable. Please try again later.')
      return
    }

    if (wizardStep === 'payment' && isQrOnlinePayment && !manualProof) {
      setError('Upload the payment proof screenshot.')
      return
    }

    const next = wizardSteps[Math.min(activeWizardIndex + 1, wizardSteps.length - 1)]
    setWizardStep(next.id)
  }

  const goToPreviousWizardStep = () => {
    setError('')
    const previous = wizardSteps[Math.max(activeWizardIndex - 1, 0)]
    setWizardStep(previous.id)
  }

  const submit = async (event) => {
    event.preventDefault()
    if (!selectedQuestionIds.length) {
      setError('Select at least one question.')
      return
    }
    if (!appointmentLanguageId) {
      setError('Select the consultation language.')
      return
    }
    if (!contactNumber.trim()) {
      setError('Enter your contact number.')
      return
    }
    if (!availablePaymentMethods.length) {
      setError('Payment options are currently unavailable. Please try again later.')
      return
    }
    if (isQrOnlinePayment && !manualProof) {
      setError('Upload the payment proof screenshot.')
      return
    }

    setStatus('Submitting consultation request...')
    setError('')

    try {
      const payload = isQrOnlinePayment ? new FormData() : {
          appointment_language_id: Number(appointmentLanguageId),
          question_ids: selectedQuestionIds,
          contact_number: contactNumber.trim(),
          preferred_date: preferredDate,
          preferred_time: preferredTime,
          preferred_timezone: appointmentTimezone,
          payment_method: paymentMethod,
        }

      if (isQrOnlinePayment) {
        payload.append('appointment_language_id', appointmentLanguageId)
        selectedQuestionIds.forEach((id) => payload.append('question_ids[]', id))
        payload.append('contact_number', contactNumber.trim())
        payload.append('preferred_date', preferredDate)
        payload.append('preferred_time', preferredTime)
        payload.append('preferred_timezone', appointmentTimezone)
        payload.append('payment_method', paymentMethod)
        if (manualProof) payload.append('payment_screenshot', manualProof)
      }

      const response = await submitAppointmentRequest(payload)
      const appointment = response.data

      if (appointment?.can_upload_manual_payment && !isQrOnlinePayment) {
        setManualAppointment(appointment)
        setStep('manual-payment')
        setStatus('')
        return
      }

      if (appointment?.can_pay) {
        setStatus('Preparing secure payment...')
        const checkout = await createAppointmentOrder(appointment.id)
        const razorpayLoaded = await loadRazorpayScript()

        if (!razorpayLoaded || !window.Razorpay || !checkout?.razorpay_key_id) {
          setError('Secure payment checkout is not configured right now. Please try again later.')
          setStatus('')
          return
        }

        const razorpay = new window.Razorpay({
          key: checkout.razorpay_key_id,
          amount: checkout.appointment.amount_minor,
          currency: checkout.appointment.currency,
          name: 'Your Good Time',
          description: 'Talk to Astrologer',
          order_id: checkout.razorpay_order_id,
          prefill: checkout.prefill || {},
          handler: async (paymentResponse) => {
            setStatus('Confirming payment...')
            setError('')
            try {
              const verification = await verifyRazorpayPayment(paymentResponse)
              if (!verification.appointment_paid) {
                setError('Payment was verified, but appointment access was not updated. Please contact support.')
                setStatus('')
                return
              }
              setConfirmation({ ...appointment, payment_status: 'PAID', status: 'open' })
              setStep('confirmed')
              setStatus('')
              onSubmitted?.(appointment)
            } catch {
              setError('Payment could not be verified. Please contact support if money was deducted.')
              setStatus('')
            }
          },
          modal: {
            ondismiss: () => {
              setError('Payment was not completed. Your request is waiting for payment.')
              setStatus('')
            },
          },
          theme: {
            color: '#ff9d2e',
          },
        })

        razorpay.open()
        setStatus('Complete the payment in the secure checkout.')
        return
      }

      setConfirmation(appointment)
      setStep('confirmed')
      setStatus('')
      onSubmitted?.(appointment)
    } catch (submitError) {
      setError(submitError?.response?.data?.message || 'Unable to submit your consultation request.')
      setStatus('')
    }
  }

  const uploadManualProof = async (event) => {
    event.preventDefault()
    if (!manualAppointment?.id || !manualProof) {
      setError('Upload the payment screenshot to continue.')
      return
    }

    setStatus('Uploading payment screenshot...')
    setError('')

    try {
      const response = await uploadAppointmentManualPayment(manualAppointment.id, manualProof)
      setConfirmation(response.data)
      setStep('confirmed')
      setStatus('')
      onSubmitted?.(response.data)
    } catch (uploadError) {
      setError(uploadError?.response?.data?.message || 'Unable to upload the payment screenshot.')
      setStatus('')
    }
  }

  return (
    <section className="talk-modal-overlay" role="dialog" aria-modal="true" aria-label="Talk to Astrologer">
      <div className="talk-modal">
        <button type="button" className="talk-modal-close" onClick={onClose} aria-label="Close Talk to Astrologer">x</button>

        {step === 'intro' ? (
          <div className="talk-intro">
            <span>Talk to Astrologer</span>
            <h2>Connect directly with our astrologer for personalised guidance.</h2>
            {status ? <p>{status}</p> : null}
            {error ? <p className="talk-error">{error}</p> : null}
            {config ? (
              <>
                <div className="talk-feature-grid">
                  <p>Up to <strong>{config.duration_minutes}</strong> minutes</p>
                  <p>Ask up to <strong>{config.maximum_questions}</strong> questions</p>
                  <p>Online private consultation</p>
                  <p>Appointment confirmed by our team</p>
                <p>Meeting details sent by email</p>
                <p>Online or GPay QR payment</p>
                </div>
                <div className="talk-price">
                  <span>Consultation Fee</span>
                  <strong>{formatMoney(config.amount_minor, config.currency)}</strong>
                  <small>Amount is loaded from backend configuration.</small>
                </div>
              </>
            ) : null}
            {!signedIn ? (
              <div className="talk-auth-note">
                <p>Sign in or register to continue booking this consultation.</p>
                <strong>Register now and receive your Past Prediction absolutely free.</strong>
                <button type="button" onClick={onRequireLogin}>Sign In / Register</button>
              </div>
            ) : (
              <button type="button" className="talk-primary-btn" disabled={Boolean(status) || !config?.available} onClick={() => { setWizardStep('questions'); setStep('form') }}>
                {status ? 'Loading...' : 'Continue to Book Appointment'}
              </button>
            )}
          </div>
        ) : null}

        {step === 'form' ? (
          <form className="talk-form" onSubmit={submit}>
            <div>
              <span>Book Consultation</span>
              <h2>Your preferred time is not yet confirmed.</h2>
              <p>Complete payment to send this request to our admin team.</p>
            </div>
            <div className="talk-wizard-steps" aria-label="Booking progress">
              {wizardSteps.map((item, index) => (
                <span key={item.id} className={index === activeWizardIndex ? 'is-active' : index < activeWizardIndex ? 'is-done' : ''}>{item.label}</span>
              ))}
            </div>

            {wizardStep === 'questions' ? (
              <div className="talk-question-list">
                <div>
                  <span>Select Questions</span>
                  <p>Choose up to {maximumQuestions} topics for your consultation.</p>
                </div>
                <div className="talk-question-table" role="table" aria-label="Consultation questions">
                  <div className="talk-question-row is-head" role="row">
                    <span role="columnheader">Category</span>
                    <span role="columnheader">Question</span>
                    <span role="columnheader">Selected</span>
                  </div>
                  {configuredQuestions.map((item) => {
                    const isSelected = selectedQuestionIds.includes(item.id)
                    const isDisabled = !isSelected && selectedQuestionIds.length >= maximumQuestions

                    return (
                      <div
                        key={item.id}
                        className={isSelected ? 'talk-question-row is-selected' : 'talk-question-row'}
                        role="row"
                      >
                        <strong role="cell">{item.title}</strong>
                        <span role="cell">{item.question}</span>
                        <button
                          type="button"
                          disabled={isDisabled}
                          onClick={() => toggleQuestion(item.id)}
                        >
                          {isSelected ? 'Selected' : 'Select'}
                        </button>
                      </div>
                    )
                  })}
                </div>
                <small>{selectedQuestionIds.length} / {maximumQuestions} selected</small>
              </div>
            ) : null}

            {wizardStep === 'schedule' ? (
              <div className="talk-schedule-row">
                <label>
                  Contact Number
                  <input required value={contactNumber} onChange={(event) => setContactNumber(event.target.value)} placeholder="Your WhatsApp number" />
                </label>
                <label>
                  Language
                  <select required value={appointmentLanguageId} onChange={(event) => setAppointmentLanguageId(event.target.value)}>
                    {availableLanguages.map((language) => <option key={language.id} value={language.id}>{language.name}</option>)}
                  </select>
                </label>
                <label>
                  Preferred Date
                  <input type="date" min={todayValue()} required value={preferredDate} onChange={(event) => setPreferredDate(event.target.value)} />
                </label>
                <label>
                  Preferred Time
                  <select required value={preferredTime} onChange={(event) => setPreferredTime(event.target.value)}>
                    {preferredTimeOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                  </select>
                </label>
                <div className="talk-timezone-chip">
                  <span>Appointment timezone</span>
                  <strong>IST ({appointmentTimezone})</strong>
                </div>
              </div>
            ) : null}

            {wizardStep === 'payment' ? (
              <>
                <div className="talk-payment-methods">
                  <span>Payment Method</span>
                  {availablePaymentMethods.length ? (
                    <div className="future-payment-method-grid">
                      {availablePaymentMethods.map((method) => (
                        <button key={method.code} type="button" className={paymentMethod === method.code ? 'is-selected' : ''} onClick={() => setPaymentMethod(method.code)}>
                          <strong>{method.name}</strong>
                          <span>{method.subtitle || method.instructions}</span>
                        </button>
                      ))}
                    </div>
                  ) : (
                    <strong>Payment options are currently unavailable. Please try again later.</strong>
                  )}
                  {selectedPaymentMethod?.instructions ? <small>{selectedPaymentMethod.instructions}</small> : null}
                </div>
                {isQrOnlinePayment ? (
                  <div className="talk-qr-payment">
                    <div className="talk-qr-box">
                      <span>Scan this QR code to pay</span>
                      {selectedPaymentMethod?.qr_image_url ? <img src={selectedPaymentMethod.qr_image_url} alt="GPay QR code" /> : <strong>No QR code is available right now.</strong>}
                    </div>
                    <div className="talk-manual-proof-grid">
                      <label>
                        Screenshot / Receipt
                        <input type="file" accept="image/png,image/jpeg,image/webp" onChange={(event) => setManualProof(event.target.files?.[0] || null)} />
                      </label>
                      <p>Upload the payment screenshot after scanning the QR code.</p>
                    </div>
                  </div>
                ) : null}
              </>
            ) : null}

            {wizardStep === 'review' ? (
              <div className="talk-review-card">
                <div><span>Questions</span><strong>{selectedQuestionIds.length} selected</strong></div>
                <div><span>Contact</span><strong>{contactNumber || '-'}</strong></div>
                <div><span>Language</span><strong>{selectedLanguage?.name || '-'}</strong></div>
                <div><span>Preferred</span><strong>{preferredDate} {preferredTime}</strong></div>
                <div><span>Timezone</span><strong>IST ({appointmentTimezone})</strong></div>
                <div><span>Payment</span><strong>{selectedPaymentMethod?.name || 'Payment Gateway'}</strong></div>
                <div><span>Amount</span><strong>{formatMoney(config?.amount_minor, config?.currency)}</strong></div>
              </div>
            ) : null}

            {error ? <p className="talk-error">{error}</p> : null}
            {status ? <p>{status}</p> : null}
            <div className="talk-wizard-actions">
              <button type="button" className="talk-secondary-btn" disabled={Boolean(status) || activeWizardIndex === 0} onClick={goToPreviousWizardStep}>Previous</button>
              {wizardStep === 'review' ? (
                <button type="submit" className="talk-primary-btn" disabled={Boolean(status)}>{status ? 'Processing...' : isQrOnlinePayment ? 'Submit Payment for Verification' : `Pay ${formatMoney(config?.amount_minor, config?.currency)}`}</button>
              ) : (
                <button type="button" className="talk-primary-btn" disabled={Boolean(status)} onClick={goToNextWizardStep}>Next</button>
              )}
            </div>
          </form>
        ) : null}

        {step === 'manual-payment' ? (
          <form className="talk-form" onSubmit={uploadManualProof}>
            <div>
              <span>QR / Online Payment Proof</span>
              <h2>Upload your payment proof.</h2>
              <p>Admin will verify the payment proof and move the request to payment received.</p>
            </div>
            <div className="talk-price">
              <span>Request</span>
              <strong>{manualAppointment?.appointment_number}</strong>
            </div>
            <label>
              Screenshot / Receipt
              <input type="file" accept="image/png,image/jpeg,image/webp" required onChange={(event) => setManualProof(event.target.files?.[0] || null)} />
            </label>
            {error ? <p className="talk-error">{error}</p> : null}
            {status ? <p>{status}</p> : null}
            <button type="submit" className="talk-primary-btn" disabled={Boolean(status)}>{status ? 'Uploading...' : 'Upload Payment Proof'}</button>
          </form>
        ) : null}

        {step === 'confirmed' ? (
          <div className="talk-intro">
            <span>Request Received</span>
            <h2>Thank you!</h2>
            <p>
              {confirmation?.payment_method === 'qr_online'
                ? 'Your payment proof has been submitted successfully. Our team will verify the payment and confirm your appointment shortly. You will be able to access it once the verification is completed.'
                : 'Thank you. Your consultation request has been received. Our team will review your preferred date and time and send the confirmed appointment details by email.'}
            </p>
            <div className="talk-price">
              <span>Request</span>
              <strong>{confirmation?.appointment_number}</strong>
            </div>
            <button type="button" className="talk-primary-btn" onClick={onClose}>Close</button>
          </div>
        ) : null}
      </div>
    </section>
  )
}

export default TalkToAstrologerModal
