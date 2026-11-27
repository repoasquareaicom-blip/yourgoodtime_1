import { useEffect, useRef, useState } from 'react'
import { beginFutureQrPayment, downloadFutureQrImage, submitFutureQrPayment } from '../../services/paymentService'
import { paymentSupportNumber, paymentSupportUrl, predictionPaymentText as t } from '../../data/predictionPaymentCopy'
import './FuturePurchaseModal.css'

export default function PredictionQrPayment({ context, initialPayment, product, qrUrl, onClose, onChange }) {
  const [payment, setPayment] = useState(initialPayment)
  const [stage, setStage] = useState(() => {
    if (initialPayment?.status === 'AWAITING_SUBMISSION') return 'payment'
    if (['PENDING', 'VERIFIED'].includes(initialPayment?.status)) return 'submitted'
    return 'instructions'
  })
  const [proof, setProof] = useState(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [showQr, setShowQr] = useState(false)
  const inFlight = useRef(false)
  const dialog = useRef(null)
  const confirmation = useRef(null)
  const closeButton = useRef(null)
  const [resumed] = useState(() => initialPayment?.status === 'AWAITING_SUBMISSION')

  useEffect(() => {
    const element = dialog.current
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    element.showModal()
    return () => {
      element.close()
      document.body.style.overflow = previousOverflow
    }
  }, [])

  useEffect(() => {
    if (confirming) confirmation.current.showModal()
  }, [confirming])

  const changePayment = (current) => {
    setPayment(current)
    setStage(current.status === 'AWAITING_SUBMISSION' ? 'payment' : 'submitted')
    onChange?.(current)
  }

  const run = async (operation) => {
    if (inFlight.current) return
    inFlight.current = true
    setBusy(true)
    setError('')
    try {
      changePayment(await operation())
    } catch {
      setError('error')
    } finally {
      inFlight.current = false
      setBusy(false)
    }
  }

  const close = () => {
    if (inFlight.current) return
    if (stage === 'payment') setConfirming(true)
    else onClose()
  }

  const selectProof = (event) => {
    const file = event.target.files?.[0]
    const invalid = file && (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024)
    setProof(invalid ? null : file)
    setError(invalid ? 'invalidFile' : '')
    if (invalid) event.target.value = ''
  }

  const amount = payment?.amount_minor != null
    ? new Intl.NumberFormat('en-IN', { style: 'currency', currency: payment.currency }).format(payment.amount_minor / 100)
    : product?.display_price
  const productName = payment?.product_name || product?.title || product?.name
  const start = payment?.period?.next_start_year
  const end = payment?.period?.next_end_year
  const title = stage === 'instructions' ? 'title' : stage === 'payment' ? (resumed ? 'resume' : 'qrTitle') : (payment?.status === 'VERIFIED' ? 'verified' : 'thanks')

  return (
    <dialog ref={dialog} className="future-payment-dialog" onCancel={(event) => event.preventDefault()} aria-labelledby="prediction-qr-title" lang="en">
      <div className={`future-payment-content${stage === 'payment' ? ' future-payment-content--checkout' : ''}`}>
        <button ref={closeButton} type="button" className="future-payment-close" onClick={close} disabled={busy} aria-label={t('close')}>×</button>
        <h2 id="prediction-qr-title">{t(title)}</h2>
        {stage === 'instructions' && (
          <>
            <ol>{['scan', 'screenshot', 'share', 'submitStep'].map((key) => <li key={key}>{t(key)}</li>)}</ol>
            <p className="future-payment-warning"><strong>{t('beforeWarning')}</strong></p>
            <p>{t('optional')}</p>
            {error && <p className="future-purchase-error" role="alert">{t(error)}</p>}
            <button type="button" className="future-payment-primary" disabled={busy || !context || !qrUrl} onClick={() => run(() => beginFutureQrPayment(context))}>
              {t(busy ? 'busy' : 'proceed')}
            </button>
          </>
        )}
        {stage === 'payment' && (
          <form className="future-qr-checkout" onSubmit={(event) => {
            event.preventDefault()
            if (error === 'invalidFile') return
            run(async () => (await submitFutureQrPayment({ paymentId: payment.id, paymentProof: proof })).data)
          }}>
            <dl className="future-qr-summary">
              {productName && <div><dt>{t('product')}</dt><dd>{productName}</dd></div>}
              {start && <div><dt>{t('period')}</dt><dd>{start === end ? start : `${start}–${end}`}</dd></div>}
              <div><dt>{t('amount')}</dt><dd>{amount}</dd></div>
            </dl>
            <div className={`future-qr-checkout-body${qrUrl ? ' has-qr' : ''}`}>
              {qrUrl && (
                <div className="future-qr-checkout-code">
                  {resumed && !showQr
                    ? <button type="button" className="future-payment-show-qr" onClick={() => setShowQr(true)}>{t('showQr')}</button>
                    : <DownloadQr qrUrl={qrUrl} />}
                </div>
              )}
              <div className="future-payment-warning">
                <strong>{t('afterTitle')}</strong>
                <p><strong>{t('afterWarning')}</strong></p>
                <p className="future-payment-share">{t('shareAfter')}</p>
                <WhatsAppSupport />
                {resumed && <p>{t('resumeHelp')}</p>}
                <p>{t('activation')}</p>
                <p className="future-payment-missed">{t('missed')}</p>
              </div>
            </div>
            <label className="future-payment-proof">
              <input type="file" accept="image/png,image/jpeg,image/webp" onChange={selectProof} disabled={busy} />
              <strong>{t('upload')}</strong>
              <span>{proof?.name || t('fileHint')}</span>
            </label>
            {error && <p className="future-purchase-error" role="alert">{t(error)}</p>}
            <button className="future-payment-primary" type="submit" disabled={busy || error === 'invalidFile'}>{t(busy ? 'busy' : 'submit')}</button>
          </form>
        )}
        {stage === 'submitted' && (
          <>
            {payment?.status === 'PENDING' && <><p>{t('success')}</p><p>{t('team')}</p></>}
            <p>{t('support')}</p>
            <WhatsAppSupport />
            <button className="future-payment-primary" type="button" onClick={onClose}>{t('close')}</button>
          </>
        )}
      </div>
      {confirming && (
        <dialog ref={confirmation} className="future-payment-confirm" onCancel={(event) => event.preventDefault()} aria-labelledby="prediction-qr-confirm-title">
          <h2 id="prediction-qr-confirm-title">{t('closeTitle')}</h2>
          <p>{t('closeWarning')}</p>
          <p>{t('closeSupport')}</p>
          <WhatsAppSupport />
          <div className="future-purchase-actions">
            <button type="button" onClick={onClose}>{t('closeAnyway')}</button>
            <button type="button" autoFocus onClick={() => {
              confirmation.current.close()
              setConfirming(false)
              closeButton.current.focus()
            }}>{t('continue')}</button>
          </div>
        </dialog>
      )}
    </dialog>
  )
}

function DownloadQr({ qrUrl }) {
  const [downloading, setDownloading] = useState(false)
  const [failed, setFailed] = useState(false)

  const download = async () => {
    setDownloading(true)
    setFailed(false)
    try {
      await downloadFutureQrImage()
    } catch {
      setFailed(true)
    } finally {
      setDownloading(false)
    }
  }

  return (
    <>
      <button type="button" className="future-qr-download" onClick={download} disabled={downloading}>
        <span className="future-qr-payment-box"><img src={qrUrl} alt="" /></span>
        <span>{t(downloading ? 'downloadingQr' : 'downloadQr')}</span>
      </button>
      {failed && <p className="future-purchase-error" role="alert">{t('downloadError')}</p>}
    </>
  )
}

function WhatsAppSupport() {
  return (
    <a className="future-payment-whatsapp" href={paymentSupportUrl} target="_blank" rel="noopener noreferrer">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
        <path d="M20.5 11.8a8.4 8.4 0 0 1-12.6 7.3L4 20l1-3.7a8.4 8.4 0 1 1 15.5-4.5Z" />
        <path d="M9.1 8.2c-.3-.2-.9.1-1 .6-.6 1.9 2.8 5.7 5 6.5.8.3 2.4.1 2.6-.9.1-.5-1.5-1.3-1.9-1.4-.4-.1-.8.7-1.1.7-1.1-.1-2.7-1.7-2.8-2.6 0-.4.5-.7.5-1.1-.1-.4-.9-1.7-1.3-1.8Z" />
      </svg>
      WhatsApp {paymentSupportNumber}
    </a>
  )
}
