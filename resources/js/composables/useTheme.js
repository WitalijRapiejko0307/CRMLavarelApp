import { ref, computed } from 'vue'
import { usePage } from '@inertiajs/inertia-vue3'
import { apiFetch } from '@/utils/api'

const STORAGE_KEY = 'crm-theme'

const preference = ref('system')
let mediaQuery = null
let mediaListener = null
let initialized = false

function getSystemTheme() {
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

function resolveTheme(pref) {
    if (pref === 'dark') return 'dark'
    if (pref === 'light') return 'light'
    return getSystemTheme()
}

function applyTheme() {
    const resolved = resolveTheme(preference.value)
    document.documentElement.classList.toggle('dark', resolved === 'dark')
    localStorage.setItem(STORAGE_KEY, preference.value)
}

function onSystemChange() {
    if (preference.value === 'system') {
        applyTheme()
    }
}

function bindSystemListener() {
    if (mediaQuery) return
    mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
    mediaListener = () => onSystemChange()
    if (mediaQuery.addEventListener) {
        mediaQuery.addEventListener('change', mediaListener)
    } else {
        mediaQuery.addListener(mediaListener)
    }
}

export function useTheme() {
    const page = usePage()

    const resolvedTheme = computed(() => resolveTheme(preference.value))

    function syncFromPage() {
        const userTheme = page.props.value.auth?.user?.theme
        if (userTheme && userTheme !== preference.value) {
            preference.value = userTheme
            applyTheme()
        }
    }

    async function setTheme(value) {
        if (!['light', 'dark', 'system'].includes(value)) return

        preference.value = value
        applyTheme()

        if (page.props.value.auth?.user) {
            try {
                const resp = await apiFetch('/settings/theme', 'PATCH', { theme: value })
                if (resp.ok) {
                    const data = await resp.json()
                    if (page.props.value.auth?.user) {
                        page.props.value.auth.user.theme = data.theme
                    }
                }
            } catch {
                // Theme applied locally; sync on next page load
            }
        } else {
            localStorage.setItem(STORAGE_KEY, value)
        }
    }

    return {
        preference,
        resolvedTheme,
        setTheme,
        applyTheme,
        syncFromPage,
    }
}

export function initTheme(initialUserTheme = null) {
    if (initialized) return
    initialized = true

    const fromWindow = typeof window !== 'undefined' ? window.__USER_THEME__ : null
    preference.value = initialUserTheme || fromWindow || localStorage.getItem(STORAGE_KEY) || 'system'
    bindSystemListener()
    applyTheme()
}
