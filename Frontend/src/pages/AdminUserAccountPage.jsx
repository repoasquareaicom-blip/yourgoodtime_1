import { useParams } from 'react-router-dom'
import AccountPage from './AccountPage'

export default function AdminUserAccountPage() {
  const { userId } = useParams()

  return <AccountPage key={userId} previewUserId={userId} />
}
