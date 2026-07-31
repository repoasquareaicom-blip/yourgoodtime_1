import { useEffect, useState } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import AuthenticationLoader from './AuthenticationLoader'
import { getCurrentAdmin } from '../../services/adminService'
import { isAdminLoggedIn, removeAdminToken, removeAdminUser, saveAdminUser } from '../../utils/auth'

function AdminProtectedRoute({ children }) {
  const location = useLocation()
  const [status, setStatus] = useState(isAdminLoggedIn() ? 'checking' : 'guest')
  const redirectTo = location.pathname + location.search

  useEffect(() => {
    if (!isAdminLoggedIn()) {
      setStatus('guest')
      return undefined
    }

    let mounted = true

    getCurrentAdmin()
      .then((response) => {
        if (!mounted) return

        if (response?.user?.is_admin) {
          saveAdminUser(response.user)
          setStatus('authenticated')
          return
        }

        removeAdminToken()
        removeAdminUser()
        setStatus('guest')
      })
      .catch(() => {
        if (!mounted) return
        removeAdminToken()
        removeAdminUser()
        setStatus('guest')
      })

    return () => {
      mounted = false
    }
  }, [redirectTo])

  if (status === 'checking') {
    return (
      <AuthenticationLoader
        fullscreen
        title="Checking admin access..."
        message="Please wait while we verify your admin session."
      />
    )
  }

  if (status !== 'authenticated') {
    return (
      <Navigate
        to={`/admin-login?redirect=${encodeURIComponent(redirectTo)}`}
        replace
        state={{ redirectTo }}
      />
    )
  }

  return children
}

export default AdminProtectedRoute
