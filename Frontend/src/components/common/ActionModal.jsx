import './ActionModal.css'

function ActionModal({
  title,
  message,
  primaryLabel,
  secondaryLabel,
  onPrimary,
  onSecondary,
  isSecondaryLoading = false,
}) {
  return (
    <div className="action-modal-overlay" role="presentation">
      <section className="action-modal" role="dialog" aria-modal="true" aria-labelledby="action-modal-title">
        <h2 id="action-modal-title">{title}</h2>
        <p>{message}</p>

        <div className="action-modal-actions">
          <button type="button" className="action-modal-primary" onClick={onPrimary}>
            {primaryLabel}
          </button>
          <button type="button" className="action-modal-secondary" onClick={onSecondary} disabled={isSecondaryLoading}>
            {isSecondaryLoading ? 'Logging out...' : secondaryLabel}
          </button>
        </div>
      </section>
    </div>
  )
}

export default ActionModal
