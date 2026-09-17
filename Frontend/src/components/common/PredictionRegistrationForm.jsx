import { useEffect, useRef, useState } from 'react'
import PlaceAutocomplete from './PlaceAutocomplete'
import {
  dateOfBirthEligibilityError,
  formatTimeInput,
  from24HourTime,
  normalizeTimeDisplay,
  to24HourTime,
} from '../../utils/birthDetails'
import './PredictionRegistrationForm.css'

function PredictionRegistrationForm({
  values,
  disabled = false,
  isSubmitting = false,
  submitLabel = 'Continue',
  loadingLabel = 'Submitting...',
  error = '',
  fieldErrors = {},
  emailInputRef,
  onSubmit,
  onFieldChange,
  onPlaceChange,
  onPlaceSelect,
  children,
  renderAsForm = true,
  requireMinimumAge = false,
  dateInputMax,
}) {
  const parsedTime = from24HourTime(values.timeOfBirth)
  const [timeDisplay, setTimeDisplay] = useState(parsedTime.hhmm)
  const [meridiem, setMeridiem] = useState(parsedTime.meridiem)
  const lastEmittedTimeRef = useRef(values.timeOfBirth || '')
  const update = (field, value) => {
    onFieldChange?.(field, value)
  }
  const Shell = renderAsForm ? 'form' : 'div'
  const shellProps = renderAsForm ? { onSubmit } : {}
  const dobError = requireMinimumAge
    ? (dateOfBirthEligibilityError(values.dateOfBirth) || fieldErrors.date_of_birth)
    : fieldErrors.date_of_birth

  useEffect(() => {
    const parentTime = values.timeOfBirth || ''
    if (parentTime === (lastEmittedTimeRef.current || '')) return

    lastEmittedTimeRef.current = parentTime
    const nextParsed = from24HourTime(parentTime)
    if (nextParsed.hhmm) {
      setTimeDisplay(nextParsed.hhmm)
      setMeridiem(nextParsed.meridiem)
      return
    }

    setTimeDisplay('')
    setMeridiem('AM')
  }, [values.timeOfBirth])

  const emitTime = (nextDisplay, nextMeridiem) => {
    const trimmed = String(nextDisplay || '').trim()
    if (!trimmed) {
      lastEmittedTimeRef.current = ''
      update('timeOfBirth', '')
      return
    }

    const next24 = to24HourTime(normalizeTimeDisplay(trimmed), nextMeridiem)
    if (!next24) return

    lastEmittedTimeRef.current = next24
    update('timeOfBirth', next24)
  }

  return (
    <Shell className="past-form prediction-registration-form" {...shellProps}>
      <label>
        Full name
        <input
          type="text"
          value={values.fullName}
          disabled={disabled}
          onChange={(event) => update('fullName', event.target.value)}
          placeholder="Full Name"
          autoComplete="name"
          required
        />
        {fieldErrors.full_name ? <span className="past-field-error">{fieldErrors.full_name}</span> : null}
      </label>

      <label>
        Email ID
        <input
          ref={emailInputRef}
          type="email"
          value={values.email}
          disabled={disabled}
          onChange={(event) => update('email', event.target.value)}
          placeholder="name@example.com"
          autoComplete="email"
          required
        />
        {fieldErrors.email ? <span className="past-field-error">{fieldErrors.email}</span> : null}
      </label>

      <div className="past-birth-row">
        <label>
          Date of birth
          <input
            type="date"
            value={values.dateOfBirth}
            disabled={disabled}
            max={dateInputMax || undefined}
            aria-invalid={Boolean(dobError)}
            onChange={(event) => update('dateOfBirth', event.target.value)}
            required
          />
          {dobError ? <span className="past-field-error">{dobError}</span> : null}
        </label>

        <label>
          Time of birth
          <span className="past-field-caption">hh:mm AM/PM</span>
          <div className="past-time-of-birth">
            <input
              type="text"
              inputMode="numeric"
              autoComplete="off"
              placeholder="hh:mm"
              value={timeDisplay}
              disabled={disabled}
              aria-label="Time of birth hours and minutes"
              maxLength={5}
              required
              onChange={(event) => {
                const nextDisplay = formatTimeInput(event.target.value)
                setTimeDisplay(nextDisplay)
                emitTime(nextDisplay, meridiem)
              }}
              onBlur={() => {
                const nextDisplay = normalizeTimeDisplay(timeDisplay)
                setTimeDisplay(nextDisplay)
                emitTime(nextDisplay, meridiem)
              }}
            />
            <select
              className="past-meridiem"
              value={meridiem}
              disabled={disabled}
              aria-label="AM or PM"
              onChange={(event) => {
                const nextMeridiem = event.target.value
                setMeridiem(nextMeridiem)
                emitTime(timeDisplay, nextMeridiem)
              }}
            >
              <option value="AM">AM</option>
              <option value="PM">PM</option>
            </select>
          </div>
          {fieldErrors.time_of_birth ? <span className="past-field-error">{fieldErrors.time_of_birth}</span> : null}
        </label>
      </div>

      <label>
        Marriage status
        <select
          value={values.marriageStatus}
          disabled={disabled}
          onChange={(event) => update('marriageStatus', event.target.value)}
          required
        >
          <option value="">Select status</option>
          <option value="unmarried">Unmarried</option>
          <option value="married">Married</option>
        </select>
        {fieldErrors.marriage_status ? <span className="past-field-error">{fieldErrors.marriage_status}</span> : null}
      </label>

      <label>
        Place of birth
        <PlaceAutocomplete
          value={values.placeOfBirth}
          onChange={onPlaceChange}
          onSelect={onPlaceSelect}
          disabled={disabled}
          required
        />
        <span className="past-field-helper">Not listed? Select the nearest main city.</span>
        {fieldErrors.place_of_birth || fieldErrors.birth_place ? (
          <span className="past-field-error">{fieldErrors.place_of_birth || fieldErrors.birth_place}</span>
        ) : null}
        {fieldErrors.latitude || fieldErrors.longitude ? (
          <span className="past-field-error">{fieldErrors.latitude || fieldErrors.longitude}</span>
        ) : null}
        {fieldErrors.timezone ? <span className="past-field-error">{fieldErrors.timezone}</span> : null}
      </label>

      {error ? <p className="past-error">{error}</p> : null}
      {children}

      <button type="submit" className="past-submit" disabled={disabled || isSubmitting || Boolean(requireMinimumAge && dobError)}>
        {isSubmitting ? loadingLabel : submitLabel}
      </button>
    </Shell>
  )
}

export default PredictionRegistrationForm
