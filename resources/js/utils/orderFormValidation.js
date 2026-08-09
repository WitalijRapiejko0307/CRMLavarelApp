import { isFullNameComplete, isValidBelarusPhone, normalizePhone } from '@/utils/phone'

export function normalizeName(name) {
    if (name == null || name === '') {
        return ''
    }

    return String(name).trim().replace(/\s+/g, ' ')
}

/**
 * @returns {Record<string, string>} field key → error message
 */
export function validateOrderForm({ full_name, phone, goods }) {
    const errors = {}

    const name = normalizeName(full_name)
    if (!name) {
        errors.full_name = 'Укажите ФИО'
    } else if (!isFullNameComplete(name)) {
        errors.full_name = 'Введите фамилию и имя (минимум два слова)'
    }

    if (!phone?.trim()) {
        errors.phone = 'Укажите телефон'
    } else if (!isValidBelarusPhone(phone)) {
        errors.phone = 'Укажите корректный белорусский номер (375XXXXXXXXX)'
    }

    if (!Array.isArray(goods) || !goods.some(g => String(g ?? '').trim() !== '')) {
        errors.goods = 'Добавьте хотя бы один товар'
    }

    return errors
}

export function normalizeOrderFormFields(data) {
    return {
        ...data,
        full_name: normalizeName(data.full_name),
        phone: normalizePhone(data.phone?.trim()) || '',
    }
}

export function fieldErrorsFromValidation(errors) {
    return {
        full_name: !!errors.full_name,
        phone:     !!errors.phone,
        goods:     !!errors.goods,
    }
}

export function validationAlertMessage(errors) {
    return Object.values(errors).join('. ')
}
