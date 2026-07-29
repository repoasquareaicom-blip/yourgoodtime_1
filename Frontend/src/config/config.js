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

// Environment detection:
// When VITE_API_URL is not provided, choose the API URL from the browser hostname.
// Unknown hostnames intentionally fall back to production API.
const detectedApiUrl = hostApiUrls[window.location.hostname] || 'https://api.yourgoodtime.in/api'

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
