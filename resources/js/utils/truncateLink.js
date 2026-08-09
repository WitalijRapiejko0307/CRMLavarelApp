export function truncateLinkDisplay(url, maxLength = 20) {
  const display = url.replace(/^https?:\/\//i, '')
  return display.length <= maxLength ? display : display.slice(0, maxLength) + '…'
}
