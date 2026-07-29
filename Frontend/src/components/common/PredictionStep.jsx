function PredictionStep({ index, step, status }) {
  return (
    <li className={`is-${status}`}>
      <span className="past-processing__marker">
        {status === 'completed' ? '✓' : index + 1}
      </span>
      <span>
        <strong>{step.label}</strong>
        {status === 'active' ? <small>{step.message}</small> : null}
        {status === 'completed' ? <small>{step.completeLabel}</small> : null}
      </span>
    </li>
  )
}

export default PredictionStep
