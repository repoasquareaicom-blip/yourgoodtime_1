import { useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import AuthPanel from '../components/common/AuthPanel'
import './LoginPage.css'

function LoginPage() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const location = useLocation()
  const isModal = Boolean(location.state?.backgroundLocation)
  const requestedMode = searchParams.get('mode')
  const initialMode = requestedMode === 'register' || requestedMode === 'forgot' || requestedMode === 'otp-login'
    ? requestedMode
    : 'login'
  const redirectTo = location.state?.redirectTo || searchParams.get('redirect') || '/dashboard'

  const handleAuthenticated = () => {
    const dashboardRedirect = redirectTo === '/future' || redirectTo === '/past-prediction' ? '/account' : redirectTo
    navigate(`/account-loading?redirect=${encodeURIComponent(dashboardRedirect)}`, { replace: true })
  }

  const handleClose = () => {
    if (isModal) {
      navigate(-1)
      return
    }

    navigate('/home')
  }

  const closeIfBackdrop = (event) => {
    if (event.target === event.currentTarget) handleClose()
  }

  return (
    <main
      className={`auth-page ${isModal ? 'is-modal' : 'is-standalone'}`}
      onPointerDown={closeIfBackdrop}
      onClick={closeIfBackdrop}
    >
      <div
        className="auth-shell-card"
        onPointerDown={(event) => event.stopPropagation()}
        onClick={(event) => event.stopPropagation()}
      >
        <AuthPanel initialMode={initialMode} onAuthenticated={handleAuthenticated} onClose={handleClose} />
      </div>
    </main>
  )
}

export default LoginPage
