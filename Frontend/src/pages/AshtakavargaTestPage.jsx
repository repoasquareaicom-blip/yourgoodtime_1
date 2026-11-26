import { useState } from 'react'
import PlaceAutocomplete from '../components/common/PlaceAutocomplete'
import { fetchAshtakavargaResearch } from '../services/ashtakavargaResearchService'
import {
  formatTimeInput,
  normalizeTimeDisplay,
  to24HourTime,
} from '../utils/birthDetails'
import ygtLogo from '../assets/yt-logo.png'
import '../components/common/PredictionRegistrationForm.css'
import './HomePage.css'
import './AshtakavargaTestPage.css'

const SIGNS = [
  { key: 'aries', label: 'Aries' },
  { key: 'taurus', label: 'Taurus' },
  { key: 'gemini', label: 'Gemini' },
  { key: 'cancer', label: 'Cancer' },
  { key: 'leo', label: 'Leo' },
  { key: 'virgo', label: 'Virgo' },
  { key: 'libra', label: 'Libra' },
  { key: 'scorpio', label: 'Scorpio' },
  { key: 'sagittarius', label: 'Sagittarius' },
  { key: 'capricorn', label: 'Capricorn' },
  { key: 'aquarius', label: 'Aquarius' },
  { key: 'pisces', label: 'Pisces' },
]

const PLANETS = ['sun', 'moon', 'mars', 'mercury', 'jupiter', 'venus', 'saturn']

const PLANET_LABELS = {
  sun: 'Sun',
  moon: 'Moon',
  mars: 'Mars',
  mercury: 'Mercury',
  jupiter: 'Jupiter',
  venus: 'Venus',
  saturn: 'Saturn',
}

function isValidTimezone(timezone) {
  if (!timezone || typeof timezone !== 'string') return false

  try {
    Intl.DateTimeFormat('en-US', { timeZone: timezone }).format(new Date())
    return true
  } catch {
    return false
  }
}

function resolveBirthTimezone(place) {
  if (isValidTimezone(place?.timezone)) return place.timezone

  const countryCode = String(place?.country_code || '').toUpperCase()
  const country = String(place?.country || '').trim().toLowerCase()
  const numericOffset = Number(place?.timezone || place?.time_zone || place?.tzone)

  if (countryCode === 'IN' || country === 'india' || numericOffset === 5.5) {
    return 'Asia/Kolkata'
  }

  const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone
  return isValidTimezone(browserTimezone) ? browserTimezone : ''
}

function isConfirmedNumber(value) {
  return value !== undefined && value !== null && value !== '' && Number.isFinite(Number(value))
}

function extractAshtakPoints(payload) {
  const points = payload?.ashtak_points
  return points && typeof points === 'object' ? points : null
}

function extractSavRows(points) {
  if (!points) return null

  const rows = SIGNS.map(({ key, label }) => {
    const total = points[key]?.total
    if (!isConfirmedNumber(total)) return null
    return { sign: label, sav: Number(total) }
  })

  return rows.every(Boolean) ? rows : null
}

function extractBavRows(points) {
  if (!points) return null

  const rows = SIGNS.map(({ key, label }) => {
    const row = points[key]
    if (!row || typeof row !== 'object') return null

    const values = {}
    for (const planet of PLANETS) {
      if (!isConfirmedNumber(row[planet])) return null
      values[planet] = Number(row[planet])
    }

    const rowTotal = PLANETS.reduce((sum, planet) => sum + values[planet], 0)

    return { sign: label, rowTotal, ...values }
  })

  return rows.every(Boolean) ? rows : null
}

function buildSavSummary(savRows) {
  if (!savRows?.length) return null

  const values = savRows.map((row) => row.sav)
  const total = values.reduce((sum, value) => sum + value, 0)
  const highestValue = Math.max(...values)
  const lowestValue = Math.min(...values)
  const highestSigns = savRows.filter((row) => row.sav === highestValue)
  const lowestSigns = savRows.filter((row) => row.sav === lowestValue)

  return {
    total,
    average: total / savRows.length,
    highestValue,
    lowestValue,
    highestSigns,
    lowestSigns,
    maxForBar: highestValue,
  }
}

function buildSavRanking(savRows) {
  if (!savRows?.length) return null

  return [...savRows]
    .sort((first, second) => second.sav - first.sav || first.sign.localeCompare(second.sign))
    .map((row, index) => ({
      rank: index + 1,
      sign: row.sign,
      sav: row.sav,
    }))
}

function buildPlanetTotals(bavRows) {
  if (!bavRows?.length) return null

  const totals = {}
  for (const planet of PLANETS) {
    totals[planet] = bavRows.reduce((sum, row) => sum + row[planet], 0)
  }

  totals.rowTotal = PLANETS.reduce((sum, planet) => sum + totals[planet], 0)
  return totals
}

function formatSignList(signs, points) {
  if (!signs?.length) return '—'
  return `${signs.map((row) => row.sign).join(', ')} (${points})`
}

function relativeBarWidth(value, max) {
  if (!Number.isFinite(value) || !Number.isFinite(max) || max <= 0) return 0
  return (value / max) * 100
}

function formatCoordinate(value) {
  return Number.isFinite(Number(value)) ? Number(value).toFixed(4) : '—'
}

function AshtakavargaTestPage() {
  const [dateOfBirth, setDateOfBirth] = useState('')
  const [timeDisplay, setTimeDisplay] = useState('')
  const [meridiem, setMeridiem] = useState('AM')
  const [placeOfBirth, setPlaceOfBirth] = useState('')
  const [placeDetails, setPlaceDetails] = useState(null)
  const [error, setError] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [result, setResult] = useState(null)

  const timezone = resolveBirthTimezone(placeDetails)
  const hasSelectedPlace = Boolean(
    placeDetails?.place_name
    && Number.isFinite(Number(placeDetails.latitude))
    && Number.isFinite(Number(placeDetails.longitude))
  )

  const ashtakPoints = extractAshtakPoints(result?.data)
  const savRows = extractSavRows(ashtakPoints)
  const savSummary = buildSavSummary(savRows)
  const savRanking = buildSavRanking(savRows)
  const bavRows = extractBavRows(ashtakPoints)
  const planetTotals = buildPlanetTotals(bavRows)

  const handlePlaceChange = (value) => {
    setPlaceOfBirth(value)
    setPlaceDetails(null)
    setError('')
  }

  const handleSubmit = async (event) => {
    event.preventDefault()
    setError('')

    const timeOfBirth = to24HourTime(normalizeTimeDisplay(timeDisplay), meridiem)
    if (!dateOfBirth || !timeOfBirth || !placeOfBirth.trim()) {
      setError('Please complete date, time, and birth place.')
      return
    }

    if (!hasSelectedPlace) {
      setError('Please select a birth place from the suggestions.')
      return
    }

    if (!timezone) {
      setError('Please select a birth place with a valid timezone.')
      return
    }

    setIsSubmitting(true)
    setResult(null)

    try {
      const response = await fetchAshtakavargaResearch({
        date_of_birth: dateOfBirth,
        time_of_birth: timeOfBirth,
        birth_place: placeOfBirth.trim(),
        latitude: Number(placeDetails.latitude),
        longitude: Number(placeDetails.longitude),
        timezone,
      })

      if (!response?.success) {
        setError(response?.message || 'Ashtakavarga API endpoint not yet configured.')
        return
      }

      setResult(response)
    } catch (requestError) {
      setError(requestError.message || 'Ashtakavarga API endpoint not yet configured.')
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <main className="ashtaka-test-page">
      <section className="home-hero-shell ashtaka-test-shell">
        <header className="home-hero-header" aria-label="Ashtakavarga research header">
          <div className="home-hero-brand">
            <img src={ygtLogo} alt="YGT" className="home-hero-logo" />
            <div className="home-hero-brand-copy">
              <h1>Your Good Time</h1>
              <p>R&amp;D · Ashtakavarga test</p>
            </div>
          </div>
          <span className="ashtaka-test-badge">Developer only</span>
        </header>

        <section className="ashtaka-test-content">
          <div className="ashtaka-test-title">
            <span>Research</span>
            <h2>Ashtakavarga Test</h2>
            <p>Isolated from Identity, Past Prediction, Future Prediction, payments, and authentication.</p>
          </div>

          <form className="past-form prediction-registration-form ashtaka-test-form" onSubmit={handleSubmit} autoComplete="off">
            <div className="past-birth-row">
              <label>
                Date of Birth
                <input
                  type="date"
                  value={dateOfBirth}
                  onChange={(event) => setDateOfBirth(event.target.value)}
                  required
                />
              </label>

              <label>
                Time of Birth
                <span className="past-field-caption">hh:mm AM/PM</span>
                <div className="past-time-of-birth">
                  <input
                    type="text"
                    inputMode="numeric"
                    autoComplete="off"
                    placeholder="hh:mm"
                    value={timeDisplay}
                    aria-label="Time of birth hours and minutes"
                    maxLength={5}
                    required
                    onChange={(event) => setTimeDisplay(formatTimeInput(event.target.value))}
                    onBlur={() => setTimeDisplay(normalizeTimeDisplay(timeDisplay))}
                  />
                  <select
                    className="past-meridiem"
                    value={meridiem}
                    aria-label="AM or PM"
                    onChange={(event) => setMeridiem(event.target.value)}
                  >
                    <option value="AM">AM</option>
                    <option value="PM">PM</option>
                  </select>
                </div>
              </label>
            </div>

            <label>
              Birth Place
              <PlaceAutocomplete
                value={placeOfBirth}
                onChange={handlePlaceChange}
                onSelect={setPlaceDetails}
                required
              />
              <span className="past-field-helper">Uses the existing location search. Select a suggestion.</span>
            </label>

            {hasSelectedPlace ? (
              <dl className="ashtaka-test-geo">
                <div>
                  <dt>Latitude</dt>
                  <dd>{formatCoordinate(placeDetails.latitude)}</dd>
                </div>
                <div>
                  <dt>Longitude</dt>
                  <dd>{formatCoordinate(placeDetails.longitude)}</dd>
                </div>
                <div>
                  <dt>Timezone</dt>
                  <dd>{timezone || placeDetails.timezone || '—'}</dd>
                </div>
              </dl>
            ) : null}

            {error ? <p className="past-error">{error}</p> : null}

            <button type="submit" className="past-submit" disabled={isSubmitting}>
              {isSubmitting ? 'Loading Ashtakavarga...' : 'Get Ashtakavarga'}
            </button>
          </form>

          {result ? (
            <section className="ashtaka-test-results" aria-label="Ashtakavarga results">
              <article className="ashtaka-test-card">
                <h3>Sarvashtakavarga</h3>
                {savSummary ? (
                  <dl className="ashtaka-test-summary">
                    <div>
                      <dt>Total SAV Points</dt>
                      <dd>{savSummary.total}</dd>
                    </div>
                    <div>
                      <dt>Average per Sign</dt>
                      <dd>{savSummary.average.toFixed(2)}</dd>
                    </div>
                    <div>
                      <dt>Highest</dt>
                      <dd>{formatSignList(savSummary.highestSigns, savSummary.highestValue)}</dd>
                    </div>
                    <div>
                      <dt>Lowest</dt>
                      <dd>{formatSignList(savSummary.lowestSigns, savSummary.lowestValue)}</dd>
                    </div>
                  </dl>
                ) : null}
                <div className="ashtaka-test-table-wrap">
                  <table className="ashtaka-test-sav-table">
                    <thead>
                      <tr>
                        <th>Sign</th>
                        <th>SAV Points</th>
                        <th>Relative Strength</th>
                      </tr>
                    </thead>
                    <tbody>
                      {SIGNS.map(({ label }, index) => {
                        const sav = savRows?.[index]?.sav
                        const isHighest = savSummary && sav === savSummary.highestValue
                        const isLowest = savSummary && sav === savSummary.lowestValue
                        const rowClass = [
                          isHighest ? 'is-highest' : '',
                          isLowest ? 'is-lowest' : '',
                        ].filter(Boolean).join(' ')

                        return (
                          <tr key={label} className={rowClass || undefined}>
                            <td>{label}</td>
                            <td>{sav ?? ''}</td>
                            <td>
                              {Number.isFinite(sav) && savSummary ? (
                                <div
                                  className="ashtaka-test-bar"
                                  aria-label={`${sav} of ${savSummary.maxForBar}`}
                                >
                                  <span
                                    style={{ width: `${relativeBarWidth(sav, savSummary.maxForBar)}%` }}
                                  />
                                </div>
                              ) : null}
                            </td>
                          </tr>
                        )
                      })}
                    </tbody>
                  </table>
                </div>
                {!savRows ? (
                  <p className="ashtaka-test-note">SAV values are shown only when `ashtak_points.[sign].total` is present.</p>
                ) : null}
              </article>

              <article className="ashtaka-test-card">
                <h3>Bhinna Ashtakavarga</h3>
                <div className="ashtaka-test-table-wrap">
                  <table>
                    <thead>
                      <tr>
                        <th>Sign</th>
                        {PLANETS.map((planet) => (
                          <th key={planet}>{PLANET_LABELS[planet]}</th>
                        ))}
                        <th>Total</th>
                      </tr>
                    </thead>
                    <tbody>
                      {SIGNS.map(({ label }, index) => (
                        <tr key={label}>
                          <td>{label}</td>
                          {PLANETS.map((planet) => (
                            <td key={planet}>{bavRows?.[index]?.[planet] ?? ''}</td>
                          ))}
                          <td>{bavRows?.[index]?.rowTotal ?? ''}</td>
                        </tr>
                      ))}
                    </tbody>
                    <tfoot>
                      <tr>
                        <th scope="row">Planet Total</th>
                        {PLANETS.map((planet) => (
                          <td key={planet}>{planetTotals?.[planet] ?? ''}</td>
                        ))}
                        <td>{planetTotals?.rowTotal ?? ''}</td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
                {!bavRows ? (
                  <p className="ashtaka-test-note">BAV values are shown only when `ashtak_points.[sign]` planet fields are present.</p>
                ) : null}
              </article>

              <article className="ashtaka-test-card">
                <h3>Ashtakavarga Sign Strength</h3>
                <p className="ashtaka-test-note">Mathematical ranking of returned SAV values only.</p>
                <div className="ashtaka-test-table-wrap">
                  <table className="ashtaka-test-rank-table">
                    <thead>
                      <tr>
                        <th>Rank</th>
                        <th>Sign</th>
                        <th>SAV Points</th>
                      </tr>
                    </thead>
                    <tbody>
                      {savRanking ? savRanking.map((row) => (
                        <tr key={row.sign}>
                          <td>{row.rank}</td>
                          <td>{row.sign}</td>
                          <td>{row.sav}</td>
                        </tr>
                      )) : SIGNS.map(({ label }) => (
                        <tr key={label}>
                          <td></td>
                          <td>{label}</td>
                          <td></td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </article>

              <details className="ashtaka-test-card ashtaka-test-raw">
                <summary>
                  <h3>Raw API Response</h3>
                  <p className="ashtaka-test-endpoint">Endpoint used: {result.endpoint || 'unknown'}</p>
                </summary>
                <pre className="ashtaka-test-json">{JSON.stringify(result, null, 2)}</pre>
              </details>
            </section>
          ) : null}
        </section>
      </section>
    </main>
  )
}

export default AshtakavargaTestPage
