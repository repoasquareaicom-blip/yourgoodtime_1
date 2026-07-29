import PredictionStep from './PredictionStep'

function PredictionTimeline({ steps, activeIndex, isIntroStage = false }) {
  return (
    <ol className={`past-processing__steps ${isIntroStage ? 'is-intro' : ''}`}>
      {steps.map((step, index) => {
        const status = isIntroStage
          ? 'pending'
          : index < activeIndex
            ? 'completed'
            : index === activeIndex
              ? 'active'
              : 'pending'

        return (
          <PredictionStep
            key={step.id}
            index={index}
            step={step}
            status={status}
          />
        )
      })}
    </ol>
  )
}

export default PredictionTimeline
