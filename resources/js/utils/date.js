/**
 * Apply dd/mm/yy mask while typing (max 8 digits).
 */
export function maskDateInput(raw) {
    const digits = String(raw ?? '').replace(/\D/g, '').slice(0, 8)
    if (digits.length <= 2) return digits
    if (digits.length <= 4) return `${digits.slice(0, 2)}/${digits.slice(2)}`
    return `${digits.slice(0, 2)}/${digits.slice(2, 4)}/${digits.slice(4)}`
}

/**
 * Whether a string is a complete valid calendar date in dd/mm/yy format.
 */
export function isValidDateDMY(str) {
    const match = String(str ?? '').match(/^(\d{2})\/(\d{2})\/(\d{2})$/)
    if (!match) return false

    const day = Number(match[1])
    const month = Number(match[2])
    const year = expandTwoDigitYear(Number(match[3]))

    if (month < 1 || month > 12 || day < 1 || day > 31) return false

    const date = new Date(year, month - 1, day)
    return date.getFullYear() === year
        && date.getMonth() === month - 1
        && date.getDate() === day
}

/**
 * dd/mm/yy → YYYY-MM-DD for API/query params.
 */
export function parseDateDMY(str) {
    if (!isValidDateDMY(str)) return ''
    const [, dd, mm, yy] = String(str).match(/^(\d{2})\/(\d{2})\/(\d{2})$/)
    const year = expandTwoDigitYear(Number(yy))
    return `${year}-${mm}-${dd}`
}

/**
 * YYYY-MM-DD (or ISO datetime) → dd/mm/yy for display.
 */
export function formatDateDMY(iso) {
    if (!iso) return ''
    const match = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})/)
    if (!match) return ''
    const [, yyyy, mm, dd] = match
    return `${dd}/${mm}/${yyyy.slice(-2)}`
}

function expandTwoDigitYear(twoDigitYear) {
    return twoDigitYear >= 70 ? 1900 + twoDigitYear : 2000 + twoDigitYear
}
