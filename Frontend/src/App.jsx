import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import HomePage from './pages/HomePage'
import IntroPage from './pages/IntroPage'
import LanguageSelectionPage from './pages/LanguageSelectionPage'
import SplashPage from './pages/SplashPage'
import SettingsPage from './pages/SettingsPage'
import VideoLibraryPage from './pages/VideoLibraryPage'
import LoginPage from './pages/LoginPage'
import AccountPage from './pages/AccountPage'
import AccountLoadingPage from './pages/AccountLoadingPage'
import AdminPage from './pages/AdminPage'
import AdminUserAccountPage from './pages/AdminUserAccountPage'
import AdminGalleryVideoPreviewPage from './pages/AdminGalleryVideoPreviewPage'
import AdminLoginPage from './pages/AdminLoginPage'
import DailyPage from './pages/DailyPage'
import AshtakavargaTestPage from './pages/AshtakavargaTestPage'
import PastPredictionPage from './pages/PastPredictionPage'
import LegalDocumentPage from './pages/LegalDocumentPage'
import ProtectedRoute from './components/common/ProtectedRoute'
import AdminProtectedRoute from './components/common/AdminProtectedRoute'
import ChatbotWidget from './components/common/ChatbotWidget'
import './App.css'

function App() {
  const location = useLocation()
  const backgroundLocation = location.state?.backgroundLocation
  const chatbotHiddenPaths = ['/splash', '/language', '/intro', '/login', '/admin-login', '/admin', '/ashtakavarga-test']
  const shouldShowChatbot = !backgroundLocation && !chatbotHiddenPaths.some((path) => (
    location.pathname === path || location.pathname.startsWith(`${path}/`)
  ))

  return (
    <div className="app-shell">
      <Routes location={backgroundLocation || location}>
        <Route path="/" element={<Navigate to="/splash" replace />} />
        <Route path="/splash" element={<SplashPage />} />
        <Route path="/language" element={<LanguageSelectionPage />} />
        <Route path="/intro" element={<IntroPage />} />
        <Route path="/home" element={<HomePage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/admin-login" element={<AdminLoginPage />} />
        <Route path="/account-loading" element={<ProtectedRoute><AccountLoadingPage /></ProtectedRoute>} />
        <Route path="/account" element={<ProtectedRoute><AccountPage /></ProtectedRoute>} />
        <Route path="/dashboard" element={<ProtectedRoute><AccountPage /></ProtectedRoute>} />
        <Route path="/profile" element={<ProtectedRoute><AccountPage initialSection="profile" /></ProtectedRoute>} />
        <Route path="/my-predictions" element={<ProtectedRoute><AccountPage initialSection="predictions" /></ProtectedRoute>} />
        <Route path="/future" element={<ProtectedRoute><AccountLoadingPage /></ProtectedRoute>} />
        <Route path="/remedy" element={<ProtectedRoute><AccountPage initialSection="remedies" /></ProtectedRoute>} />
        <Route path="/appointment" element={<ProtectedRoute><AccountPage initialSection="appointments" /></ProtectedRoute>} />
        <Route path="/admin" element={<AdminProtectedRoute><AdminPage /></AdminProtectedRoute>} />
        <Route path="/admin/users/:userId/account" element={<AdminProtectedRoute><AdminUserAccountPage /></AdminProtectedRoute>} />
        <Route path="/admin/gallery-videos/:id/preview" element={<AdminProtectedRoute><AdminGalleryVideoPreviewPage /></AdminProtectedRoute>} />
        <Route path="/daily" element={<DailyPage />} />
        <Route path="/videos/:videoId/:slug" element={<VideoLibraryPage />} />
        <Route path="/videos/:videoId" element={<VideoLibraryPage />} />
        <Route path="/videos" element={<VideoLibraryPage />} />
        <Route path="/past-prediction" element={<PastPredictionPage />} />
        <Route path="/ashtakavarga-test" element={<AshtakavargaTestPage />} />
        <Route path="/settings" element={<SettingsPage />} />
        <Route path="/terms-and-conditions" element={<LegalDocumentPage type="terms" />} />
        <Route path="/privacy-policy" element={<LegalDocumentPage type="privacy" />} />
        <Route path="/refund-policy" element={<LegalDocumentPage type="refund" />} />
        <Route path="/disclaimer" element={<LegalDocumentPage type="disclaimer" />} />
        <Route path="*" element={<Navigate to="/splash" replace />} />
      </Routes>

      {backgroundLocation ? (
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/past-prediction" element={<PastPredictionPage />} />
        </Routes>
      ) : null}

      {shouldShowChatbot ? <ChatbotWidget /> : null}
    </div>
  )
}

export default App
