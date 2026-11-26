import { useEffect, useMemo, useRef, useState } from 'react'
import AuthPanel from './AuthPanel'
import AuthenticationLoader from './AuthenticationLoader'
import PredictionPlayer from './PredictionPlayer'
import {
  createFuturePredictionOrder,
  fetchActiveFutureProduct,
  fetchFutureAccess,
  fetchFuturePaymentSettings,
  fetchFutureQrPaymentStatus,
  loadRazorpayScript,
  submitFutureQrPayment,
  verifyRazorpayPayment,
} from '../../services/paymentService'
import { buildPredictionPlaylist, fetchPredictionResult, fetchStoredPredictionResult } from '../../services/predictionResultService'
import { loadPredictionSession } from '../../services/predictionSessionService'
import { getUser, isLoggedIn } from '../../utils/auth'
import './FuturePurchaseModal.css'

const pendingIntentKey = 'ygt-pending-purchase'

function savePendingFuturePurchase(productId) {
  try {
    window.sessionStorage.setItem(pendingIntentKey, JSON.stringify({
      type: 'FUTURE_PURCHASE',
      product_id: productId,
      return_route: '/home',
      saved_at: new Date().toISOString(),
    }))
  } catch {
    // Purchase can still continue from component state if session storage is unavailable.
  }
}

function clearPendingFuturePurchase() {
  try {
    window.sessionStorage.removeItem(pendingIntentKey)
  } catch {
    // Ignore storage cleanup failures.
  }
}

function FuturePurchaseModal({ onClose, onNeedBirthDetails, onUpdated }) {
  const [stage, setStage] = useState('loadingProduct')
  const [product, setProduct] = useState(null)
  const [error, setError] = useState('')
  const [access, setAccess] = useState(null)
  const [futureResult, setFutureResult] = useState(null)
  const [futurePlaylist, setFuturePlaylist] = useState([])
  const [isPaying, setIsPaying] = useState(false)
  const [paymentSettings, setPaymentSettings] = useState(null)
  const [manualPayment, setManualPayment] = useState(null)
  const [selectedPaymentMethod, setSelectedPaymentMethod] = useState('')
  const [paymentProof, setPaymentProof] = useState(null)
  const orderInFlightRef = useRef(false)
  const user = getUser()
  const predictionSession = useMemo(() => loadPredictionSession(), [stage])

  useEffect(() => {
    let isMounted = true

    setStage('loadingProduct')
    Promise.all([fetchActiveFutureProduct(), fetchFuturePaymentSettings()])
      .then(async ([activeProduct, settings]) => {
        if (!isMounted) return
        setProduct(activeProduct)
        setPaymentSettings(settings)
        setSelectedPaymentMethod(defaultFuturePaymentMethod(settings))
        if (!activeProduct) {
          setStage('product')
          setError('Future Prediction is not available right now.')
          return
        }

        if (isLoggedIn()) {
          await loadAccess(activeProduct)
          return
        }

        setStage('product')
      })
      .catch(() => {
        if (!isMounted) return
        setStage('product')
        setError('We could not load the future prediction offer right now.')
      })

    return () => {
      isMounted = false
    }
    // Initial load intentionally hydrates product and current access once.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const closeFlow = () => {
    clearPendingFuturePurchase()
    onClose?.()
  }

  const loadAccess = async (productOverride = null) => {
    const session = loadPredictionSession()

    if (!session?.predictionRequestId || !session?.requestUuid) {
      savePendingFuturePurchase((productOverride || product)?.product_id)
      setStage('birthDetails')
      return null
    }

    setStage('checkingAccess')
    setError('')

    try {
      const nextAccess = await fetchFutureAccess({
        predictionRequestId: session.predictionRequestId,
        requestUuid: session.requestUuid,
      })
      setAccess(nextAccess)
      setProduct(nextAccess?.product || productOverride || product)
      await loadManualPaymentStatus(session, nextAccess?.product || productOverride || product)

      if (nextAccess?.has_purchased_future || nextAccess?.future_prediction_enabled) {
        setStage('accessSummary')
        return nextAccess
      }

      setStage('checkout')
      return nextAccess
    } catch (accessError) {
      setStage('product')
      setError(accessError?.response?.data?.message || 'We could not check your future access right now.')
      return null
    }
  }

  const proceed = async () => {
    if (!product || isPaying) return

    savePendingFuturePurchase(product.product_id)

    if (!isLoggedIn()) {
      setStage('auth')
      return
    }

    await loadAccess()
  }

  const prepareFuturePrediction = async () => {
    const session = loadPredictionSession()
    if (!session?.predictionRequestId || !session?.requestUuid) {
      setStage('birthDetails')
      return
    }

    setStage('preparingFuture')
    setError('')

    try {
      const result = await fetchPredictionResult({
        predictionRequestId: session.predictionRequestId,
        requestUuid: session.requestUuid,
        type: 'future',
      })
      const playlist = buildPredictionPlaylist(result, 'future')
      setFutureResult(result)
      setFuturePlaylist(playlist)
      setStage('ready')
      onUpdated?.()
      clearPendingFuturePurchase()
    } catch {
      setStage('existingReady')
      setError('We could not prepare your future prediction right now. Please try again.')
    }
  }

  useEffect(() => {
    if (stage === 'preparingFuture') {
      prepareFuturePrediction()
    }
    // stage intentionally drives idempotent generation/loading.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [stage])

  const openExistingFuture = async () => {
    const session = loadPredictionSession()
    if (!session?.predictionRequestId || !session?.requestUuid) {
      setStage('birthDetails')
      return
    }

    setStage('loadingFuture')
    setError('')

    try {
      const result = await fetchStoredPredictionResult({
        predictionRequestId: session.predictionRequestId,
        requestUuid: session.requestUuid,
        type: 'future',
      })
      const playlist = buildPredictionPlaylist(result, 'future')
      setFutureResult(result)
      setFuturePlaylist(playlist)
      setStage('playing')
    } catch {
      await prepareFuturePrediction()
    }
  }

  const startPayment = async () => {
    if (orderInFlightRef.current || isPaying || !product) return

    const session = loadPredictionSession()
    if (!session?.predictionRequestId || !session?.requestUuid) {
      setStage('birthDetails')
      return
    }

    orderInFlightRef.current = true
    setIsPaying(true)
    setStage('creatingOrder')
    setError('')

    try {
      const checkout = await createFuturePredictionOrder({
        predictionRequestId: session.predictionRequestId,
        requestUuid: session.requestUuid,
        productId: product.product_id,
      })
      setProduct(checkout.product || product)

      const razorpayLoaded = await loadRazorpayScript()
      if (!razorpayLoaded || !window.Razorpay || !checkout.razorpay_key_id) {
        setStage('checkout')
        setError('Secure payment checkout is not configured in this environment.')
        return
      }

      const prefill = {
        ...(checkout.prefill || {}),
        ...(user?.phone_e164 ? { contact: user.phone_e164 } : {}),
      }

      const razorpay = new window.Razorpay({
        key: checkout.razorpay_key_id,
        amount: checkout.product.amount_minor,
        currency: checkout.product.currency,
        name: 'Your Good Time',
        description: checkout.product.name,
        order_id: checkout.razorpay_order_id,
        prefill,
        handler: async (response) => {
          setStage('verifyingPayment')
          setError('')
          try {
            const verification = await verifyRazorpayPayment(response)
            if (!verification.future_prediction_enabled) {
              setStage('checkout')
              setError('Payment was verified, but future access was not activated. Please contact support.')
              return
            }
            setAccess((current) => ({ ...(current || {}), entitlement: verification.entitlement, future_prediction_enabled: true }))
            await prepareFuturePrediction()
          } catch {
            setStage('checkout')
            setError('Payment could not be verified. No future access has been activated.')
          }
        },
        modal: {
          ondismiss: () => {
            setStage('checkout')
            setError('Payment was not completed. You can try again whenever you are ready.')
          },
        },
        theme: {
          color: '#ff9d2e',
        },
      })

      razorpay.open()
      setStage('checkoutOpen')
    } catch (paymentError) {
      if (paymentError?.response?.status === 409) {
        await loadAccess()
        return
      }
      setStage('checkout')
      setError(paymentError?.response?.data?.message || 'Payment could not be started. Please try again.')
    } finally {
      orderInFlightRef.current = false
      setIsPaying(false)
    }
  }

  const loadManualPaymentStatus = async (session, productOverride = null) => {
    const currentProduct = productOverride || product
    if (!session?.predictionRequestId || !session?.requestUuid || !currentProduct?.product_id) {
      setManualPayment(null)
      return null
    }

    try {
      const statusPayload = await fetchFutureQrPaymentStatus({
        predictionRequestId: session.predictionRequestId,
        requestUuid: session.requestUuid,
        productId: currentProduct.product_id,
      })
      setManualPayment(statusPayload)
      return statusPayload
    } catch {
      setManualPayment(null)
      return null
    }
  }

  const continueToPayment = () => {
    if (isPaying) return

    const availability = futurePaymentAvailability(paymentSettings)
    setError('')

    if (!availability.hasAny) {
      setError('Payment options are currently unavailable. Please try again later.')
      return
    }

    if (availability.gateway && !availability.qr) {
      startPayment()
      return
    }

    if (!availability.gateway && availability.qr) {
      if (manualPayment?.status === 'PENDING' || manualPayment?.status === 'VERIFIED') {
        setStage('qrSubmitted')
        return
      }
      setSelectedPaymentMethod('gpay_qr')
      setStage('qrPayment')
      return
    }

    setSelectedPaymentMethod(selectedPaymentMethod || 'payment_gateway')
    setStage('paymentMethod')
  }

  const submitQrPayment = async (event) => {
    event.preventDefault()

    const session = loadPredictionSession()
    if (!session?.predictionRequestId || !session?.requestUuid) {
      setStage('birthDetails')
      return
    }

    if (!activeProduct?.product_id) {
      setError('Future prediction checkout is not available right now.')
      return
    }

    if (!paymentProof) {
      setError('Upload the payment proof screenshot.')
      return
    }

    setIsPaying(true)
    setError('')

    try {
      const response = await submitFutureQrPayment({
        predictionRequestId: session.predictionRequestId,
        requestUuid: session.requestUuid,
        productId: activeProduct.product_id,
        paymentProof,
      })
      setManualPayment(response.data)
      setPaymentProof(null)
      setStage('qrSubmitted')
      setError('')
    } catch (qrError) {
      const existingPayment = qrError?.response?.data?.data
      if (existingPayment) setManualPayment(existingPayment)
      setError(qrError?.response?.data?.message || 'Unable to submit payment proof.')
    } finally {
      setIsPaying(false)
    }
  }

  const handleAuthenticated = async () => {
    await loadAccess()
  }

  if (stage === 'playing') {
    return (
      <PredictionPlayer
        playlist={futurePlaylist}
        onClose={() => setStage('ready')}
        completionTitle="Your Future Reading Is Complete"
        predictionRequestAccess={{
          predictionRequestId: predictionSession?.predictionRequestId,
          requestUuid: predictionSession?.requestUuid,
        }}
        predictionResultId={futureResult?.result_id}
        predictionType="future"
        onComplete={() => setStage('ready')}
      />
    )
  }

  const activeProduct = access?.product || product
  const isBusy = ['loadingProduct', 'checkingAccess', 'creatingOrder', 'verifyingPayment', 'preparingFuture', 'loadingFuture', 'checkoutOpen'].includes(stage)
  const paymentAvailability = futurePaymentAvailability(paymentSettings)

  const closeIfBackdrop = (event) => {
    if (event.target === event.currentTarget) onClose?.()
  }

  return (
    <section
      className="future-purchase-overlay"
      role="dialog"
      aria-modal="true"
      aria-label="Future Prediction purchase"
      onPointerDown={closeIfBackdrop}
      onClick={closeIfBackdrop}
    >
      <div
        className="future-purchase-panel"
        onPointerDown={(event) => event.stopPropagation()}
        onClick={(event) => event.stopPropagation()}
      >
        {stage === 'auth' ? (
          <AuthPanel initialMode="register" onAuthenticated={handleAuthenticated} onClose={() => setStage('product')} />
        ) : null}

        {stage !== 'auth' ? (
          <>
            {isBusy ? (
              <AuthenticationLoader
                compact
                title={stage === 'loadingProduct' ? 'Loading future offer...' : stage === 'checkingAccess' ? 'Checking your future access...' : stage === 'creatingOrder' ? 'Preparing secure payment...' : stage === 'verifyingPayment' ? 'Confirming your payment...' : stage === 'loadingFuture' ? 'Opening your future prediction...' : 'Preparing your future prediction...'}
                message="Please wait while we prepare your experience."
              />
            ) : null}

            {!isBusy && stage === 'product' ? (
              <>
                <h2>Know Your Future</h2>
                <p>Discover your personalised future journey from next year onwards.</p>
                {activeProduct ? (
                  <div className="future-product-summary">
                    <span>Product</span>
                    <strong>{activeProduct.title || activeProduct.name}</strong>
                    <span>Duration</span>
                    <strong>{activeProduct.duration_label}</strong>
                    <span>Price</span>
                    <strong>{activeProduct.display_price}</strong>
                    <span>Currency</span>
                    <strong>{activeProduct.currency}</strong>
                    {activeProduct.description ? (
                      <>
                        <span>Description</span>
                        <strong>{activeProduct.description}</strong>
                      </>
                    ) : null}
                  </div>
                ) : null}
                {error ? <p className="future-purchase-error">{error}</p> : null}
                <div className="future-purchase-actions">
                  <button type="button" onClick={closeFlow}>Not Now</button>
                  <button type="button" disabled={!activeProduct} onClick={proceed}>Create Account</button>
                </div>
              </>
            ) : null}

            {!isBusy && stage === 'birthDetails' ? (
              <>
                <h2>Complete Your Birth Details</h2>
                <p>We need your saved birth details before preparing your future prediction.</p>
                {error ? <p className="future-purchase-error">{error}</p> : null}
                <div className="future-purchase-actions">
                  <button type="button" onClick={() => setStage('product')}>Back</button>
                  <button type="button" onClick={onNeedBirthDetails}>Complete Details</button>
                </div>
              </>
            ) : null}

            {!isBusy && stage === 'accessSummary' ? (
              <>
                <h2>Purchased Future Predictions</h2>
                <p>Your purchased future periods remain available. You can watch them again or buy the next available period.</p>
                {access?.purchased_blocks?.length ? (
                  <div className="future-product-summary">
                    {access.purchased_blocks.map((block) => (
                      <div className="future-purchased-block" key={block.entitlement_id}>
                        <strong>{block.start_year === block.end_year ? block.start_year : `${block.start_year}-${block.end_year}`}</strong>
                        <span>{block.start_age === block.end_age ? `Age ${block.start_age}` : `Ages ${block.start_age}-${block.end_age}`}</span>
                      </div>
                    ))}
                  </div>
                ) : null}
                {access?.next_purchase?.can_purchase ? (
                  <p className="future-next-copy">
                    {access.next_purchase.start_year === access.next_purchase.end_year
                      ? `Unlock Your Prediction for ${access.next_purchase.start_year}`
                      : `Unlock Your Future Prediction: ${access.next_purchase.start_year}-${access.next_purchase.end_year}`}
                  </p>
                ) : (
                  <p className="future-next-copy">{access?.next_purchase?.message || 'No new supported future years are available right now.'}</p>
                )}
                {access?.next_purchase?.can_purchase && activeProduct ? (
                  <div className="future-product-summary">
                    <span>Next available period</span>
                    <strong>
                      {access.next_purchase.start_year === access.next_purchase.end_year
                        ? `${access.next_purchase.start_year} (Age ${access.next_purchase.start_age})`
                        : `${access.next_purchase.start_year}-${access.next_purchase.end_year} (Ages ${access.next_purchase.start_age}-${access.next_purchase.end_age})`}
                    </strong>
                    <span>Total payable</span>
                    <strong>{activeProduct.display_price}</strong>
                  </div>
                ) : null}
                {error ? <p className="future-purchase-error">{error}</p> : null}
                <div className="future-purchase-actions">
                  <button type="button" onClick={closeFlow}>Not Now</button>
                  <button type="button" onClick={openExistingFuture}>Watch Purchased Future</button>
                  <button type="button" disabled={!access?.next_purchase?.can_purchase || isPaying} onClick={continueToPayment}>Proceed to Payment</button>
                </div>
              </>
            ) : null}

            {!isBusy && stage === 'checkout' ? (
              <>
                <h2>Future Prediction</h2>
                <div className="future-product-summary">
                  <span>Product</span>
                  <strong>{activeProduct?.title || activeProduct?.name || 'Future Prediction'}</strong>
                  <span>Prediction period</span>
                  <strong>
                    {access?.next_purchase?.start_year
                      ? access.next_purchase.start_year === access.next_purchase.end_year
                        ? `${access.next_purchase.start_year} (Age ${access.next_purchase.start_age})`
                        : `${access.next_purchase.start_year}-${access.next_purchase.end_year} (Ages ${access.next_purchase.start_age}-${access.next_purchase.end_age})`
                      : activeProduct?.duration_label || 'Configured future duration'}
                  </strong>
                  <span>Total payable</span>
                  <strong>{activeProduct?.display_price || 'Loading...'}</strong>
                </div>
                {error ? <p className="future-purchase-error">{error}</p> : null}
                {manualPayment ? <FutureManualPaymentStatus payment={manualPayment} /> : null}
                <div className="future-purchase-actions">
                  <button type="button" onClick={() => setStage('product')}>Back</button>
                  <button type="button" disabled={isPaying} onClick={continueToPayment}>Proceed to Payment</button>
                </div>
              </>
            ) : null}

            {!isBusy && stage === 'paymentMethod' ? (
              <>
                <h2>Choose Payment Method</h2>
                <p>Select how you would like to pay for your Future Prediction.</p>
                <div className="future-payment-method-grid">
                  {paymentAvailability.gateway ? (
                    <button type="button" className={selectedPaymentMethod === 'payment_gateway' ? 'is-selected' : ''} onClick={() => setSelectedPaymentMethod('payment_gateway')}>
                      <strong>Pay Online</strong>
                      <span>Existing Payment Gateway</span>
                    </button>
                  ) : null}
                  {paymentAvailability.qr ? (
                    <button type="button" className={selectedPaymentMethod === 'gpay_qr' ? 'is-selected' : ''} onClick={() => setSelectedPaymentMethod('gpay_qr')}>
                      <strong>Pay via GPay QR</strong>
                      <span>Scan QR and submit proof</span>
                    </button>
                  ) : null}
                </div>
                {manualPayment ? <FutureManualPaymentStatus payment={manualPayment} /> : null}
                {error ? <p className="future-purchase-error">{error}</p> : null}
                <div className="future-purchase-actions">
                  <button type="button" onClick={() => setStage('checkout')}>Back</button>
                  <button
                    type="button"
                    disabled={isPaying || !selectedPaymentMethod}
                    onClick={() => {
                      if (selectedPaymentMethod === 'payment_gateway') {
                        startPayment()
                        return
                      }
                      if (manualPayment?.status === 'PENDING' || manualPayment?.status === 'VERIFIED') {
                        setStage('qrSubmitted')
                        return
                      }
                      setStage('qrPayment')
                    }}
                  >
                    Continue
                  </button>
                </div>
              </>
            ) : null}

            {!isBusy && stage === 'qrPayment' ? (
              <form onSubmit={submitQrPayment}>
                <h2>Pay via GPay QR</h2>
                <p>Scan the QR code and complete the payment using GPay or any supported UPI app.</p>
                <div className="future-product-summary">
                  <span>Product</span>
                  <strong>{activeProduct?.title || activeProduct?.name || 'Future Prediction'}</strong>
                  <span>Total payable</span>
                  <strong>{activeProduct?.display_price || 'Loading...'}</strong>
                </div>
                <div className="future-qr-payment-box">
                  {paymentSettings?.qr_image_url ? (
                    <img src={paymentSettings.qr_image_url} alt="Future Video GPay QR code" />
                  ) : (
                    <span>No QR code is available right now.</span>
                  )}
                </div>
                {manualPayment?.status === 'REJECTED' ? <FutureManualPaymentStatus payment={manualPayment} /> : null}
                <label className="future-payment-proof">
                  <input type="file" accept="image/png,image/jpeg,image/webp" onChange={(event) => setPaymentProof(event.target.files?.[0] || null)} />
                  <strong>{paymentProof ? paymentProof.name : 'Upload payment proof screenshot'}</strong>
                  <span>PNG, JPG, or WEBP up to 5 MB</span>
                </label>
                {error ? <p className="future-purchase-error">{error}</p> : null}
                <div className="future-purchase-actions">
                  <button type="button" onClick={() => setStage(paymentAvailability.gateway ? 'paymentMethod' : 'checkout')}>Back</button>
                  <button type="submit" disabled={isPaying || !paymentSettings?.qr_image_url}>
                    {isPaying ? 'Submitting...' : 'Submit Payment for Verification'}
                  </button>
                </div>
              </form>
            ) : null}

            {!isBusy && stage === 'qrSubmitted' ? (
              <>
                {manualPayment?.status === 'REJECTED' ? (
                  <>
                    <h2>Payment could not be verified.</h2>
                    {manualPayment?.rejection_reason ? <p>{manualPayment.rejection_reason}</p> : <p>Please upload a new payment proof screenshot.</p>}
                  </>
                ) : (
                  <>
                    <h2>Thank you!</h2>
                    <p>Your payment proof has been submitted successfully. Our team will verify the payment and enable your Future Video shortly. You will be able to access it from your account once the verification is completed.</p>
                  </>
                )}
                {error ? <p className="future-purchase-error">{error}</p> : null}
                <div className="future-purchase-actions">
                  <button type="button" onClick={closeFlow}>Close</button>
                  {manualPayment?.status === 'REJECTED' ? <button type="button" onClick={() => setStage('qrPayment')}>Submit Again</button> : null}
                </div>
              </>
            ) : null}

            {!isBusy && stage === 'existingReady' ? (
              <>
                <h2>Your Future Prediction Is Available</h2>
                <p>You already have access to your future prediction.</p>
                {error ? <p className="future-purchase-error">{error}</p> : null}
                <div className="future-purchase-actions">
                  <button type="button" onClick={closeFlow}>Later</button>
                  <button type="button" onClick={access?.future_result_ready ? openExistingFuture : prepareFuturePrediction}>
                    Continue to Future
                  </button>
                </div>
              </>
            ) : null}

            {!isBusy && stage === 'ready' ? (
              <>
                <h2>Your Future Prediction Is Ready</h2>
                <p>Your personalised future journey has been prepared.</p>
                <div className="future-purchase-actions">
                  <button type="button" onClick={closeFlow}>Later</button>
                  <button type="button" onClick={() => setStage('playing')}>Watch Future Prediction</button>
                </div>
              </>
            ) : null}
          </>
        ) : null}
      </div>
    </section>
  )
}

function futurePaymentAvailability(settings) {
  return {
    gateway: Boolean(settings?.payment_gateway_enabled),
    qr: Boolean(settings?.gpay_qr_enabled && settings?.qr_image_url),
    hasAny: Boolean(settings?.payment_gateway_enabled || (settings?.gpay_qr_enabled && settings?.qr_image_url)),
  }
}

function defaultFuturePaymentMethod(settings) {
  const availability = futurePaymentAvailability(settings)

  if (availability.gateway) return 'payment_gateway'
  if (availability.qr) return 'gpay_qr'

  return ''
}

function FutureManualPaymentStatus({ payment }) {
  const status = payment?.status || 'PENDING'
  const label = status === 'PENDING'
    ? 'Pending Verification'
    : status === 'VERIFIED'
      ? 'Verified'
      : status === 'REJECTED'
        ? 'Rejected'
        : payment?.status_label || status

  return (
    <div className={`future-manual-payment-status is-${status.toLowerCase()}`}>
      <strong>{label}</strong>
      {payment?.submitted_at ? <span>Submitted: {new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(payment.submitted_at))}</span> : null}
      {status === 'REJECTED' && payment?.rejection_reason ? <p>{payment.rejection_reason}</p> : null}
    </div>
  )
}

export default FuturePurchaseModal
