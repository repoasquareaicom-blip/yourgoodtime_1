import PlaceAutocomplete from './PlaceAutocomplete'
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
}) {
  const update = (field, value) => {
    onFieldChange?.(field, value)
  }
  const Shell = renderAsForm ? 'form' : 'div'
  const shellProps = renderAsForm ? { onSubmit } : {}

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
            onChange={(event) => update('dateOfBirth', event.target.value)}
            required
          />
          {fieldErrors.date_of_birth ? <span className="past-field-error">{fieldErrors.date_of_birth}</span> : null}
        </label>

        <label>
          Time of birth
          <span className="past-field-caption">hh:mm AM/PM</span>
          <input
            type="time"
            value={values.timeOfBirth}
            disabled={disabled}
            onChange={(event) => update('timeOfBirth', event.target.value)}
            required
          />
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

      <button type="submit" className="past-submit" disabled={disabled || isSubmitting}>
        {isSubmitting ? loadingLabel : submitLabel}
      </button>
    </Shell>
  )
}

export default PredictionRegistrationForm
