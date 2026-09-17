import { useEffect, useRef, useState } from 'react'

function PredictionCountdown({
  seconds = 2,
  title,
  subtitle,
  details = [],
  paused = false,
  cancelled = false,
  onComplete,
}) {
  const [remaining, setRemaining] = useState(seconds)
  const completedRef = useRef(false)

  useEffect(() => {
    setRemaining(seconds)
    completedRef.current = false
  }, [seconds, title, subtitle])

  useEffect(() => {
    if (paused || cancelled || completedRef.current) return undefined

    if (remaining <= 0) {
      completedRef.current = true
      onComplete?.()
      return undefined
    }

    const timerId = window.setTimeout(() => {
      setRemaining((current) => Math.max(current - 1, 0))
    }, 1000)

    return () => window.clearTimeout(timerId)
  }, [cancelled, onComplete, paused, remaining])

  return (
    <section className="prediction-countdown" aria-live="polite">
      <span className="prediction-countdown__orbit" aria-hidden="true" />
      <span className="prediction-countdown__orbit prediction-countdown__orbit--two" aria-hidden="true" />
      <p className="prediction-countdown__eyebrow">{remaining > 0 ? `Begins in ${remaining}` : 'Beginning now'}</p>
      <strong className="prediction-countdown__number" aria-label={`${remaining} seconds remaining`}>
        {remaining}
      </strong>
      <h2>{title}</h2>
      {subtitle ? <p>{subtitle}</p> : null}
      {details.length ? (
        <dl className="prediction-countdown__details">
          {details.map((item) => (
            <div key={`${item.label}-${item.value}`}>
              <dt>{item.label}</dt>
              <dd>{item.value}</dd>
            </div>
          ))}
        </dl>
      ) : null}
    </section>
  )
}

export default PredictionCountdown
