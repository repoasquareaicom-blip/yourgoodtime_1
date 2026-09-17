import { Link } from 'react-router-dom'
import { scrollPageToTop } from '../../utils/scrollPageToTop'

function AppFooter() {
  return (
    <footer className="app-footer" aria-label="Legal footer">
      <p>Copyright {'\u00a9'} Your Good Time. All rights reserved.</p>
      <nav aria-label="Legal links">
        <Link to="/privacy-policy" onClick={scrollPageToTop}>Privacy Policy</Link>
        <Link to="/terms-and-conditions" onClick={scrollPageToTop}>Terms & Conditions</Link>
        <Link to="/disclaimer" onClick={scrollPageToTop}>Disclaimer</Link>
        <Link to="/refund-policy" onClick={scrollPageToTop}>Refund & Cancellation Policy</Link>
      </nav>
    </footer>
  )
}

export default AppFooter
