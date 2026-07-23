<template>
    <AppLayout>
        <template #header>
            <h1 class="page-title">Настройки</h1>
        </template>

        <div class="max-w-3xl mx-auto space-y-6">

            <!-- Appearance -->
            <div class="card">
                <h2 class="section-title mb-4 pb-3 border-b border-gray-100 dark:border-gray-700">
                    Оформление
                </h2>
                <div>
                    <p class="label mb-2">Тема</p>
                    <div class="theme-segment">
                        <button
                            v-for="option in themeOptions"
                            :key="option.value"
                            type="button"
                            :class="[
                                'theme-segment-btn',
                                preference === option.value && 'theme-segment-btn-active',
                            ]"
                            :disabled="savingTheme"
                            @click="selectTheme(option.value)"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Tenant settings (admin/manager only) -->
            <form v-if="canManageSettings" @submit.prevent="save" class="space-y-6">

                <!-- Setting group cards -->
                <div v-for="(group, groupKey) in schema" :key="groupKey" class="card">
                    <h2 class="section-title mb-4 pb-3 border-b border-gray-100 dark:border-gray-700">
                        {{ group.label }}
                    </h2>

                    <div class="space-y-4">
                        <template v-for="(meta, key) in group.keys" :key="key">
                            <!-- depends_on: hide if condition not met -->
                            <div v-if="isVisible(meta)" class="setting-row">
                                <label class="label">{{ meta[0] }}</label>

                                <!-- toggle -->
                                <div v-if="meta[1] === 'toggle'" class="flex items-center gap-3 mt-1">
                                    <button
                                        type="button"
                                        :class="[
                                            'relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-300',
                                            isToggleOn(key) ? 'bg-indigo-600' : 'bg-gray-300 dark:bg-gray-600',
                                        ]"
                                        @click="toggleSwitch(key)"
                                    >
                                        <span :class="[
                                            'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
                                            isToggleOn(key) ? 'translate-x-6' : 'translate-x-1',
                                        ]" />
                                    </button>
                                    <span class="text-sm text-muted">{{ isToggleOn(key) ? 'Включено' : 'Выключено' }}</span>
                                </div>

                                <!-- select -->
                                <select
                                    v-else-if="meta[1] === 'select'"
                                    v-model="form[key]"
                                    class="input mt-1"
                                >
                                    <option v-if="!form[key] && !currentValues[key]" value="" disabled>— выберите —</option>
                                    <option
                                        v-for="(optLabel, optValue) in (meta[4] || {})"
                                        :key="optValue"
                                        :value="optValue"
                                    >{{ optLabel }}</option>
                                </select>

                                <!-- textarea -->
                                <textarea
                                    v-else-if="meta[1] === 'textarea'"
                                    v-model="form[key]"
                                    :placeholder="meta[2]"
                                    rows="4"
                                    class="input mt-1 font-mono text-xs resize-y"
                                />

                                <!-- text / password -->
                                <div v-else class="flex gap-2 mt-1">
                                    <div class="relative flex-1">
                                        <input
                                            :type="visibleKeys[key] ? 'text' : meta[1]"
                                            v-model="form[key]"
                                            :placeholder="currentValues[key] ? maskedPlaceholder(key, meta[1]) : meta[2]"
                                            class="input pr-10"
                                        />
                                        <button
                                            v-if="meta[1] === 'password'"
                                            type="button"
                                            class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                            @click="toggleVisible(key)"
                                            tabindex="-1"
                                        >
                                            <span v-if="visibleKeys[key]">🙈</span>
                                            <span v-else>👁</span>
                                        </button>
                                    </div>

                                    <!-- Generate button for webhook_secret -->
                                    <button
                                        v-if="key === 'webhook_secret'"
                                        type="button"
                                        class="btn-secondary text-sm whitespace-nowrap"
                                        :disabled="generating"
                                        @click="generateSecret"
                                    >
                                        {{ generating ? '…' : 'Сгенерировать' }}
                                    </button>
                                </div>

                                <!-- Hint -->
                                <p v-if="meta[3]" class="text-xs text-gray-400 dark:text-gray-500 mt-1">{{ meta[3] }}</p>

                                <!-- Current value indicator (non-toggle, non-textarea) -->
                                <p v-if="meta[1] !== 'toggle' && meta[1] !== 'textarea' && currentValues[key] && !form[key]"
                                   class="text-xs text-green-600 dark:text-green-400 mt-1">
                                    ✓ Сохранено
                                </p>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Submit -->
                <div class="flex justify-end gap-3">
                    <p v-if="saved" class="text-sm text-green-600 dark:text-green-400 flex items-center gap-1 mr-auto">
                        ✓ Настройки сохранены
                    </p>
                    <button type="submit" class="btn-primary" :disabled="saving || readOnly">
                        {{ saving ? 'Сохраняю…' : 'Сохранить настройки' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { Inertia } from '@inertiajs/inertia'
import { usePage } from '@inertiajs/inertia-vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useTheme } from '@/composables/useTheme'
import { useSubscription } from '@/composables/useSubscription'
import { apiFetch } from '@/utils/api'

const { readOnly } = useSubscription()

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps({
    schema:            { type: Object, default: () => ({}) },
    current:           { type: Object, default: () => ({}) },
    canManageSettings: { type: Boolean, default: false },
    theme:             { type: String, default: 'system' },
})

// ── Theme ─────────────────────────────────────────────────────────────────────
const { preference, setTheme } = useTheme()
const page = usePage()
const savingTheme = ref(false)

const themeOptions = [
    { value: 'light',  label: 'Светлая' },
    { value: 'dark',   label: 'Тёмная' },
    { value: 'system', label: 'Системная' },
]

onMounted(() => {
    preference.value = props.theme || page.props.value.auth?.user?.theme || preference.value
})

async function selectTheme(value) {
    savingTheme.value = true
    try {
        await setTheme(value)
    } finally {
        savingTheme.value = false
    }
}

// ── State ─────────────────────────────────────────────────────────────────────
const currentValues = ref({ ...props.current })

const form = reactive((() => {
    const f = {}
    for (const group of Object.values(props.schema)) {
        for (const [key, meta] of Object.entries(group.keys)) {
            const type = meta[1]
            if (type === 'select') {
                f[key] = props.current[key] ?? (meta[4] ? Object.keys(meta[4])[0] : '')
            } else if (type === 'toggle') {
                f[key] = props.current[key] ?? ''
            } else {
                f[key] = ''
            }
        }
    }
    return f
})())

const visibleKeys = reactive({})
const saving      = ref(false)
const saved       = ref(false)
const generating  = ref(false)

// ── Helpers ───────────────────────────────────────────────────────────────────

function isVisible(meta) {
    const depOn = meta[5] ?? null
    if (!depOn) return true
    const [depKey, depVal] = Object.entries(depOn)[0]
    const current = form[depKey] !== undefined ? form[depKey] : (currentValues.value[depKey] ?? '')
    return current === depVal
}

function isToggleOn(key) {
    return (form[key] === '1') || (form[key] === '' && currentValues.value[key] === '1')
}

function toggleSwitch(key) {
    const next = isToggleOn(key) ? '' : '1'
    form[key] = next
}

function maskedPlaceholder(key, type) {
    if (type !== 'password') return ''
    const val = currentValues.value[key] ?? ''
    return val.length > 0 ? val.slice(0, 4) + '••••••••' : ''
}

function toggleVisible(key) {
    visibleKeys[key] = !visibleKeys[key]
}

// ── Save ──────────────────────────────────────────────────────────────────────
function save() {
    if (readOnly.value) return
    const settings = {}

    for (const group of Object.values(props.schema)) {
        for (const [key, meta] of Object.entries(group.keys)) {
            const type = meta[1]
            const raw  = form[key]

            if (type === 'toggle') {
                settings[key] = raw === '1' ? '1' : ''
            } else if (type === 'select') {
                if (raw && String(raw).trim() !== '') {
                    settings[key] = String(raw).trim()
                }
            } else {
                if (raw && String(raw).trim() !== '') {
                    settings[key] = String(raw).trim()
                }
            }
        }
    }

    saving.value = true
    Inertia.post('/settings', { settings }, {
        preserveState: true,
        onSuccess: () => {
            Object.entries(settings).forEach(([k, v]) => {
                currentValues.value[k] = v
            })
            for (const group of Object.values(props.schema)) {
                for (const [key, meta] of Object.entries(group.keys)) {
                    const type = meta[1]
                    if (type !== 'toggle' && type !== 'select') {
                        form[key] = ''
                    }
                }
            }
            saved.value = true
            setTimeout(() => { saved.value = false }, 3000)
        },
        onFinish: () => { saving.value = false },
    })
}

// ── Generate webhook secret ───────────────────────────────────────────────────
async function generateSecret() {
    if (readOnly.value) return
    generating.value = true
    try {
        const resp = await apiFetch('/settings/generate-webhook-secret', 'POST')
        const data = await resp.json()
        if (data.success) {
            form['webhook_secret']                = data.secret
            currentValues.value['webhook_secret'] = data.secret
            visibleKeys['webhook_secret']         = true
        }
    } finally {
        generating.value = false
    }
}
</script>

<style scoped>
.setting-row {
    @apply py-1;
}
</style>
