const TOKEN_KEY = 'auth_token'
const USER_KEY = 'auth_user'

function readStorageValue(key) {
  try {
    return window.localStorage.getItem(key)
  } catch {
    return null
  }
}

function writeStorageValue(key, value) {
  try {
    window.localStorage.setItem(key, value)
  } catch {
    // Ignore storage write failures so auth requests can still complete.
  }
}

function removeStorageValue(key) {
  try {
    window.localStorage.removeItem(key)
  } catch {
    // Ignore storage removal failures.
  }
}

export function saveToken(token) {
  if (!token) {
    removeToken()
    return
  }

  writeStorageValue(TOKEN_KEY, token)
}

export function getToken() {
  return readStorageValue(TOKEN_KEY)
}

export function removeToken() {
  removeStorageValue(TOKEN_KEY)
}

export function saveUser(user) {
  if (!user) {
    removeUser()
    return
  }

  writeStorageValue(USER_KEY, JSON.stringify(user))
}

export function getUser() {
  const storedUser = readStorageValue(USER_KEY)

  if (!storedUser) {
    return null
  }

  try {
    return JSON.parse(storedUser)
  } catch {
    removeUser()
    return null
  }
}

export function removeUser() {
  removeStorageValue(USER_KEY)
}

export function isLoggedIn() {
  return Boolean(getToken())
}
