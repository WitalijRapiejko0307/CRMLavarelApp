import { ref, watch, onBeforeUnmount } from 'vue'
import { apiFetch } from '@/utils/api'
import { phoneKey } from '@/utils/customerPrefill'

/**
 * Looks the customer up by phone while the operator types it.
 *
 * - searches only for a full 9-digit key that differs from the last searched one;
 * - `skipKey` (the order's customer_lookup_key) suppresses the search for orders that
 *   were already looked up / auto-filled, so an order is never searched twice for the
 *   same phone.
 */
export function useCustomerLookup(phoneGetter, { orderId = null, skipKey = null, initialInfo = null, onFound = null } = {}) {
    const info = ref(initialInfo)
    const loading = ref(false)
    let lastKey = skipKey || phoneKey(phoneGetter())
    let timer = null
    let seq = 0

    async function run(phone, key) {
        const my = ++seq
        loading.value = true
        try {
            const query = new URLSearchParams({ phone, ...(orderId ? { order_id: String(orderId) } : {}) })
            const res = await apiFetch(`/api/customers/lookup?${query}`, 'GET')
            if (!res.ok) throw new Error('lookup failed')
            const data = await res.json()
            if (my !== seq) return
            info.value = data
            if (data.found && data.has_profile && onFound) onFound(data)
        } catch (e) {
            if (my === seq) info.value = null
        } finally {
            if (my === seq) loading.value = false
        }
    }

    function search(phone = phoneGetter(), { force = false } = {}) {
        const key = phoneKey(phone)
        if (key.length !== 9) {
            info.value = null
            lastKey = ''
            return
        }
        if (!force && key === lastKey) return
        lastKey = key
        clearTimeout(timer)
        timer = setTimeout(() => run(phone, key), 350)
    }

    watch(phoneGetter, (phone) => search(phone))
    onBeforeUnmount(() => clearTimeout(timer))

    return { info, loading, search }
}
