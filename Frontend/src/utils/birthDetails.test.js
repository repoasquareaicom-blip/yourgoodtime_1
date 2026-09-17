import assert from 'node:assert/strict'
import test from 'node:test'
import {
  dateOfBirthEligibilityError,
  dateYearsAgo,
  from24HourTime,
  isAtLeastMinimumSignupAge,
  MIN_SIGNUP_AGE,
  SIGNUP_AGE_ERROR,
  to24HourTime,
} from './birthDetails.js'

const today = new Date(2026, 8, 16)

test('max selectable DOB is exactly 16 years ago', () => {
  assert.equal(MIN_SIGNUP_AGE, 16)
  assert.equal(dateYearsAgo(MIN_SIGNUP_AGE, today), '2010-09-16')
})

test('exactly 16 today is allowed', () => {
  assert.equal(isAtLeastMinimumSignupAge('2010-09-16', today), true)
  assert.equal(dateOfBirthEligibilityError('2010-09-16', today), '')
})

test('16 years plus one day is allowed', () => {
  assert.equal(isAtLeastMinimumSignupAge('2010-09-15', today), true)
  assert.equal(dateOfBirthEligibilityError('2010-09-15', today), '')
})

test('one day short of 16 is blocked', () => {
  assert.equal(isAtLeastMinimumSignupAge('2010-09-17', today), false)
  assert.equal(dateOfBirthEligibilityError('2010-09-17', today), SIGNUP_AGE_ERROR)
})

test('clearly under 16 is blocked', () => {
  assert.equal(isAtLeastMinimumSignupAge('2015-01-01', today), false)
  assert.equal(dateOfBirthEligibilityError('2015-01-01', today), SIGNUP_AGE_ERROR)
})

test('future DOB is blocked', () => {
  assert.equal(isAtLeastMinimumSignupAge('2026-09-17', today), false)
  assert.equal(dateOfBirthEligibilityError('2026-09-17', today), 'Enter a valid date of birth.')
})

test('empty DOB leaves required validation to the form', () => {
  assert.equal(dateOfBirthEligibilityError('', today), '')
})

test('converts 12-hour AM/PM display to 24-hour H:i', () => {
  assert.equal(to24HourTime('12:00', 'AM'), '00:00')
  assert.equal(to24HourTime('12:30', 'AM'), '00:30')
  assert.equal(to24HourTime('01:00', 'AM'), '01:00')
  assert.equal(to24HourTime('01:30', 'AM'), '01:30')
  assert.equal(to24HourTime('11:59', 'AM'), '11:59')
  assert.equal(to24HourTime('12:00', 'PM'), '12:00')
  assert.equal(to24HourTime('12:30', 'PM'), '12:30')
  assert.equal(to24HourTime('01:00', 'PM'), '13:00')
  assert.equal(to24HourTime('01:30', 'PM'), '13:30')
  assert.equal(to24HourTime('11:59', 'PM'), '23:59')
})

test('converts existing 24-hour values to 12-hour display', () => {
  assert.deepEqual(from24HourTime('00:30'), { hhmm: '12:30', meridiem: 'AM' })
  assert.deepEqual(from24HourTime('09:15'), { hhmm: '09:15', meridiem: 'AM' })
  assert.deepEqual(from24HourTime('12:30'), { hhmm: '12:30', meridiem: 'PM' })
  assert.deepEqual(from24HourTime('13:30'), { hhmm: '01:30', meridiem: 'PM' })
  assert.deepEqual(from24HourTime('23:45'), { hhmm: '11:45', meridiem: 'PM' })
})
