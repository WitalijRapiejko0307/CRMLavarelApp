import { onUnmounted, ref } from 'vue'

/**
 * Reactive Tailwind breakpoint helper based on `matchMedia`.
 * Used by ResponsiveList (and pages when JS-level branching is required).
 * Navigation visibility should still rely on CSS (`md:hidden` / `hidden md:flex`)
 * to avoid any flash before hydration.
 */
export function useBreakpoint(query = '(min-width: 768px)') {
    const mql = typeof window !== 'undefined' ? window.matchMedia(query) : null
    const matches = ref(mql?.matches ?? false)

    function onChange(event) {
        matches.value = event.matches
    }

    if (mql) {
        if (mql.addEventListener) {
            mql.addEventListener('change', onChange)
        } else {
            // Safari < 14 fallback
            mql.addListener(onChange)
        }

        onUnmounted(() => {
            if (mql.removeEventListener) {
                mql.removeEventListener('change', onChange)
            } else {
                mql.removeListener(onChange)
            }
        })
    }

    return matches
}

/** Convenience shortcut: `true` when viewport is `md` (>= 768px) or wider. */
export function useIsMdUp() {
    return useBreakpoint('(min-width: 768px)')
}
