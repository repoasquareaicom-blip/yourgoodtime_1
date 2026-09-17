export function isCurtainVideo(videoId) {
  const id = Number.parseInt(String(videoId ?? ''), 10)

  if (!Number.isFinite(id)) {
    return false
  }

  return (id >= 505 && id <= 576) || (id >= 625 && id <= 628)
}
