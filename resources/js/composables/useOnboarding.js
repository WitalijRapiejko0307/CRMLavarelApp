import { computed } from 'vue'
import { usePage } from '@inertiajs/inertia-vue3'

export function useOnboarding() {
    const page = usePage()

    const onboarding = computed(() => page.props.value.onboarding ?? null)
    const visible = computed(() => onboarding.value?.visible === true)
    const dismissed = computed(() => onboarding.value?.dismissed === true)
    const currentStep = computed(() => onboarding.value?.current_step ?? null)
    const settingsFocus = computed(() => onboarding.value?.settings_focus === true)
    const belpostReady = computed(() => onboarding.value?.belpost_ready === true)
    const canSkipOptional = computed(() => onboarding.value?.can_skip_optional === true)

    return {
        onboarding,
        visible,
        dismissed,
        currentStep,
        settingsFocus,
        belpostReady,
        canSkipOptional,
    }
}
