import './AuthenticationLoader.css'

function AuthenticationLoader({
  title = 'Checking your session...',
  message = 'Please wait while we prepare your experience.',
  fullscreen = false,
  compact = false,
}) {
  return (
    <div className={`${fullscreen ? 'auth-loader-screen' : 'auth-loader-card'} ${compact ? 'is-compact' : ''}`} role="status" aria-live="polite">
      <div className="auth-loader-orbit" aria-hidden="true">
        <span className="auth-loader-sun"></span>
        <span className="auth-loader-ring ring-one"></span>
        <span className="auth-loader-ring ring-two"></span>
        <span className="auth-loader-ring ring-three"></span>
        <span className="auth-loader-particle particle-one"></span>
        <span className="auth-loader-particle particle-two"></span>
        <span className="auth-loader-particle particle-three"></span>
      </div>
      <div className="auth-loader-copy">
        <strong>{title}</strong>
        <span>{message}</span>
      </div>
      <div className="auth-loader-progress" aria-hidden="true">
        <span></span>
      </div>
    </div>
  )
}

export default AuthenticationLoader
