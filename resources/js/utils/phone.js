/**
 * Display phone as +375 XX XXX-XX-XX (uses last 9 digits).
 */
export function formatPhone(phone) {
    if (!phone) return ''
    const p = String(phone).replace(/\D/g, '')
    return p.length >= 9
        ? '+375 ' + p.slice(-9, -7) + ' ' + p.slice(-7, -4) + '-' + p.slice(-4, -2) + '-' + p.slice(-2)
        : phone
}

/**
 * Whether full name has at least 3 parts of 2+ chars (Belpost requirement).
 */
export function isFullNameComplete(name) {
    if (!name) return false
    const parts = name.trim().replace(/\s+/g, ' ').split(' ').filter(Boolean)
    return parts.length >= 3 && parts.every(p => p.length >= 2)
}

/**
 * Whether product name exists in catalog list.
 */
export function isInCatalog(name, productNames) {
    return name && productNames.includes(name)
}
