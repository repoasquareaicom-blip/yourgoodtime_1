import { useEffect, useState } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import AuthenticationLoader from './AuthenticationLoader'
import { getCurrentUser } from '../../services/authService'
import { isLoggedIn, removeToken, removeUser, saveUser } from '../../utils/auth'

function ProtectedRoute({ children }) {
  const location = useLocation()
  const [status, setStatus] = useState(isLoggedIn() ? 'checking' : 'guest')
  const redirectTo = location.pathname + location.search

  useEffect(() => {
    if (!isLoggedIn()) {
      setStatus('guest')
      return undefined
    }

    let isMounted = true
    getCurrentUser()
      .then((response) => {
        if (!isMounted) return
        if (response?.user) {
          saveUser(response.user)
          setStatus('authenticated')
          return
        }
        removeToken()
        removeUser()
        setStatus('guest')
      })
      .catch(() => {
        if (!isMounted) return
        removeToken()
        removeUser()
        setStatus('guest')
      })

    return () => {
      isMounted = false
    }
  }, [redirectTo])

  if (status === 'checking') {
    return (
      <AuthenticationLoader
        fullscreen
        title="Checking your session..."
        message="Please wait while we prepare your experience."
      />
    )
  }

  if (status !== 'authenticated') {
    return (
      <Navigate
        to={`/login?redirect=${encodeURIComponent(redirectTo)}`}
        replace
        state={{ redirectTo }}
      />
    )
  }

  return children
}

export default ProtectedRoute
