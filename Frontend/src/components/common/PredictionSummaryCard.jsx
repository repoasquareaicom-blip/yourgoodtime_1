function PredictionSummaryCard({ children, delay = 0 }) {
  return (
    <span className="past-processing__summary-card" style={{ '--summary-delay': `${delay}ms` }}>
      {children}
    </span>
  )
}

export default PredictionSummaryCard
