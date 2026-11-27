import { useState } from 'react'
import {
  createAdminManualPredictionRequest,
  fetchAdminManualPredictionOptions,
  searchAdminManualPredictionUsers,
} from '../../services/adminService'

const methods = [
  ['GPAY_QR', 'QR / UPI'],
  ['BANK_TRANSFER', 'Bank Transfer'],
  ['PAYMENT_GATEWAY', 'Online Payment'],
  ['CASH', 'Cash'],
  ['OTHER', 'Other'],
]

function formError(error) {
  const data = error.response?.data
  return Object.values(data?.errors || {}).flat()[0] || data?.message || 'Unable to complete this request.'
}

export default function AdminManualPredictionForm({ initialType, onClose, onCreated }) {
  const [term, setTerm] = useState('')
  const [users, setUsers] = useState(null)
  const [user, setUser] = useState(null)
  const [options, setOptions] = useState(null)
  const [type, setType] = useState(initialType)
  const [amount, setAmount] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const period = options?.periods?.[type]
  const profile = options?.profile

  const run = async (operation) => {
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await operation()
    } catch (failure) {
      setError(formError(failure))
    } finally {
      setBusy(false)
    }
  }

  const selectUser = (selected) => run(async () => {
    setUser(selected)
    setOptions(null)
    const found = await fetchAdminManualPredictionOptions(selected.id)
    setOptions(found)
    const minor = found.periods?.[type]?.amount_minor
    setAmount(minor == null ? '' : String(minor / 100))
  })

  const submit = (event) => {
    event.preventDefault()
    const payload = new FormData(event.currentTarget)
    if (!payload.get('payment_proof')?.size) payload.delete('payment_proof')
    payload.set('user_id', user.id)
    if (!period || payload.get('period_reference') !== period.reference) return
    run(async () => onCreated(await createAdminManualPredictionRequest(payload)))
  }

  return (
    <div className="admin-modal-overlay">
      <section className="admin-modal admin-manual-prediction" role="dialog" aria-modal="true" aria-labelledby="admin-manual-title">
        <header className="admin-modal-head">
          <h3 id="admin-manual-title">Add Manual Prediction Request</h3>
          <button type="button" disabled={busy} onClick={onClose} aria-label="Close manual prediction form">×</button>
        </header>

        <form className="admin-verification-search" onSubmit={(event) => {
          event.preventDefault()
          run(async () => setUsers(await searchAdminManualPredictionUsers(term.trim())))
        }}>
          <input type="search" value={term} onChange={(event) => setTerm(event.target.value)} placeholder="Name, mobile number, or email" aria-label="Search registered users" minLength={2} maxLength={120} required />
          <button type="submit" className="admin-row-action" disabled={busy || term.trim().length < 2}>Search Users</button>
        </form>

        {users && (
          <div className="admin-manual-users" aria-label="User search results">
            {users.length === 0 && <p>No registered users found.</p>}
            {users.map((current) => (
              <button type="button" className="admin-row-action" disabled={busy} key={current.id} aria-pressed={user?.id === current.id} onClick={() => selectUser(current)}>
                {current.name} · {current.email}{(current.phone_e164 || current.phone_number) && ` · ${current.phone_e164 || current.phone_number}`}
              </button>
            ))}
          </div>
        )}
        {user && options && !profile && <p>{user.name} needs saved birth and astrology details before activation.</p>}

        {profile && (
          <form className="admin-manual-fields" onSubmit={submit}>
            <fieldset>
              <legend>Selected profile</legend>
              <strong>{profile.full_name || user.name}</strong>
              <p>{user.email} {user.phone_e164 || user.phone_number}</p>
              <p>{[profile.date_of_birth, profile.time_of_birth, profile.place_of_birth, profile.timezone].filter(Boolean).join(' · ')}</p>
              <p>{[profile.gender, profile.marriage_status].filter(Boolean).join(' · ')}</p>
              <p>{[['Rasi', profile.astrology?.rasi], ['Nakshatra', profile.astrology?.nakshatra], ['Lagnam', profile.astrology?.lagnam]]
                .filter(([, value]) => value).map(([label, value]) => `${label} ${value}`).join(' · ')}</p>
            </fieldset>
            <label>
              Prediction Type
              <select name="prediction_type" value={type} onChange={(event) => {
                const selected = event.target.value
                setType(selected)
                const minor = options.periods?.[selected]?.amount_minor
                setAmount(minor == null ? '' : String(minor / 100))
              }}>
                <option value="past">Past Prediction</option>
                <option value="future">Future Prediction</option>
              </select>
            </label>
            <label>
              Prediction Period
              <select name="period_reference" key={period?.reference || type} required>
                {period ? <option value={period.reference}>{period.range.start_year}–{period.range.end_year}</option> : <option value="">No available period</option>}
              </select>
            </label>
            {period?.existing_status && <p className="admin-manual-wide">This will reuse the existing request ({period.existing_status === 'PENDING' ? 'Pending Verification' : 'Awaiting Payment Submission'}).</p>}
            <label>Payment Method<select name="payment_method">{methods.map(([id, label]) => <option key={id} value={id}>{label}</option>)}</select></label>
            <label>Payment Amount ({period?.currency || 'INR'})<input name="amount" type="number" step="0.01" min="0.01" max="42949672.95" required value={amount} onChange={(event) => setAmount(event.target.value)} /></label>
            <label>Payment Received Date<input name="payment_received_on" type="date" required /></label>
            <label>UPI Transaction Reference (Optional)<input name="transaction_reference" maxLength={120} /></label>
            <label className="admin-manual-wide">Payment Screenshot (Optional)<input name="payment_proof" type="file" accept="image/jpeg,image/png,image/webp" /></label>
            <label className="admin-manual-wide">Admin Remarks<textarea name="admin_remarks" maxLength={2000} rows={3} /></label>
            <label className="admin-manual-wide admin-manual-confirm"><input type="checkbox" name="payment_confirmed" value="1" required /> I have verified that the payment has been received.</label>
            <button className="admin-row-action is-success admin-manual-wide" type="submit" disabled={!period || busy}>
              {busy ? 'Working…' : 'Create Request & Activate Prediction'}
            </button>
          </form>
        )}
        {error && <p role="alert">{error}</p>}
      </section>
    </div>
  )
}
