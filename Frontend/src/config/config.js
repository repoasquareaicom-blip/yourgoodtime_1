const hostApiUrls = {
  localhost: 'http://localhost:8000/api',
  '127.0.0.1': 'http://localhost:8000/api',
  'dev.yourgoodtime.in': 'https://api.yourgoodtime.in/api',
  'yourgoodtime.in': 'https://api.yourgoodtime.in/api',
  'www.yourgoodtime.in': 'https://api.yourgoodtime.in/api',
}

const environment = import.meta.env.MODE || 'production'
const appName = import.meta.env.VITE_APP_NAME || 'Your Good Time'

// Environment variable override:
// Set VITE_API_URL when a deployment needs an explicit API endpoint.
// This takes priority over hostname detection.
const apiUrlOverride = import.meta.env.VITE_API_URL?.trim()
const isPrivateDevelopmentHost = /^(10\.\d{1,3}\.\d{1,3}\.\d{1,3}|192\.168\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2\d|3[0-1])\.\d{1,3}\.\d{1,3})$/.test(window.location.hostname)
const isLocalDevelopmentHost = window.location.hostname === 'localhost'
  || window.location.hostname === '127.0.0.1'
  || isPrivateDevelopmentHost
const localNetworkApiUrl = `${window.location.protocol}//${window.location.hostname}:8000/api`

// Environment detection:
// When VITE_API_URL is not provided, choose the API URL from the browser hostname.
// Unknown hostnames intentionally fall back to production API.
const detectedApiUrl = environment === 'development' && isLocalDevelopmentHost
  ? '/api'
  : hostApiUrls[window.location.hostname]
    || (isPrivateDevelopmentHost ? localNetworkApiUrl : 'https://api.yourgoodtime.in/api')

const apiUrl = apiUrlOverride || detectedApiUrl

// Future environments:
// Add a hostname and API URL to hostApiUrls above, or set VITE_API_URL in the
// hosting environment for one-off staging, preview, or branch deployments.
export default {
  apiUrl,
  appName,
  environment,
  isDevelopment: environment === 'development',
  isProduction: environment === 'production',
}
