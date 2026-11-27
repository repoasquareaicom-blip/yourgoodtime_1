import { useEffect, useMemo, useRef, useState } from 'react'
import AuthPanel from './AuthPanel'
import AuthenticationLoader from './AuthenticationLoader'
import PredictionPlayer from './PredictionPlayer'
import PredictionQrPayment from './PredictionQrPayment'
import { predictionPaymentText as t } from '../../data/predictionPaymentCopy'
import {
  createFuturePredictionOrder,
  fetchActiveFutureProduct,
  fetchFutureAccess,
  fetchFuturePaymentSettings,
  fetchFutureQrPaymentStatus,
  loadRazorpayScript,
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

function FuturePurchaseModal({ onClose, onNeedBirthDetails, onUpdated, resumePayment }) {
  const [stage, setStage] = useState(resumePayment ? 'qrPayment' : 'loadingProduct')
  const [product, setProduct] = useState(null)
  const [error, setError] = useState('')
  const [access, setAccess] = useState(null)
  const [futureResult, setFutureResult] = useState(null)
  const [futurePlaylist, setFuturePlaylist] = useState([])
  const [isPaying, setIsPaying] = useState(false)
  const [paymentSettings, setPaymentSettings] = useState(null)
  const [manualPayment, setManualPayment] = useState(null)
  const [selectedPaymentMethod, setSelectedPaymentMethod] = useState('')
  const orderInFlightRef = useRef(false)
  const user = getUser()
  const predictionSession = useMemo(() => loadPredictionSession(), [stage])

  useEffect(() => {
    if (resumePayment) {
      fetchFuturePaymentSettings().then(setPaymentSettings).catch(() => {})
      return
    }

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

  useEffect(() => {
    if (stage === 'qrPayment' || stage === 'playing') return
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => { document.body.style.overflow = previousOverflow }
  }, [stage])

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
            onUpdated?.()
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

    if (manualPayment?.status === 'AWAITING_SUBMISSION' || manualPayment?.status === 'PENDING') {
      setStage('qrPayment')
      return
    }

    if (!availability.hasAny) {
      setError('Payment options are currently unavailable. Please try again later.')
      return
    }

    if (availability.gateway && !availability.qr) {
      startPayment()
      return
    }

    if (!availability.gateway && availability.qr) {
      setSelectedPaymentMethod('gpay_qr')
      setStage('qrPayment')
      return
    }

    setSelectedPaymentMethod(selectedPaymentMethod || 'payment_gateway')
    setStage('paymentMethod')
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
  const nextPurchase = access?.next_purchase
  const isAccessSummary = stage === 'accessSummary'
  const isBusy = ['loadingProduct', 'checkingAccess', 'creatingOrder', 'verifyingPayment', 'preparingFuture', 'loadingFuture', 'checkoutOpen'].includes(stage)
  const paymentAvailability = futurePaymentAvailability(paymentSettings)

  if (stage === 'qrPayment') {
    return (
      <PredictionQrPayment
        context={predictionSession && activeProduct?.product_id ? { ...predictionSession, productId: activeProduct.product_id } : null}
        initialPayment={resumePayment || manualPayment}
        product={activeProduct}
        qrUrl={paymentSettings?.gpay_qr_enabled ? paymentSettings.qr_image_url : null}
        onClose={closeFlow}
        onChange={(payment) => {
          setManualPayment(payment)
          onUpdated?.(payment)
        }}
      />
    )
  }

  return (
    <section
      className="future-purchase-overlay future-purchase-fullscreen"
      role="dialog"
      aria-modal="true"
      aria-label={isAccessSummary ? 'Purchased Future Predictions' : 'Future Prediction purchase'}
      onKeyDown={(event) => {
        if (event.key === 'Escape') {
          event.preventDefault()
          event.stopPropagation()
        }
      }}
    >
      <div className={isAccessSummary ? 'future-purchase-panel future-access-panel' : 'future-purchase-panel'}>
        <button type="button" className="future-payment-close" onClick={closeFlow} aria-label={t('close')}>?</button>
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

            {isAccessSummary ? (
              <>
                <h2>Purchased Future Predictions</h2>
                {access?.purchased_blocks?.length ? (
                  <ul className="future-purchased-list" aria-label="Purchased periods" role="list">
                    {access.purchased_blocks.map((block) => (
                      <li className="future-purchased-block" key={block.entitlement_id}>
                        <strong>{block.start_year === block.end_year ? block.start_year : `${block.start_year}-${block.end_year}`}</strong>
                        <span>{block.start_age === block.end_age ? `Age ${block.start_age}` : `Ages ${block.start_age}-${block.end_age}`}</span>
                      </li>
                    ))}
                  </ul>
                ) : null}
                {nextPurchase?.can_purchase ? (
                  <div className="future-next-period">
                    <div>
                      <span>Next period</span>
                      <strong>
                        {nextPurchase.start_year === nextPurchase.end_year
                          ? `${nextPurchase.start_year} (Age ${nextPurchase.start_age})`
                          : `${nextPurchase.start_year}-${nextPurchase.end_year} (Ages ${nextPurchase.start_age}-${nextPurchase.end_age})`}
                      </strong>
                    </div>
                    {activeProduct?.display_price ? (
                      <div>
                        <span>Price</span>
                        <strong>{activeProduct.display_price}</strong>
                      </div>
                    ) : null}
                  </div>
                ) : (
                  <p>{nextPurchase?.message || 'No new supported future years are available right now.'}</p>
                )}
                {error ? <p className="future-purchase-error">{error}</p> : null}
                <div className="future-purchase-actions">
                  <button type="button" onClick={closeFlow}>Close</button>
                  {access?.future_result_ready && <button type="button" aria-label="Watch purchased predictions" onClick={openExistingFuture}>Watch</button>}
                  {nextPurchase?.can_purchase ? (
                    <button type="button" disabled={isPaying} onClick={continueToPayment}>Buy Next</button>
                  ) : null}
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
                      <span>{t('qrOption')}</span>
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
                      setStage('qrPayment')
                    }}
                  >
                    Continue
                  </button>
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
    ? t('pending')
    : status === 'VERIFIED'
      ? t('verified')
      : status === 'REJECTED'
        ? 'Rejected'
        : status === 'AWAITING_SUBMISSION' ? t('resume') : payment?.status_label || status

  return (
    <div className={`future-manual-payment-status is-${status.toLowerCase()}`}>
      <strong>{label}</strong>
      {payment?.submitted_at ? <span>Submitted: {new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(payment.submitted_at))}</span> : null}
      {status === 'REJECTED' && payment?.rejection_reason ? <p>{payment.rejection_reason}</p> : null}
    </div>
  )
}

export default FuturePurchaseModal
