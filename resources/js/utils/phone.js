/**
 * Normalize Belarus phone to 375XXXXXXXXX (12 digits). Mirrors PhoneNormalizer.php.
 */
export function normalizePhone(phone) {
    if (phone == null || phone === '') {
        return phone ?? ''
    }

    const digits = String(phone).replace(/\D/g, '')
    if (digits === '') {
        return String(phone)
    }

    const len = digits.length
    if (len === 9) {
        return '375' + digits
    }
    if (len === 11 && digits.startsWith('80')) {
        return '375' + digits.slice(2)
    }
    if (len === 12 && digits.startsWith('375')) {
        return digits
    }

    return digits
}

function lastNineDigits(phone) {
    const digits = String(phone ?? '').replace(/\D/g, '')
    if (digits === '') {
        return ''
    }
    return digits.length >= 9 ? digits.slice(-9) : digits
}

/**
 * Whether phone is a valid Belarus number (375XXXXXXXXX). Mirrors BelarusPhone.php.
 */
export function isValidBelarusPhone(phone) {
    if (!phone || String(phone).trim() === '') {
        return false
    }

    const normalized = normalizePhone(phone)
    if (normalized == null || normalized === '') {
        return false
    }

    const digits = String(normalized).replace(/\D/g, '')

    return digits.length === 12
        && digits.startsWith('375')
        && lastNineDigits(digits).length === 9
}

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
 * Whether full name has at least 2 parts of 2+ chars (Belpost requirement).
 */
export function isFullNameComplete(name) {
    if (!name) return false
    const parts = name.trim().replace(/\s+/g, ' ').split(' ').filter(Boolean)
    return parts.length >= 2 && parts.every(p => p.length >= 2)
}

/**
 * Whether product name exists in catalog list.
 */
export function isInCatalog(name, productNames) {
    return name && productNames.includes(name)
}
