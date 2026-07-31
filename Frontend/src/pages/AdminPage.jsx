import { useEffect, useMemo, useState } from 'react'
import { Navigate } from 'react-router-dom'
import {
  fetchAdminOverview,
  fetchAdminPredictionRequests,
  fetchAdminTempleRemedies,
  fetchAdminUsers,
  logoutAdmin,
  updateAdminTempleRemedy,
} from '../services/adminService'
import { getAdminUser, removeAdminToken, removeAdminUser } from '../utils/auth'
import './AdminPage.css'

const tabs = [
  { id: 'users', label: 'Users' },
  { id: 'past', label: 'Past Requests' },
  { id: 'future', label: 'Future Requests' },
  { id: 'remedies', label: 'Temple Remedies' },
]

const statusOptions = ['RECEIVED', 'REVIEWING', 'PAYMENT_PENDING', 'PAID', 'PREPARING_REMEDY', 'REMEDY_READY', 'COMPLETED']
const paymentOptions = ['NOT_REQUIRED', 'PENDING', 'PAID', 'FAILED', 'REFUNDED']
const remedyTypes = ['', 'pdf', 'image', 'video', 'link']

function formatDate(value) {
  if (!value) return 'Not available'

  return new Intl.DateTimeFormat('en-IN', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
}

function formatMoney(amountMinor, currency = 'INR') {
  if (!amountMinor) return 'No amount'

  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency,
  }).format(amountMinor / 100)
}

function labelFromKey(value) {
  return String(value || 'Not available').replaceAll('_', ' ').toLowerCase().replace(/\b\w/g, (letter) => letter.toUpperCase())
}

function AdminPage() {
  const user = getAdminUser()
  const [activeTab, setActiveTab] = useState('users')
  const [overview, setOverview] = useState(null)
  const [users, setUsers] = useState([])
  const [pastRequests, setPastRequests] = useState([])
  const [futureRequests, setFutureRequests] = useState([])
  const [remedies, setRemedies] = useState([])
  const [edits, setEdits] = useState({})
  const [status, setStatus] = useState('loading')
  const [message, setMessage] = useState('')

  useEffect(() => {
    let mounted = true

    async function loadAdminData() {
      setStatus('loading')
      setMessage('')

      try {
        const [overviewData, userData, pastData, futureData, remedyData] = await Promise.all([
          fetchAdminOverview(),
          fetchAdminUsers(),
          fetchAdminPredictionRequests('past'),
          fetchAdminPredictionRequests('future'),
          fetchAdminTempleRemedies(),
        ])

        if (!mounted) return

        setOverview(overviewData)
        setUsers(userData)
        setPastRequests(pastData)
        setFutureRequests(futureData)
        setRemedies(remedyData)
        setEdits(Object.fromEntries(remedyData.map((remedy) => [remedy.id, remedyToEdit(remedy)])))
        setStatus('ready')
      } catch (error) {
        if (!mounted) return
        setStatus(error.response?.status === 403 ? 'forbidden' : 'error')
        setMessage(error.response?.data?.message || 'Unable to load admin dashboard.')
      }
    }

    loadAdminData()

    return () => {
      mounted = false
    }
  }, [])

  const activeCount = useMemo(() => {
    if (activeTab === 'users') return users.length
    if (activeTab === 'past') return pastRequests.length
    if (activeTab === 'future') return futureRequests.length
    return remedies.length
  }, [activeTab, futureRequests.length, pastRequests.length, remedies.length, users.length])

  if (status === 'forbidden' || (status !== 'loading' && !user?.is_admin)) {
    return <Navigate to="/admin-login" replace />
  }

  const handleLogout = async () => {
    try {
      await logoutAdmin()
    } catch {
      // Local cleanup is enough if the token is already invalid.
    }

    removeAdminToken()
    removeAdminUser()
    window.location.assign('/admin-login')
  }

  const updateRemedyEdit = (id, key, value) => {
    setEdits((current) => ({
      ...current,
      [id]: {
        ...current[id],
        [key]: value,
      },
    }))
  }

  const saveRemedy = async (id) => {
    const draft = edits[id]
    if (!draft) return

    setMessage('Saving temple remedy workflow...')

    try {
      const response = await updateAdminTempleRemedy(id, {
        ...draft,
        amount_minor: draft.amount_minor === '' ? null : Number(draft.amount_minor),
        payment_enabled: Boolean(draft.payment_enabled),
      })

      setRemedies((current) => current.map((item) => (item.id === id ? response.data : item)))
      setEdits((current) => ({ ...current, [id]: remedyToEdit(response.data) }))
      setMessage('Temple remedy request updated.')
    } catch (error) {
      setMessage(error.response?.data?.message || 'Unable to update temple remedy request.')
    }
  }

  return (
    <main className="admin-shell">
      <aside className="admin-sidebar">
        <div className="admin-brand">
          <span className="admin-mark">YGT</span>
          <div>
            <strong>Your Good Time</strong>
            <span>Admin workspace</span>
          </div>
        </div>
        <nav className="admin-tabs" aria-label="Admin sections">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              type="button"
              className={activeTab === tab.id ? 'is-active' : ''}
              onClick={() => setActiveTab(tab.id)}
            >
              {tab.label}
            </button>
          ))}
        </nav>
      </aside>

      <section className="admin-main">
        <header className="admin-header">
          <div>
            <p>Admin Dashboard</p>
            <h1>Operations Control Center</h1>
          </div>
          <div className="admin-user-chip">
            <span>{user?.name?.charAt(0)?.toUpperCase() || 'A'}</span>
            <div>
              <strong>{user?.name || 'Admin'}</strong>
              <small>{user?.email}</small>
            </div>
            <button type="button" onClick={handleLogout}>Logout</button>
          </div>
        </header>

        <section className="admin-metrics" aria-label="Dashboard summary">
          <Metric label="Users" value={overview?.cards?.users} />
          <Metric label="Past Requests" value={overview?.cards?.past_requests} />
          <Metric label="Future Requests" value={overview?.cards?.future_requests} />
          <Metric label="Temple Remedies" value={overview?.cards?.temple_remedies} />
        </section>

        <section className="admin-panel">
          <div className="admin-panel-head">
            <div>
              <p>{activeCount} records</p>
              <h2>{tabs.find((tab) => tab.id === activeTab)?.label}</h2>
            </div>
            {message ? <span className="admin-message">{message}</span> : null}
          </div>

          {status === 'loading' ? <div className="admin-empty">Loading admin data...</div> : null}
          {status === 'error' ? <div className="admin-empty">{message}</div> : null}
          {status === 'ready' && activeTab === 'users' ? <UsersTable users={users} /> : null}
          {status === 'ready' && activeTab === 'past' ? <PredictionList requests={pastRequests} /> : null}
          {status === 'ready' && activeTab === 'future' ? <PredictionList requests={futureRequests} /> : null}
          {status === 'ready' && activeTab === 'remedies' ? (
            <RemedyList remedies={remedies} edits={edits} onChange={updateRemedyEdit} onSave={saveRemedy} />
          ) : null}
        </section>
      </section>
    </main>
  )
}

function Metric({ label, value }) {
  return (
    <article className="admin-metric">
      <span>{label}</span>
      <strong>{value ?? '-'}</strong>
    </article>
  )
}

function UsersTable({ users }) {
  if (!users.length) return <div className="admin-empty">No users found.</div>

  return (
    <div className="admin-table-wrap">
      <table className="admin-table">
        <thead>
          <tr>
            <th>User</th>
            <th>Email</th>
            <th>Status</th>
            <th>Last Login</th>
          </tr>
        </thead>
        <tbody>
          {users.map((user) => (
            <tr key={user.id}>
              <td>{user.name}</td>
              <td>{user.email}</td>
              <td><span className={`admin-badge ${user.is_active ? 'is-green' : 'is-muted'}`}>{user.is_active ? 'Active' : 'Inactive'}</span></td>
              <td>{formatDate(user.last_login_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function PredictionList({ requests }) {
  if (!requests.length) return <div className="admin-empty">No prediction requests found.</div>

  return (
    <div className="admin-request-list">
      {requests.map((request) => (
        <article className="admin-request-card" key={`${request.prediction_type}-${request.id}`}>
          <div className="admin-request-title">
            <div>
              <span>#{request.prediction_request_id || request.id}</span>
              <h3>{request.identity?.name || 'Prediction request'}</h3>
            </div>
            <span className="admin-badge is-blue">{labelFromKey(request.status)}</span>
          </div>
          <div className="admin-readable-grid">
            <InfoBlock title="Identity Fetched" items={[
              ['Email', request.identity?.email],
              ['Birth', `${request.identity?.date_of_birth || '-'} ${request.identity?.time_of_birth || ''}`],
              ['Place', request.identity?.place_of_birth],
              ['Coordinates', request.identity?.latitude ? `${request.identity.latitude}, ${request.identity.longitude}` : null],
              ['Marriage', request.identity?.marriage_status],
            ]} />
            <InfoBlock title="Astrology Summary" items={[
              ['Rasi', request.astrology?.rasi],
              ['Nakshatra', request.astrology?.nakshatra],
              ['Lagnam', request.astrology?.lagnam],
            ]} />
            <InfoBlock title="Prediction Engine" items={[
              ['API calls', request.engine?.api_calls],
              ['Cache', request.engine?.cache_hit ? 'Used' : 'Fresh'],
              ['Videos', request.engine?.videos_count],
              ['Warnings', request.engine?.warnings_count],
              ['Generated', formatDate(request.generated_at || request.completed_at)],
            ]} />
          </div>
        </article>
      ))}
    </div>
  )
}

function InfoBlock({ title, items }) {
  return (
    <div className="admin-info-block">
      <h4>{title}</h4>
      {items.map(([label, value]) => (
        <div className="admin-info-row" key={label}>
          <span>{label}</span>
          <strong>{value || 'Not available'}</strong>
        </div>
      ))}
    </div>
  )
}

function RemedyList({ remedies, edits, onChange, onSave }) {
  if (!remedies.length) return <div className="admin-empty">No temple remedy requests found.</div>

  return (
    <div className="admin-request-list">
      {remedies.map((remedy) => {
        const draft = edits[remedy.id] || remedyToEdit(remedy)

        return (
          <article className="admin-request-card" key={remedy.id}>
            <div className="admin-request-title">
              <div>
                <span>#{remedy.id} · {formatDate(remedy.submitted_at)}</span>
                <h3>{remedy.user?.name || 'Temple remedy request'}</h3>
              </div>
              <div className="admin-badge-row">
                <span className="admin-badge is-blue">{labelFromKey(remedy.status)}</span>
                <span className="admin-badge is-gold">{labelFromKey(remedy.payment_status)}</span>
              </div>
            </div>

            <div className="admin-remedy-layout">
              <div className="admin-problem-box">
                <span>Problem shared by user</span>
                <p>{remedy.problem_text}</p>
                <small>{remedy.user?.email}</small>
              </div>

              <form className="admin-remedy-form" onSubmit={(event) => { event.preventDefault(); onSave(remedy.id) }}>
                <label>
                  Status
                  <select value={draft.status} onChange={(event) => onChange(remedy.id, 'status', event.target.value)}>
                    {statusOptions.map((option) => <option key={option} value={option}>{labelFromKey(option)}</option>)}
                  </select>
                </label>
                <label>
                  Payment
                  <select value={draft.payment_status} onChange={(event) => onChange(remedy.id, 'payment_status', event.target.value)}>
                    {paymentOptions.map((option) => <option key={option} value={option}>{labelFromKey(option)}</option>)}
                  </select>
                </label>
                <label className="admin-toggle">
                  <input type="checkbox" checked={draft.payment_enabled} onChange={(event) => onChange(remedy.id, 'payment_enabled', event.target.checked)} />
                  Enable payment for user
                </label>
                <label>
                  Amount in paise
                  <input value={draft.amount_minor} onChange={(event) => onChange(remedy.id, 'amount_minor', event.target.value)} placeholder="Example: 150000" />
                  <small>{formatMoney(Number(draft.amount_minor), draft.currency)}</small>
                </label>
                <label>
                  Remedy type
                  <select value={draft.remedy_type} onChange={(event) => onChange(remedy.id, 'remedy_type', event.target.value)}>
                    {remedyTypes.map((type) => <option key={type || 'blank'} value={type}>{type ? labelFromKey(type) : 'Not ready'}</option>)}
                  </select>
                </label>
                <label>
                  Remedy title
                  <input value={draft.remedy_title} onChange={(event) => onChange(remedy.id, 'remedy_title', event.target.value)} />
                </label>
                <label className="is-wide">
                  Remedy URL
                  <input value={draft.remedy_url} onChange={(event) => onChange(remedy.id, 'remedy_url', event.target.value)} placeholder="PDF, image, video, or secure link" />
                </label>
                <label className="is-wide">
                  Admin note
                  <textarea value={draft.admin_note} onChange={(event) => onChange(remedy.id, 'admin_note', event.target.value)} rows="3" />
                </label>
                <button type="submit">Save Workflow</button>
              </form>
            </div>
          </article>
        )
      })}
    </div>
  )
}

function remedyToEdit(remedy) {
  return {
    status: remedy.status || 'RECEIVED',
    payment_enabled: Boolean(remedy.payment_enabled),
    amount_minor: remedy.amount_minor ?? '',
    currency: remedy.currency || 'INR',
    payment_status: remedy.payment_status || 'NOT_REQUIRED',
    remedy_type: remedy.remedy_type || '',
    remedy_title: remedy.remedy_title || '',
    remedy_url: remedy.remedy_url || '',
    admin_note: remedy.admin_note || '',
  }
}

export default AdminPage
