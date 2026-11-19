import { isFullNameComplete } from '@/utils/phone'

const ADDRESS_KEYS = ['city', 'street', 'building', 'housing', 'apartment']
const BLACKLIST_MARK = 'НЕБЛАГОНАДЕЖНЫЙ КЛИЕНТ'

function empty(v) {
    return v === null || v === undefined || String(v).trim() === ''
}

/**
 * Copy customer profile data into an order form. Mirrors CustomerService::prefillValues():
 * only empty fields are filled, the address is copied as one block, and goods / prices /
 * comments are never touched. Returns the list of filled field names.
 *
 * @param {object} form      useForm() instance (full_name, city, ..., poste_restante)
 * @param {object} customer  profile from /api/customers/lookup
 * @param {object} [opts]    { deliveryType: current delivery type (to decide on picker ids) }
 */
export function applyCustomerToForm(form, customer, opts = {}) {
    const filled = []
    if (!customer) return filled

    const current = String(form.full_name ?? '')
    const clean = current.replace(BLACKLIST_MARK, '').trim()
    if (!empty(customer.full_name) && (clean === '' || !isFullNameComplete(clean))) {
        const mark = current.includes(BLACKLIST_MARK) ? ' ' + BLACKLIST_MARK : ''
        form.full_name = customer.full_name + mark
        filled.push('full_name')
    }

    const addressEmpty = ADDRESS_KEYS.every(k => empty(form[k])) && !form.poste_restante
    const profileHasAddress = !empty(customer.city) || !empty(customer.street)

    if (addressEmpty && profileHasAddress) {
        ADDRESS_KEYS.forEach(k => { form[k] = customer[k] ?? '' })
        form.poste_restante = !!customer.poste_restante
        filled.push(...ADDRESS_KEYS, 'poste_restante')

        // Picker ids belong to the address: copy them only for the same delivery type.
        const sameType = !opts.deliveryType || opts.deliveryType === customer.delivery_type
        if (sameType && empty(form.belpost_address_id) && customer.belpost_address_id) {
            form.belpost_address_id = customer.belpost_address_id
            filled.push('belpost_address_id')
        }
        if (sameType && !form.europochta_store_id && customer.europochta_store_id) {
            form.europochta_store_id = customer.europochta_store_id
            filled.push('europochta_store_id')
        }
    }

    return filled
}

export function phoneKey(phone) {
    const digits = String(phone ?? '').replace(/\D/g, '')
    return digits.length >= 9 ? digits.slice(-9) : ''
}
