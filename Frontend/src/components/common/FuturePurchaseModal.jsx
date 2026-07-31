import { useEffect, useMemo, useRef, useState } from 'react'
import AuthPanel from './AuthPanel'
import AuthenticationLoader from './AuthenticationLoader'
import PredictionPlayer from './PredictionPlayer'
import {
  createFuturePredictionOrder,
  fetchActiveFutureProduct,
  fetchFutureAccess,
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

function FuturePurchaseModal({ onClose, onNeedBirthDetails, onUpdated }) {
  const [stage, setStage] = useState('loadingProduct')
  const [product, setProduct] = useState(null)
  const [error, setError] = useState('')
  const [access, setAccess] = useState(null)
  const [futureResult, setFutureResult] = useState(null)
  const [futurePlaylist, setFuturePlaylist] = useState([])
  const [isPaying, setIsPaying] = useState(false)
  const orderInFlightRef = useRef(false)
  const user = getUser()
  const predictionSession = useMemo(() => loadPredictionSession(), [stage])

  useEffect(() => {
    let isMounted = true

    setStage('loadingProduct')
    fetchActiveFutureProduct()
      .then(async (activeProduct) => {
        if (!isMounted) return
        setProduct(activeProduct)
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

  return (
    <section className="future-purchase-overlay" role="dialog" aria-modal="true" aria-label="Future Prediction purchase">
      <div className="future-purchase-panel">
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
                  <button type="button" disabled={!access?.next_purchase?.can_purchase || isPaying} onClick={startPayment}>Proceed to Secure Payment</button>
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
                <div className="future-purchase-actions">
                  <button type="button" onClick={() => setStage('product')}>Back</button>
                  <button type="button" disabled={isPaying} onClick={startPayment}>Proceed to Secure Payment</button>
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

export default FuturePurchaseModal
