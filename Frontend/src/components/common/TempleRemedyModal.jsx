import { useEffect, useRef, useState } from 'react'
import { createTempleRemedyOrder, loadRazorpayScript, verifyRazorpayPayment } from '../../services/paymentService'
import { cancelTempleRemedyPayment, fetchTempleRemedyConfig, submitTempleRemedy } from '../../services/templeRemedyService'
import paymentQrImage from '../../assets/payment-qr.png'

function formatMoney(amountMinor, currency = 'INR') {
  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency,
    maximumFractionDigits: 2,
  }).format((Number(amountMinor) || 0) / 100)
}

function TempleRemedyModal({
  initialProblem = '',
  isAuthenticated = false,
  onClose,
  onRequireLogin,
  onSubmitted,
}) {
  const [config, setConfig] = useState(null)
  const [step, setStep] = useState('intro')
  const [selectedOptionCode, setSelectedOptionCode] = useState('')
  const [problem, setProblem] = useState(initialProblem)
  const [contactNumber, setContactNumber] = useState('')
  const [alternateContactNumber, setAlternateContactNumber] = useState('')
  const [preferredLanguage, setPreferredLanguage] = useState('ta')
  const [paymentMethod, setPaymentMethod] = useState('payment_gateway')
  const [manualProof, setManualProof] = useState(null)
  const [confirmation, setConfirmation] = useState(null)
  const [status, setStatus] = useState('')
  const [error, setError] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const submitLockRef = useRef(false)

  useEffect(() => {
    setProblem(initialProblem)
    setStatus('Loading Temple Remedy details...')
    setError('')
    setStep('intro')
    setConfirmation(null)
    setManualProof(null)

    fetchTempleRemedyConfig()
      .then((payload) => {
        setConfig(payload)
        const options = Array.isArray(payload?.options) ? payload.options : []
        const languages = Array.isArray(payload?.languages) ? payload.languages : []
        const methods = Array.isArray(payload?.payment_methods) ? payload.payment_methods : []
        setSelectedOptionCode(options[0]?.code || '')
        setPreferredLanguage(languages[0]?.code || 'ta')
        setPaymentMethod(methods[0]?.code || 'payment_gateway')
        setStatus('')
      })
      .catch(() => {
        setStatus('')
        setError('Temple Remedy details are not available right now.')
      })
  }, [initialProblem])

  const options = Array.isArray(config?.options) ? config.options : []
  const languages = Array.isArray(config?.languages) ? config.languages : []
  const paymentMethods = Array.isArray(config?.payment_methods) ? config.payment_methods : []
  const selectedOption = options.find((item) => item.code === selectedOptionCode)
  const selectedLanguage = languages.find((item) => item.code === preferredLanguage)
  const selectedPaymentMethod = paymentMethods.find((item) => item.code === paymentMethod)
  const isQrPayment = paymentMethod === 'qr_online'
  const steps = ['option', 'contact', 'payment', 'review']
  const activeIndex = Math.max(0, steps.indexOf(step))

  const validateStep = () => {
    if (step === 'option') {
      if (!selectedOptionCode) return 'Select one Temple Remedy option.'
    }

    if (step === 'contact') {
      if (!contactNumber.trim()) return 'Enter your contact number.'
      if (!preferredLanguage) return 'Select your preferred language.'
    }

    if (step === 'payment' && isQrPayment && !manualProof) {
      return 'Upload the payment screenshot to continue.'
    }

    return ''
  }

  const nextStep = () => {
    setError('')
    const validationError = validateStep()
    if (validationError) {
      setError(validationError)
      return
    }

    if (!isAuthenticated) {
      onRequireLogin?.(problem.trim())
      return
    }

    setStep(step === 'intro' ? 'option' : steps[Math.min(activeIndex + 1, steps.length - 1)])
  }

  const previousStep = () => {
    setError('')
    setStep(step === 'option' ? 'intro' : steps[Math.max(activeIndex - 1, 0)])
  }

  const submit = async (event) => {
    event.preventDefault()
    if (submitLockRef.current) return

    const validationError = validateStep()
    if (validationError) {
      setError(validationError)
      return
    }

    if (!isAuthenticated) {
      onRequireLogin?.(problem.trim())
      return
    }

    submitLockRef.current = true
    setIsSubmitting(true)
    setStatus('Submitting Temple Remedy request...')
    setError('')

    try {
      const payload = new FormData()
      payload.append('remedy_option_code', selectedOptionCode)
      payload.append('problem_text', problem.trim())
      payload.append('contact_number', contactNumber.trim())
      if (alternateContactNumber.trim()) payload.append('alternate_contact_number', alternateContactNumber.trim())
      payload.append('preferred_language', preferredLanguage)
      payload.append('payment_method', paymentMethod)
      if (manualProof) payload.append('payment_screenshot', manualProof)

      const response = await submitTempleRemedy(payload)
      const remedy = response.data

      if (isQrPayment) {
        setConfirmation(remedy)
        setStep('confirmed')
        setStatus('')
        onSubmitted?.(remedy)
        return
      }

      setStatus('Preparing secure payment...')
      const checkout = await createTempleRemedyOrder(remedy.id)
      const razorpayLoaded = await loadRazorpayScript()

      if (!razorpayLoaded || !window.Razorpay || !checkout?.razorpay_key_id) {
        setError('Secure payment checkout is not configured right now. Please try again later.')
        setStatus('')
        return
      }

      const razorpay = new window.Razorpay({
        key: checkout.razorpay_key_id,
        amount: checkout.remedy?.amount_minor,
        currency: checkout.remedy?.currency || remedy.currency || 'INR',
        name: 'Your Good Time',
        description: 'Temple Remedy',
        order_id: checkout.razorpay_order_id,
        prefill: checkout.prefill || {},
        handler: async (paymentResponse) => {
          setStatus('Confirming payment...')
          setError('')
          try {
            const verification = await verifyRazorpayPayment(paymentResponse)
            if (!verification.temple_remedy_paid) {
              setError('Payment was verified, but the Temple Remedy request was not updated. Please contact support.')
              setStatus('')
              return
            }
            setConfirmation({ ...remedy, payment_status: 'PAID' })
            setStep('confirmed')
            setStatus('')
            onSubmitted?.(remedy)
          } catch {
            setError('Payment could not be verified. Please contact support if money was deducted.')
            setStatus('')
          }
        },
        modal: {
          ondismiss: () => {
            cancelTempleRemedyPayment(remedy.id).catch(() => {})
            setError('Payment was not completed. This pending request was removed from My Account.')
            setStatus('')
          },
        },
        theme: { color: '#ff9d2e' },
      })

      razorpay.open()
      setStatus('Complete the payment in the secure checkout.')
    } catch (submitError) {
      setError(submitError?.response?.data?.message || 'We could not submit your Temple Remedy request right now.')
      setStatus('')
    } finally {
      submitLockRef.current = false
      setIsSubmitting(false)
    }
  }

  return (
    <section className="temple-remedy-overlay" role="dialog" aria-modal="true" aria-label="Temple Remedy request">
      <form className="temple-remedy-panel is-temple-flow" onSubmit={submit}>
        <button type="button" className="temple-remedy-close" onClick={onClose} aria-label="Close Temple Remedy request">x</button>
        <span className="temple-remedy-kicker">Temple Remedy</span>
        <h2>{step === 'confirmed' ? 'Thank you for your request' : step === 'intro' ? 'Temple Remedy Process' : 'Select Temple Remedy'}</h2>
        {step === 'intro' ? (
          <div className="temple-remedy-summary">
            <p>Select one remedy option, share your contact details, choose Tamil or English, and complete the INR 1000 payment by QR Code or Payment Gateway.</p>
            <div>
              <span>1. Select remedy option</span>
              <span>2. Add contact details</span>
              <span>3. Choose payment method</span>
              <span>4. Submit for admin review</span>
            </div>
          </div>
        ) : step !== 'confirmed' ? <p>Choose one option, share your contact details, and complete payment before submitting.</p> : null}

        {step !== 'intro' && step !== 'confirmed' ? (
          <div className="talk-wizard-steps" aria-label="Temple Remedy progress">
            {steps.map((item, index) => (
              <span key={item} className={index === activeIndex ? 'is-active' : index < activeIndex ? 'is-done' : ''}>{item}</span>
            ))}
          </div>
        ) : null}

        <div className="temple-remedy-step-body">
        {step === 'option' ? (
          <>
            <div className="temple-remedy-option-shell">
              <div className="temple-remedy-option-head">
                <strong>Choose One Option</strong>
                <span>{options.length} available</span>
              </div>
            <div className="talk-question-table temple-remedy-option-table" role="table" aria-label="Temple Remedy options">
              {options.map((item) => (
                <div key={item.code} className={selectedOptionCode === item.code ? 'talk-question-row is-selected' : 'talk-question-row'} role="row">
                  <strong role="cell">{item.title}</strong>
                  <button type="button" onClick={() => setSelectedOptionCode(item.code)}>
                    {selectedOptionCode === item.code ? 'Selected' : 'Select'}
                  </button>
                </div>
              ))}
            </div>
            </div>
            <label>
              Comments / Problem details optional
              <textarea value={problem} maxLength={3000} rows={3} onChange={(event) => setProblem(event.target.value)} placeholder="Optional notes for the admin team" />
            </label>
            <div className="temple-remedy-meta"><span>{problem.trim().length}/3000</span></div>
          </>
        ) : null}

        {step === 'contact' ? (
          <div className="talk-schedule-row">
            <label>
              Contact Number
              <input required value={contactNumber} onChange={(event) => setContactNumber(event.target.value)} placeholder="Your WhatsApp number" />
            </label>
            <label>
              Alternate Contact Number
              <input value={alternateContactNumber} onChange={(event) => setAlternateContactNumber(event.target.value)} placeholder="Optional alternate number" />
            </label>
            <label>
              Language
              <select required value={preferredLanguage} onChange={(event) => setPreferredLanguage(event.target.value)}>
                {languages.map((language) => <option key={language.code} value={language.code}>{language.name}</option>)}
              </select>
            </label>
          </div>
        ) : null}

        {step === 'payment' ? (
          <>
            <div className="talk-price">
              <strong>{formatMoney(config?.amount_minor, config?.currency)}</strong>
              <span>Temple Remedy Fee</span>
            </div>
            <div className="talk-payment-methods">
              <span>Payment Method</span>
              <div>
                {paymentMethods.map((method) => (
                  <button key={method.code} type="button" className={paymentMethod === method.code ? 'is-selected' : ''} onClick={() => setPaymentMethod(method.code)}>
                    {method.name}
                  </button>
                ))}
              </div>
              {selectedPaymentMethod?.instructions ? <small>{selectedPaymentMethod.instructions}</small> : null}
            </div>
            {isQrPayment ? (
              <div className="talk-qr-payment">
                <div className="talk-qr-box">
                  <span>Scan this QR code to pay</span>
                  <img src={paymentQrImage} alt="Payment QR code" />
                </div>
                <div className="talk-manual-proof-grid temple-remedy-proof-grid">
                  <label>
                    Screenshot / Receipt
                    <input type="file" accept="image/*,.pdf" onChange={(event) => setManualProof(event.target.files?.[0] || null)} />
                  </label>
                  <p>Upload the payment screenshot after scanning the QR code.</p>
                </div>
              </div>
            ) : null}
          </>
        ) : null}

        {step === 'review' ? (
          <div className="talk-review-card">
            <div><span>Option</span><strong>{selectedOption?.title || '-'}</strong></div>
            <div><span>Contact</span><strong>{contactNumber || '-'}</strong></div>
            <div><span>Alternate</span><strong>{alternateContactNumber || '-'}</strong></div>
            <div><span>Language</span><strong>{selectedLanguage?.name || '-'}</strong></div>
            <div><span>Payment</span><strong>{selectedPaymentMethod?.name || '-'}</strong></div>
            <div><span>Amount</span><strong>{formatMoney(config?.amount_minor, config?.currency)}</strong></div>
          </div>
        ) : null}

        {step === 'confirmed' ? (
          <div className="temple-remedy-thanks">
            <strong>Thanks, your Temple Remedy request has been received.</strong>
            <p>
              Our admin team will verify your payment and review the selected remedy option. You can check the latest status from My Account.
            </p>
            <span>Request #{confirmation?.id}</span>
            <span>{confirmation?.payment_status === 'PAID' ? 'Payment Received' : 'Payment Pending'}</span>
          </div>
        ) : null}
        </div>

        {status ? <p className="temple-remedy-success">{status}</p> : null}
        {error ? <p className="temple-remedy-error">{error}</p> : null}
        <div className="temple-remedy-actions">
          {step === 'confirmed' ? (
            <button type="button" className="temple-remedy-primary" onClick={onClose}>Done</button>
          ) : step === 'intro' ? (
            <button type="button" className="temple-remedy-primary" disabled={Boolean(status) || !config?.available} onClick={nextStep}>
              {status ? 'Loading...' : isAuthenticated ? 'Proceed' : 'Login and Proceed'}
            </button>
          ) : (
            <>
              <button type="button" className="temple-remedy-secondary" disabled={isSubmitting} onClick={previousStep}>Previous</button>
              {step === 'review' ? (
                <button type="submit" className="temple-remedy-primary" disabled={isSubmitting || Boolean(status)}>
                  {isSubmitting ? 'Processing...' : isQrPayment ? 'Submit Request' : `Pay ${formatMoney(config?.amount_minor, config?.currency)}`}
                </button>
              ) : (
                <button type="button" className="temple-remedy-primary" disabled={isSubmitting || Boolean(status) || !config?.available} onClick={nextStep}>
                  {status ? 'Loading...' : isAuthenticated ? 'Next' : 'Login and Continue'}
                </button>
              )}
            </>
          )}
        </div>
      </form>
    </section>
  )
}

export default TempleRemedyModal
