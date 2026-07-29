import api from './api'

export async function submitPredictionFeedback({ predictionRequestId, requestUuid, predictionResultId, predictionType = 'past', rating, accuracyBand, comments }) {
  const response = await api.post(`/predictions/${predictionRequestId}/feedback`, {
    request_uuid: requestUuid,
    prediction_result_id: predictionResultId,
    prediction_type: predictionType,
    rating,
    accuracy_band: accuracyBand,
    comments,
  })

  return response.data
}
