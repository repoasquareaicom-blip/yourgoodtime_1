import ygtLogo from '../../assets/yt-logo.png'

function PredictionCelebration({ onContinue, summary, playlist }) {
  const predictionPeriodCount = summary?.grouped_result_count ?? Math.max((playlist?.length || 1) - 1, 0)
  const videoCount = playlist?.reduce((total, group) => total + group.videos.length, 0) || 0

  return (
    <div className="past-ready-screen">
      <div className="past-ready-hero">
        <div className="past-ready-logo-wrap">
          <img src={ygtLogo} alt="Your Good Time" className="past-ready-logo" />
          <span className="past-ready-orbit" aria-hidden="true" />
        </div>

        <h1 id="past-ready-title">Your Past Prediction Is Ready</h1>

        <p className="past-ready-brand">
          <span>Know Your Past.</span>
          <span>Shape Your Future.</span>
        </p>

        <p className="past-ready-copy">Your personalised astrological journey has been prepared.</p>
      </div>

      <div className="past-ready-detail">
        <dl className="past-ready-stats">
          <div>
            <dt>Prediction periods</dt>
            <dd>{predictionPeriodCount}</dd>
          </div>
          <div>
            <dt>Videos</dt>
            <dd>{videoCount}</dd>
          </div>
          <div>
            <dt>Status</dt>
            <dd>Ready</dd>
          </div>
        </dl>

        <div className="past-processing__actions is-ready-action">
          <button type="button" className="past-processing__primary" onClick={onContinue}>
            <span className="past-processing__play" aria-hidden="true" />
            <span>Play My Past Prediction</span>
          </button>
        </div>
      </div>
    </div>
  )
}

export default PredictionCelebration
