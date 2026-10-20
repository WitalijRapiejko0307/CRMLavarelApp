import { computed } from 'vue'
import { usePage } from '@inertiajs/inertia-vue3'

export function useSubscription() {
    const page = usePage()

    const subscription = computed(() => page.props.value.subscription ?? null)
    const readOnly = computed(() => subscription.value?.readOnly === true)
    const trialDaysLeft = computed(() => subscription.value?.trialDaysLeft ?? null)
    const status = computed(() => subscription.value?.status ?? null)
    const isTrial = computed(() => status.value === 'trial')
    const isExpired = computed(() => status.value === 'expired' || readOnly.value)
    const accessEndsAt = computed(() => subscription.value?.accessEndsAt ?? null)
    const accessDaysLeft = computed(() => subscription.value?.accessDaysLeft ?? null)
    const subscriptionStartsAt = computed(() => subscription.value?.subscriptionStartsAt ?? null)
    const bannerDismissed = computed(() => subscription.value?.bannerDismissed === true)

    return {
        subscription,
        readOnly,
        trialDaysLeft,
        status,
        isTrial,
        isExpired,
        accessEndsAt,
        accessDaysLeft,
        subscriptionStartsAt,
        bannerDismissed,
    }
}
