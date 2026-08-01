import { ref, onMounted, onUnmounted } from 'vue'
import { Inertia } from '@inertiajs/inertia'

/**
 * Poll order feed every 2.5s and refresh the orders list when changes arrive.
 */
export function useOrderFeed(options = {}) {
    const feedUpdated = ref(false)
    const lastSince = ref(new Date().toISOString())
    let pollTimer = null

    function isEnabled() {
        if (options.enabled !== undefined) {
            return typeof options.enabled === 'function'
                ? options.enabled()
                : options.enabled.value
        }
        return true
    }

    async function pollFeed() {
        if (!isEnabled()) return

        try {
            const url = `/api/orders/feed?since=${encodeURIComponent(lastSince.value)}`
            const resp = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })

            if (!resp.ok) return

            const data = await resp.json()
            lastSince.value = data.server_time || new Date().toISOString()

            if (!data.orders?.length) return

            feedUpdated.value = true
            setTimeout(() => { feedUpdated.value = false }, 3000)

            Inertia.reload({
                only: ['orders'],
                preserveScroll: true,
                preserveState: true,
            })
        } catch {
            // silent retry on next tick
        }
    }

    function startPolling() {
        stopPolling()
        pollTimer = setInterval(pollFeed, 2500)
        pollFeed()
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer)
            pollTimer = null
        }
    }

    onMounted(startPolling)
    onUnmounted(stopPolling)

    return {
        feedUpdated,
        startPolling,
        stopPolling,
        pollFeed,
    }
}
