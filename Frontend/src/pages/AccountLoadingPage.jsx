import { useEffect } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import AuthenticationLoader from '../components/common/AuthenticationLoader'

function safeDashboardTarget(value) {
  if (!value || typeof value !== 'string') return '/account'

  if (value === '/future' || value === '/past-prediction') {
    return '/account'
  }

  return value.startsWith('/') ? value : '/account'
}

function AccountLoadingPage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const target = safeDashboardTarget(searchParams.get('redirect'))

  useEffect(() => {
    const timer = window.setTimeout(() => {
      navigate(target, { replace: true })
    }, 2400)

    return () => window.clearTimeout(timer)
  }, [navigate, target])

  return (
    <AuthenticationLoader
      fullscreen
      title="Loading your personal page securely..."
      message="You can access your Past and Future Predictions from your dashboard."
    />
  )
}

export default AccountLoadingPage
