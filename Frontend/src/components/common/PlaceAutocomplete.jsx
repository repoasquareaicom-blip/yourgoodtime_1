import { useEffect, useId, useRef, useState } from 'react'
import { searchLocations } from '../../services/locationService'
import './PlaceAutocomplete.css'

function PlaceAutocomplete({
  value,
  onChange,
  onSelect,
  required = false,
  disabled = false,
}) {
  const listboxId = useId()
  const abortRef = useRef(null)
  const cacheRef = useRef(new Map())
  const debounceRef = useRef(null)
  const hasUserEditedRef = useRef(false)
  const lastScheduledQueryRef = useRef('')
  const lastSuccessfulQueryRef = useRef('')
  const requestIdRef = useRef(0)
  const rootRef = useRef(null)
  const selectedNormalizedRef = useRef('')
  const [query, setQuery] = useState(value || '')
  const [places, setPlaces] = useState([])
  const [status, setStatus] = useState('idle')
  const [errorMessage, setErrorMessage] = useState('')
  const [isOpen, setIsOpen] = useState(false)
  const [activeIndex, setActiveIndex] = useState(-1)

  useEffect(() => {
    setQuery(value || '')
  }, [value])

  useEffect(() => () => {
    window.clearTimeout(debounceRef.current)
    abortRef.current?.abort()
  }, [])

  useEffect(() => {
    const handlePointerDown = (event) => {
      if (!rootRef.current?.contains(event.target)) {
        setIsOpen(false)
      }
    }

    document.addEventListener('pointerdown', handlePointerDown)

    return () => document.removeEventListener('pointerdown', handlePointerDown)
  }, [])

  useEffect(() => {
    window.clearTimeout(debounceRef.current)
    abortRef.current?.abort()
    setActiveIndex(-1)
    setErrorMessage('')

    if (!hasUserEditedRef.current) return undefined

    const trimmedQuery = query.trim()
    const normalizedQuery = trimmedQuery.toLowerCase()

    if (!normalizedQuery) {
      selectedNormalizedRef.current = ''
      setPlaces([])
      setStatus('idle')
      setIsOpen(false)
      return undefined
    }

    if (normalizedQuery.length < 3) {
      setPlaces([])
      setStatus('minimum')
      setIsOpen(true)
      return undefined
    }

    if (normalizedQuery === selectedNormalizedRef.current) {
      setStatus('idle')
      setIsOpen(false)
      return undefined
    }

    if (cacheRef.current.has(normalizedQuery)) {
      const cachedResults = cacheRef.current.get(normalizedQuery)
      setPlaces(cachedResults)
      setStatus(cachedResults.length ? 'ready' : 'empty')
      setIsOpen(true)
      return undefined
    }

    if (normalizedQuery === lastSuccessfulQueryRef.current) {
      return undefined
    }

    lastScheduledQueryRef.current = normalizedQuery

    debounceRef.current = window.setTimeout(async () => {
      if (lastScheduledQueryRef.current !== normalizedQuery) return

      const requestId = requestIdRef.current + 1
      requestIdRef.current = requestId

      const controller = new AbortController()
      abortRef.current = controller
      setStatus('loading')
      setIsOpen(true)

      try {
        if (import.meta.env.DEV) {
          console.log('Birthplace search query:', trimmedQuery)
        }

        const results = await searchLocations(trimmedQuery, {
          signal: controller.signal,
        })

        if (requestId !== requestIdRef.current) return

        const sortedResults = [...results].sort((firstPlace, secondPlace) => {
          const firstIsIndia = String(firstPlace.country_code || '').toUpperCase() === 'IN'
            || String(firstPlace.country || '').toLowerCase() === 'india'
          const secondIsIndia = String(secondPlace.country_code || '').toUpperCase() === 'IN'
            || String(secondPlace.country || '').toLowerCase() === 'india'

          if (firstIsIndia === secondIsIndia) return 0
          return firstIsIndia ? -1 : 1
        })

        if (import.meta.env.DEV) {
          console.log('Birthplace search results:', sortedResults)
        }

        cacheRef.current.set(normalizedQuery, sortedResults)
        lastSuccessfulQueryRef.current = normalizedQuery
        setPlaces(sortedResults)
        setStatus(sortedResults.length ? 'ready' : 'empty')
      } catch (error) {
        if (error.name === 'AbortError') return
        if (requestId !== requestIdRef.current) return

        if (import.meta.env.DEV) {
          console.error('Place autocomplete request failed', {
            message: error.message,
            requestUrl: error.requestUrl,
            status: error.status,
            responseBody: error.responseBody,
          })
        }

        setErrorMessage(error.message === 'VITE_JH_API_URL is not configured'
          ? 'Location search is not configured'
          : 'Unable to fetch places')
        setStatus('error')
      }
    }, 300)

    return () => window.clearTimeout(debounceRef.current)
  }, [query])

  const handleInputChange = (event) => {
    const nextValue = event.target.value
    const normalizedValue = nextValue.trim().toLowerCase()

    hasUserEditedRef.current = true
    setQuery(nextValue)
    onChange(nextValue)

    if (!normalizedValue) {
      window.clearTimeout(debounceRef.current)
      abortRef.current?.abort()
      selectedNormalizedRef.current = ''
      setPlaces([])
      setStatus('idle')
      setIsOpen(false)
      onSelect(null)
      return
    }

    if (normalizedValue !== selectedNormalizedRef.current) {
      selectedNormalizedRef.current = ''
      setIsOpen(true)
    }
  }

  const selectPlace = (place) => {
    const selectedPlace = {
      place_name: place.place_name || '',
      country: place.country || '',
      country_code: String(place.country_code || '').toUpperCase(),
      timezone: place.timezone || '',
      latitude: Number(place.latitude),
      longitude: Number(place.longitude),
    }

    hasUserEditedRef.current = false
    selectedNormalizedRef.current = selectedPlace.place_name.trim().toLowerCase()
    cacheRef.current.set(selectedNormalizedRef.current, [selectedPlace])
    window.clearTimeout(debounceRef.current)
    abortRef.current?.abort()
    setQuery(selectedPlace.place_name)
    setPlaces([selectedPlace])
    setStatus('idle')
    setIsOpen(false)
    setActiveIndex(-1)
    onChange(selectedPlace.place_name)
    onSelect(selectedPlace)
  }

  const handleFocus = () => {
    const normalizedQuery = query.trim().toLowerCase()

    if (!normalizedQuery || normalizedQuery === selectedNormalizedRef.current) {
      setIsOpen(false)
      return
    }

    if (cacheRef.current.has(normalizedQuery)) {
      const cachedResults = cacheRef.current.get(normalizedQuery)
      setPlaces(cachedResults)
      setStatus(cachedResults.length ? 'ready' : 'empty')
      setIsOpen(true)
    }
  }

  const handleKeyDown = (event) => {
    if (!isOpen && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
      handleFocus()
      return
    }

    if (event.key === 'Escape') {
      setIsOpen(false)
      return
    }

    if (!places.length) return

    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setActiveIndex((current) => (current + 1) % places.length)
    }

    if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActiveIndex((current) => (current <= 0 ? places.length - 1 : current - 1))
    }

    if (event.key === 'Enter' && activeIndex >= 0) {
      event.preventDefault()
      selectPlace(places[activeIndex])
    }
  }

  const shouldShowPanel = isOpen && status !== 'idle'

  return (
    <div className={`place-autocomplete ${shouldShowPanel ? 'is-open' : ''}`} ref={rootRef}>
      <div className="place-autocomplete__field">
        <span className="place-autocomplete__search" aria-hidden="true" />
        <input
          type="text"
          value={query}
          disabled={disabled}
          onChange={handleInputChange}
          onFocus={handleFocus}
          onKeyDown={handleKeyDown}
          placeholder="Select exact birth address"
          autoComplete="off"
          required={required}
          role="combobox"
          aria-expanded={shouldShowPanel}
          aria-controls={listboxId}
          aria-autocomplete="list"
          aria-activedescendant={activeIndex >= 0 ? `${listboxId}-${activeIndex}` : undefined}
        />
        {status === 'loading' ? <span className="place-autocomplete__spinner" aria-hidden="true" /> : null}
      </div>

      {shouldShowPanel ? (
        <div className="place-autocomplete__panel" id={listboxId} role="listbox">
          {status === 'minimum' ? (
            <div className="place-autocomplete__state">Type at least 3 letters</div>
          ) : null}

          {status === 'loading' ? (
            <div className="place-autocomplete__state">Searching places...</div>
          ) : null}

          {status === 'empty' ? (
            <div className="place-autocomplete__state">No places found</div>
          ) : null}

          {status === 'error' ? (
            <div className="place-autocomplete__state is-error">{errorMessage || 'Unable to fetch places'}</div>
          ) : null}

          {status === 'ready' ? places.map((place, index) => (
            <button
              type="button"
              id={`${listboxId}-${index}`}
              key={`${place.place_name}-${place.country}-${place.latitude}-${place.longitude}`}
              className={`place-autocomplete__option ${activeIndex === index ? 'is-active' : ''}`}
              onMouseEnter={() => setActiveIndex(index)}
              onClick={() => selectPlace(place)}
              role="option"
              aria-selected={activeIndex === index}
            >
              <span className="place-autocomplete__place">{place.place_name}</span>
              <span className="place-autocomplete__country">{place.country}</span>
            </button>
          )) : null}
        </div>
      ) : null}
    </div>
  )
}

export default PlaceAutocomplete
