export const MIN_SIGNUP_AGE = 16
export const SIGNUP_AGE_ERROR = 'You must be 16 years or older to create an account.'
export const INVALID_DOB_ERROR = 'Enter a valid date of birth.'

export function formatLocalDate(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

export function dateYearsAgo(years, today = new Date()) {
  const cutoff = new Date(today.getFullYear(), today.getMonth(), today.getDate())
  cutoff.setFullYear(cutoff.getFullYear() - years)
  return formatLocalDate(cutoff)
}

export function isValidCalendarDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(String(value || ''))) return false

  const [year, month, day] = String(value).split('-').map(Number)
  const date = new Date(year, month - 1, day)

  return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day
}

export function isAtLeastMinimumSignupAge(dateOfBirth, today = new Date()) {
  if (!isValidCalendarDate(dateOfBirth)) return false

  const todayDate = new Date(today.getFullYear(), today.getMonth(), today.getDate())
  if (dateOfBirth > formatLocalDate(todayDate)) return false

  return dateOfBirth <= dateYearsAgo(MIN_SIGNUP_AGE, todayDate)
}

export function dateOfBirthEligibilityError(dateOfBirth, today = new Date()) {
  if (!dateOfBirth) return ''
  if (!isValidCalendarDate(dateOfBirth)) return INVALID_DOB_ERROR

  const todayDate = new Date(today.getFullYear(), today.getMonth(), today.getDate())
  if (dateOfBirth > formatLocalDate(todayDate)) return INVALID_DOB_ERROR
  if (!isAtLeastMinimumSignupAge(dateOfBirth, todayDate)) return SIGNUP_AGE_ERROR

  return ''
}

function parseHourMinute(value) {
  const match = String(value || '').trim().match(/^(\d{1,2}):(\d{2})$/)
  if (!match) return null

  const hour = Number(match[1])
  const minute = Number(match[2])
  if (!Number.isInteger(hour) || hour < 1 || hour > 12 || !Number.isInteger(minute) || minute < 0 || minute > 59) {
    return null
  }

  return { hour, minute }
}

export function formatTimeInput(value) {
  const digits = String(value || '').replace(/\D/g, '').slice(0, 4)
  if (digits.length <= 2) return digits
  if (digits.length === 3) return `${digits.slice(0, 1)}:${digits.slice(1)}`
  return `${digits.slice(0, 2)}:${digits.slice(2)}`
}

export function normalizeTimeDisplay(value) {
  const parsed = parseHourMinute(value)
  if (!parsed) return value
  return `${String(parsed.hour).padStart(2, '0')}:${String(parsed.minute).padStart(2, '0')}`
}

export function to24HourTime(hhmm, meridiem) {
  const parsed = parseHourMinute(hhmm)
  if (!parsed || (meridiem !== 'AM' && meridiem !== 'PM')) return ''

  let hours = parsed.hour % 12
  if (meridiem === 'PM') hours += 12

  return `${String(hours).padStart(2, '0')}:${String(parsed.minute).padStart(2, '0')}`
}

export function from24HourTime(value) {
  const match = String(value || '').trim().match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/)
  if (!match) return { hhmm: '', meridiem: 'AM' }

  const hours = Number(match[1])
  const minutes = Number(match[2])
  if (!Number.isInteger(hours) || hours < 0 || hours > 23 || !Number.isInteger(minutes) || minutes < 0 || minutes > 59) {
    return { hhmm: '', meridiem: 'AM' }
  }

  const meridiem = hours >= 12 ? 'PM' : 'AM'
  const hour12 = hours % 12 === 0 ? 12 : hours % 12

  return {
    hhmm: `${String(hour12).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`,
    meridiem,
  }
}
