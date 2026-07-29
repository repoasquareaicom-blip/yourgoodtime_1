const locationApiUrl = import.meta.env.VITE_JH_API_URL?.trim()

function buildLocationUrl(path) {
  if (!locationApiUrl) {
    throw new Error('VITE_JH_API_URL is not configured')
  }

  const baseUrl = locationApiUrl.endsWith('/') ? locationApiUrl : `${locationApiUrl}/`
  const relativePath = path.replace(/^\/+/, '')

  return new URL(relativePath, baseUrl)
}

function firstValue(source, keys) {
  for (const key of keys) {
    const value = source?.[key]

    if (value !== undefined && value !== null && value !== '') {
      return value
    }
  }

  return ''
}

function buildPlaceName(location) {
  const displayName = firstValue(location, ['displayName', 'display_name', 'label'])
  if (displayName) return String(displayName).trim()

  const name = firstValue(location, ['place_name', 'placeName', 'place', 'name', 'city'])
  const state = firstValue(location, ['state', 'region', 'province', 'admin1'])

  if (!name || !state || String(name).toLowerCase().includes(String(state).toLowerCase())) {
    return String(name || '').trim()
  }

  return `${name}, ${state}`.trim()
}

function normalizeLocation(location) {
  const latitude = Number(firstValue(location, ['latitude', 'lat']))
  const longitude = Number(firstValue(location, ['longitude', 'lon', 'lng']))
  const countryCode = firstValue(location, ['country_code', 'countryCode', 'iso2', 'country_iso2'])
  const timezone = firstValue(location, ['timezone', 'time_zone', 'timeZone', 'tzid', 'tz'])

  return {
    place_name: buildPlaceName(location),
    country: String(firstValue(location, ['country', 'country_name', 'countryName']) || '').trim(),
    country_code: String(countryCode || '').toUpperCase(),
    timezone,
    latitude,
    longitude,
    elevation: Number(firstValue(location, ['elevation', 'altitude'])),
    state: String(firstValue(location, ['state', 'region', 'province', 'admin1']) || '').trim(),
  }
}

function unwrapLocations(payload) {
  if (Array.isArray(payload)) return payload
  if (Array.isArray(payload?.data)) return payload.data
  if (Array.isArray(payload?.results)) return payload.results
  if (Array.isArray(payload?.locations)) return payload.locations
  if (Array.isArray(payload?.suggestions)) return payload.suggestions

  return []
}

export async function searchLocations(query, options = {}) {
  const trimmedQuery = query.trim()

  if (trimmedQuery.length < 3) {
    return []
  }

  const url = buildLocationUrl('/location/autocomplete')
  url.searchParams.set('q', trimmedQuery)

  const response = await fetch(url, {
    headers: {
      Accept: 'application/json',
    },
    signal: options.signal,
  })
  const responseBody = await response.text()

  if (!response.ok) {
    const error = new Error(`HTTP ${response.status}`)
    error.requestUrl = url.toString()
    error.status = response.status
    error.responseBody = responseBody
    throw error
  }

  let payload = {}

  try {
    payload = responseBody ? JSON.parse(responseBody) : {}
  } catch {
    const error = new Error('Invalid JSON response')
    error.requestUrl = url.toString()
    error.status = response.status
    error.responseBody = responseBody
    throw error
  }

  return unwrapLocations(payload)
    .map(normalizeLocation)
    .filter((location) => (
      location.place_name
      && Number.isFinite(location.latitude)
      && Number.isFinite(location.longitude)
    ))
}
