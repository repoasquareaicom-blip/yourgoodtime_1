import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { loginAdmin } from '../services/adminService'
import { saveAdminToken, saveAdminUser } from '../utils/auth'
import './AdminLoginPage.css'

function AdminLoginPage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const redirectTo = searchParams.get('redirect') || '/admin'
  const [form, setForm] = useState({ email: '', password: '' })
  const [status, setStatus] = useState('idle')
  const [message, setMessage] = useState('')

  const updateField = (key, value) => {
    setForm((current) => ({ ...current, [key]: value }))
  }

  const submit = async (event) => {
    event.preventDefault()
    setStatus('submitting')
    setMessage('')

    try {
      const response = await loginAdmin(form)
      saveAdminToken(response.token)
      saveAdminUser(response.user)
      navigate(redirectTo, { replace: true })
    } catch (error) {
      setStatus('idle')
      setMessage(error.response?.data?.message || 'Unable to login as admin.')
    }
  }

  return (
    <main className="admin-login-shell">
      <section className="admin-login-card">
        <div className="admin-login-brand">
          <span>YGT</span>
          <div>
            <p>Your Good Time</p>
            <h1>Admin Access</h1>
          </div>
        </div>

        <form className="admin-login-form" onSubmit={submit}>
          <label>
            Admin username
            <input
              type="text"
              value={form.email}
              onChange={(event) => updateField('email', event.target.value)}
              autoComplete="username"
              required
            />
          </label>
          <label>
            Password
            <input
              type="password"
              value={form.password}
              onChange={(event) => updateField('password', event.target.value)}
              autoComplete="current-password"
              required
            />
          </label>
          {message ? <p className="admin-login-error">{message}</p> : null}
          <button type="submit" disabled={status === 'submitting'}>
            {status === 'submitting' ? 'Checking access...' : 'Login to Admin'}
          </button>
        </form>

        <button type="button" className="admin-login-home" onClick={() => navigate('/home')}>
          Back to website
        </button>
      </section>
    </main>
  )
}

export default AdminLoginPage
