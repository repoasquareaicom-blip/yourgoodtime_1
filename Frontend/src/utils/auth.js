const TOKEN_KEY = 'auth_token'
const USER_KEY = 'auth_user'
const ADMIN_TOKEN_KEY = 'admin_auth_token'
const ADMIN_USER_KEY = 'admin_auth_user'

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

export function saveAdminToken(token) {
  if (!token) {
    removeAdminToken()
    return
  }

  writeStorageValue(ADMIN_TOKEN_KEY, token)
}

export function getAdminToken() {
  return readStorageValue(ADMIN_TOKEN_KEY)
}

export function removeAdminToken() {
  removeStorageValue(ADMIN_TOKEN_KEY)
}

export function saveAdminUser(user) {
  if (!user) {
    removeAdminUser()
    return
  }

  writeStorageValue(ADMIN_USER_KEY, JSON.stringify(user))
}

export function getAdminUser() {
  const storedUser = readStorageValue(ADMIN_USER_KEY)

  if (!storedUser) {
    return null
  }

  try {
    return JSON.parse(storedUser)
  } catch {
    removeAdminUser()
    return null
  }
}

export function removeAdminUser() {
  removeStorageValue(ADMIN_USER_KEY)
}

export function isAdminLoggedIn() {
  return Boolean(getAdminToken())
}
