import { useEffect, useState } from 'react'
import { submitTempleRemedy } from '../../services/templeRemedyService'

function TempleRemedyModal({
  initialProblem = '',
  isAuthenticated = false,
  onClose,
  onRequireLogin,
  onSubmitted,
}) {
  const [problem, setProblem] = useState(initialProblem)
  const [status, setStatus] = useState('')
  const [error, setError] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)

  useEffect(() => {
    setProblem(initialProblem)
    setStatus('')
    setError('')
  }, [initialProblem])

  const handleSubmit = async (event) => {
    event.preventDefault()
    setStatus('')
    setError('')

    const problemText = problem.trim()

    if (problemText.length < 20) {
      setError('Please explain the problem in at least 20 characters.')
      return
    }

    if (!isAuthenticated) {
      onRequireLogin?.(problemText)
      return
    }

    setIsSubmitting(true)

    try {
      const response = await submitTempleRemedy({ problem_text: problemText })
      setStatus(response?.message || 'Your Temple Remedy request has been submitted successfully.')
      setProblem('')
      onSubmitted?.(response?.data)
    } catch (submitError) {
      setError(submitError?.response?.data?.message || 'We could not submit your Temple Remedy request right now.')
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <section className="temple-remedy-overlay" role="dialog" aria-modal="true" aria-label="Temple Remedy request">
      <form className="temple-remedy-panel" onSubmit={handleSubmit}>
        <button
          type="button"
          className="temple-remedy-close"
          onClick={onClose}
          aria-label="Close Temple Remedy request"
        >
          x
        </button>
        <span className="temple-remedy-kicker">Temple Remedy</span>
        <h2>Tell us the problem you need help solving</h2>
        <p>
          Describe the concern clearly, including what is happening, how long it has continued, and what kind of guidance you are expecting.
        </p>
        <label>
          Problem details
          <textarea
            value={problem}
            minLength={20}
            maxLength={3000}
            rows={7}
            placeholder="Example: I am facing repeated delays in career decisions and family pressure. Please suggest a suitable temple remedy..."
            onChange={(event) => setProblem(event.target.value)}
          />
        </label>
        <div className="temple-remedy-meta">
          <span>{problem.trim().length}/3000</span>
          {!isAuthenticated ? <strong>Login required to submit</strong> : null}
        </div>
        {status ? <p className="temple-remedy-success">{status}</p> : null}
        {error ? <p className="temple-remedy-error">{error}</p> : null}
        <div className="temple-remedy-actions">
          <button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Submitting...' : isAuthenticated ? 'Submit Request' : 'Login and Continue'}
          </button>
          {status ? (
            <button type="button" onClick={onClose}>Close</button>
          ) : null}
        </div>
      </form>
    </section>
  )
}

export default TempleRemedyModal
