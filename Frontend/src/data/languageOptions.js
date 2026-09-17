export const languageOptions = [
  { code: 'ta', name: 'Tamil', nativeName: 'தமிழ்' },
  { code: 'en', name: 'English', nativeName: 'English' },
  { code: 'hi', name: 'Hindi', nativeName: 'हिन्दी' },
]

export const LANGUAGE_STORAGE_KEY = 'ygtPreferredLanguage'
export const LANGUAGE_NAME_STORAGE_KEY = 'ygtPreferredLanguageName'
export const LANGUAGE_CHANGE_EVENT = 'ygt-language-change'

export function getLanguageByCode(code) {
  const normalizedCode = String(code || '').trim()
  return languageOptions.find((language) => language.code === normalizedCode) || null
}

export function isValidLanguageCode(code) {
  return Boolean(getLanguageByCode(code))
}

function readLocalStorage(key) {
  if (typeof window === 'undefined') return null

  try {
    return window.localStorage.getItem(key)
  } catch {
    return null
  }
}

function writeLocalStorage(key, value) {
  if (typeof window === 'undefined') return

  try {
    window.localStorage.setItem(key, value)
  } catch {
    // Local storage can be unavailable in private browser contexts.
  }
}

function removeLocalStorage(key) {
  if (typeof window === 'undefined') return

  try {
    window.localStorage.removeItem(key)
  } catch {
    // Local storage can be unavailable in private browser contexts.
  }
}

function emitLanguageChange(language) {
  if (typeof window === 'undefined') return

  window.dispatchEvent(new CustomEvent(LANGUAGE_CHANGE_EVENT, { detail: language }))
}

export function getStoredLanguage() {
  const storedCode = readLocalStorage(LANGUAGE_STORAGE_KEY)
  const language = getLanguageByCode(storedCode)

  if (!storedCode || language) return language

  removeLocalStorage(LANGUAGE_STORAGE_KEY)
  removeLocalStorage(LANGUAGE_NAME_STORAGE_KEY)
  return null
}

export function getStoredLanguageCode() {
  return getStoredLanguage()?.code || ''
}

export function getStoredLanguageName() {
  return getStoredLanguage()?.name || 'Not selected'
}

export function savePreferredLanguage(language) {
  const selectedLanguage = typeof language === 'string'
    ? getLanguageByCode(language)
    : getLanguageByCode(language?.code)

  if (!selectedLanguage) {
    removeLocalStorage(LANGUAGE_STORAGE_KEY)
    removeLocalStorage(LANGUAGE_NAME_STORAGE_KEY)
    return null
  }

  writeLocalStorage(LANGUAGE_STORAGE_KEY, selectedLanguage.code)
  writeLocalStorage(LANGUAGE_NAME_STORAGE_KEY, selectedLanguage.name)
  emitLanguageChange(selectedLanguage)
  return selectedLanguage
}
