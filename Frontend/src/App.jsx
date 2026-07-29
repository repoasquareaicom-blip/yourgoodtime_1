import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import HomePage from './pages/HomePage'
import IntroPage from './pages/IntroPage'
import LanguageSelectionPage from './pages/LanguageSelectionPage'
import SplashPage from './pages/SplashPage'
import SettingsPage from './pages/SettingsPage'
import VideoLibraryPage from './pages/VideoLibraryPage'
import LoginPage from './pages/LoginPage'
import AccountPage from './pages/AccountPage'
import DailyPage from './pages/DailyPage'
import PastPredictionPage from './pages/PastPredictionPage'
import './App.css'

function App() {
  const location = useLocation()
  const backgroundLocation = location.state?.backgroundLocation

  return (
    <div className="app-shell">
      <Routes location={backgroundLocation || location}>
        <Route path="/" element={<Navigate to="/splash" replace />} />
        <Route path="/splash" element={<SplashPage />} />
        <Route path="/language" element={<LanguageSelectionPage />} />
        <Route path="/intro" element={<IntroPage />} />
        <Route path="/home" element={<HomePage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/account" element={<AccountPage />} />
        <Route path="/daily" element={<DailyPage />} />
        <Route path="/videos" element={<VideoLibraryPage />} />
        <Route path="/past-prediction" element={<PastPredictionPage />} />
        <Route path="/settings" element={<SettingsPage />} />
        <Route path="*" element={<Navigate to="/splash" replace />} />
      </Routes>

      {backgroundLocation ? (
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/past-prediction" element={<PastPredictionPage />} />
        </Routes>
      ) : null}
    </div>
  )
}

export default App
