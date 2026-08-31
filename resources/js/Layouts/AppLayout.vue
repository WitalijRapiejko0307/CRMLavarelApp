<template>
    <div class="min-h-screen flex flex-col bg-gray-50 dark:bg-gray-950">
        <!-- Navigation -->
        <nav class="bg-indigo-700 dark:bg-indigo-950 text-white shadow-md dark:border-b dark:border-indigo-900">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-14 gap-3">
                    <!-- Mobile: burger + logo -->
                    <div class="flex items-center gap-3 min-w-0">
                        <MobileNav :title="shopName" :links="mobileLinks">
                            <template #actions>
                                <div class="px-4 py-2 text-sm text-indigo-200 truncate">
                                    {{ $page.props.auth.user?.name }}
                                </div>
                                <Link
                                    href="/settings"
                                    class="touch-target flex items-center gap-2 px-4 text-base font-medium transition-colors"
                                    :class="isActive('/settings') ? 'text-white bg-white/10' : 'text-indigo-200 hover:text-white hover:bg-white/5'"
                                >
                                    <span aria-hidden="true">⚙</span> Настройки
                                </Link>
                                <Link
                                    v-if="isAdmin"
                                    href="/users"
                                    class="touch-target flex items-center gap-2 px-4 text-base font-medium transition-colors"
                                    :class="isActive('/users') ? 'text-white bg-white/10' : 'text-indigo-200 hover:text-white hover:bg-white/5'"
                                >
                                    <span aria-hidden="true">👥</span> Пользователи
                                </Link>
                                <form @submit.prevent="logout">
                                    <button
                                        type="submit"
                                        class="touch-target w-full flex items-center gap-2 px-4 text-base font-medium text-indigo-200 hover:text-white hover:bg-white/5 transition-colors"
                                    >
                                        Выйти
                                    </button>
                                </form>
                            </template>
                        </MobileNav>

                        <Link href="/orders" class="text-lg font-bold tracking-tight hover:text-indigo-200 transition-colors truncate">
                            {{ shopName }}
                        </Link>
                    </div>

                    <!-- Desktop links -->
                    <div class="hidden md:flex items-center gap-5">
                        <Link
                            href="/orders"
                            :class="navLinkClass('/orders')"
                        >
                            Заказы
                        </Link>
                        <Link
                            v-if="isStore"
                            href="/belpost"
                            :class="navLinkClass('/belpost')"
                        >
                            Белпочта
                        </Link>
                        <Link
                            v-if="isStore"
                            href="/europochta"
                            :class="navLinkClass('/europochta')"
                        >
                            Европочта
                        </Link>
                        <Link
                            v-if="isStore"
                            href="/products"
                            :class="navLinkClass('/products')"
                        >
                            Склад
                        </Link>
                        <Link
                            v-if="canViewAnalytics"
                            href="/analytics"
                            :class="navLinkClass('/analytics')"
                        >
                            Аналитика
                        </Link>
                        <Link
                            v-if="canViewFinances"
                            href="/finances"
                            :class="navLinkClass('/finances')"
                        >
                            Финансы
                        </Link>
                    </div>

                    <!-- Desktop user menu -->
                    <div class="hidden md:flex items-center gap-4 text-sm">
                        <span class="text-indigo-300 hidden sm:inline">{{ $page.props.auth.user?.name }}</span>

                        <!-- Settings -->
                        <Link
                            href="/settings"
                            :class="['transition-colors hover:text-white', isActive('/settings') ? 'text-white' : 'text-indigo-300']"
                            title="Настройки"
                        >
                            ⚙
                        </Link>

                        <!-- Users (admin only) -->
                        <Link
                            v-if="isAdmin"
                            href="/users"
                            :class="['transition-colors hover:text-white', isActive('/users') ? 'text-white' : 'text-indigo-300']"
                            title="Пользователи"
                        >
                            👥
                        </Link>

                        <form @submit.prevent="logout">
                            <button type="submit" class="text-indigo-300 hover:text-white transition-colors">
                                Выйти
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </nav>

        <TrialBanner />
        <OnboardingChecklist />
        <OnboardingWelcomeModal />

        <!-- Auto tracking notice -->
        <div
            v-if="showTrackingNotice && isStore"
            class="max-w-7xl mx-auto w-full px-4 sm:px-6 lg:px-8 mt-4"
        >
            <div class="bg-indigo-50 dark:bg-indigo-900/30 border border-indigo-200 dark:border-indigo-800 text-indigo-900 dark:text-indigo-100 rounded-md px-4 py-3 text-sm flex items-start justify-between gap-3">
                <div class="flex items-start gap-2">
                    <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                    </svg>
                    <span>
                        Утренняя проверка статусов выполнена {{ formatNoticeDate(trackingNotice.last_auto_at) }}.
                        Проверено заявок: {{ trackingNotice.checked }} из {{ trackingNotice.total }}.
                        <span v-if="trackingNotice.errors > 0"> Ошибок: {{ trackingNotice.errors }}.</span>
                    </span>
                </div>
                <button
                    type="button"
                    class="text-indigo-500 hover:text-indigo-700 dark:text-indigo-300 dark:hover:text-indigo-100 flex-shrink-0"
                    title="Закрыть"
                    @click="dismissTrackingNotice"
                >
                    ✕
                </button>
            </div>
        </div>

        <!-- SalesRender sync failures -->
        <div
            v-if="showSrSyncFailures"
            class="max-w-7xl mx-auto w-full px-4 sm:px-6 lg:px-8 mt-4"
        >
            <div class="bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 text-amber-900 dark:text-amber-100 rounded-md px-4 py-3 text-sm flex items-start justify-between gap-3">
                <div class="flex items-start gap-2 min-w-0">
                    <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    <div class="min-w-0">
                        <p class="font-medium">
                            Не удалось обновить {{ srSyncFailures.failures.length }} {{ srFailureNoun }} из SalesRender
                        </p>
                        <ul class="mt-1 space-y-1 text-amber-800 dark:text-amber-200">
                            <li
                                v-for="(failure, idx) in srSyncFailures.failures"
                                :key="idx"
                            >
                                Заявка {{ failure.order_id }}
                                <span v-if="failure.external_id">| ID {{ failure.external_id }}</span>
                                <span v-if="failure.sr_status">| SR: {{ failure.sr_status }}</span>
                                <span class="block text-xs">{{ srFailureReason(failure) }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
                <button
                    type="button"
                    class="text-amber-500 hover:text-amber-700 dark:text-amber-300 dark:hover:text-amber-100 flex-shrink-0"
                    title="Закрыть"
                    @click="dismissSrSyncFailures"
                >
                    ✕
                </button>
            </div>
        </div>

        <!-- Flash messages -->
        <div v-if="flash.message || flash.error" class="max-w-7xl mx-auto w-full px-4 sm:px-6 lg:px-8 mt-4">
            <div v-if="flash.message" class="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-800 dark:text-green-200 rounded-md px-4 py-3 text-sm flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                {{ flash.message }}
            </div>
            <div v-if="flash.error" class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 rounded-md px-4 py-3 text-sm flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                {{ flash.error }}
            </div>
        </div>

        <!-- Page header -->
        <header v-if="$slots.header" class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <slot name="header" />
            </div>
        </header>

        <!-- Main content -->
        <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <slot />
        </main>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, usePage } from '@inertiajs/inertia-vue3'
import { Inertia } from '@inertiajs/inertia'
import { apiFetch } from '@/utils/api'
import TrialBanner from '@/Components/TrialBanner.vue'
import OnboardingChecklist from '@/Components/OnboardingChecklist.vue'
import OnboardingWelcomeModal from '@/Components/OnboardingWelcomeModal.vue'
import MobileNav from '@/Components/MobileNav.vue'

const page = usePage()

const flash = computed(() => ({
    message: page.props.value.flash?.message,
    error:   page.props.value.flash?.error,
}))

const shopName          = computed(() => page.props.value.shop_name || 'BaseCRM')
const tenantType        = computed(() => page.props.value.tenant?.type ?? 'store')
const isCallCenter      = computed(() => tenantType.value === 'call_center')
const isStore           = computed(() => !isCallCenter.value)
const currentRole       = computed(() => page.props.value.auth?.user?.role ?? '')
const isAdmin           = computed(() => currentRole.value === 'admin')
const canViewFinances   = computed(() => isStore.value && ['admin', 'manager'].includes(currentRole.value))
const canViewAnalytics  = computed(() => isCallCenter.value && ['admin', 'manager', 'operator'].includes(currentRole.value))

const mobileLinks = computed(() => {
    const links = [
        { href: '/orders', label: 'Заказы', active: isActive('/orders') },
    ]
    if (isStore.value) {
        links.push(
            { href: '/belpost', label: 'Белпочта', active: isActive('/belpost') },
            { href: '/europochta', label: 'Европочта', active: isActive('/europochta') },
            { href: '/products', label: 'Склад', active: isActive('/products') },
        )
    }
    if (canViewAnalytics.value) {
        links.push({ href: '/analytics', label: 'Аналитика', active: isActive('/analytics') })
    }
    if (canViewFinances.value) {
        links.push({ href: '/finances', label: 'Финансы', active: isActive('/finances') })
    }
    return links
})

const trackingNoticeDismissed = ref(false)
const trackingNotice = computed(() => page.props.value.tracking_auto_notice)
const showTrackingNotice = computed(() =>
    trackingNotice.value && !trackingNoticeDismissed.value
)

function formatNoticeDate(value) {
    if (!value) return '—'
    const d = new Date(value)
    return d.toLocaleString('ru-RU', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })
}

async function dismissTrackingNotice() {
    trackingNoticeDismissed.value = true
    try {
        await apiFetch('/api/tracking/auto-notice/dismiss', 'POST')
    } catch {
        // Non-fatal — banner already hidden locally
    }
}

const srSyncFailuresDismissed = ref(false)
const srSyncFailures = computed(() => page.props.value.sr_sync_failures)
const showSrSyncFailures = computed(() =>
    srSyncFailures.value?.failures?.length > 0 && !srSyncFailuresDismissed.value
)
const srFailureNoun = computed(() => {
    const n = srSyncFailures.value?.failures?.length ?? 0
    const mod10 = n % 10
    const mod100 = n % 100
    if (mod10 === 1 && mod100 !== 11) return 'заявку'
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'заявки'
    return 'заявок'
})

function srFailureReason(failure) {
    if (failure.reason === 'VALIDATION') {
        return '→ Не совпадает ID или телефон'
    }
    if (failure.reason === 'UPDATE_ERROR') {
        return '→ Ошибка записи: ' + (failure.message || 'неизвестная ошибка')
    }
    return failure.message || ''
}

async function dismissSrSyncFailures() {
    srSyncFailuresDismissed.value = true
    try {
        await apiFetch('/api/sr-sync/failures/dismiss', 'POST')
    } catch {
        // Non-fatal — banner already hidden locally
    }
}

function isActive(path) {
    return window.location.pathname.startsWith(path)
}

function navLinkClass(path) {
    return [
        'text-sm font-medium transition-colors hover:text-indigo-200',
        isActive(path) ? 'text-white' : 'text-indigo-300',
    ]
}

function logout() {
    Inertia.post('/logout')
}
</script>
