export const languageOptions = [
  { code: 'ta', name: 'Tamil' },
  { code: 'en', name: 'English' },
  { code: 'hi', name: 'Hindi' },
]

export function getStoredLanguageName() {
  return window.localStorage.getItem('ygtPreferredLanguageName') || 'Not selected'
}

export function savePreferredLanguage(language) {
  window.localStorage.setItem('ygtPreferredLanguage', language.code)
  window.localStorage.setItem('ygtPreferredLanguageName', language.name)
}
