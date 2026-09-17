<template>
    <AppLayout>
        <template #header>
            <PageHeader>
                <template #title>
                    <h1 class="page-title">Настройки</h1>
                </template>
                <template #actions>
                    <button
                        v-if="dismissed"
                        type="button"
                        class="btn-secondary text-sm"
                        @click="restoreOnboarding"
                    >
                        Показать подсказки
                    </button>
                </template>
            </PageHeader>
        </template>

        <div class="max-w-3xl mx-auto space-y-6">

            <!-- Appearance -->
            <div class="card">
                <h2 class="section-title mb-4 pb-3 border-b border-gray-100 dark:border-gray-700">
                    Оформление
                </h2>
                <div>
                    <p class="label mb-2">Тема</p>
                    <div class="theme-segment flex-wrap w-full sm:w-auto sm:flex-nowrap">
                        <button
                            v-for="option in themeOptions"
                            :key="option.value"
                            type="button"
                            :class="[
                                'theme-segment-btn flex-1 sm:flex-initial text-center touch-target',
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

            <!-- Store: connect to call center -->
            <div v-if="canViewSettings && isStore" class="card">
                <button
                    v-if="collapseOptionalGroups"
                    type="button"
                    class="w-full flex items-center justify-between text-left"
                    @click="toggleExtra('call_center')"
                >
                    <h2 class="section-title">Колл-центр</h2>
                    <span class="text-muted text-sm">{{ extraOpen.call_center ? '▾' : '▸' }}</span>
                </button>
                <h2
                    v-else
                    class="section-title mb-4 pb-3 border-b border-gray-100 dark:border-gray-700"
                >
                    Колл-центр
                </h2>
                <p v-if="collapseOptionalGroups && !extraOpen.call_center" class="text-xs text-muted mt-2">
                    по желанию
                </p>

                <div v-show="!collapseOptionalGroups || extraOpen.call_center" class="mt-4">

                <div v-if="activeConnection" class="mb-4 p-3 rounded-md bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-sm">
                    <p class="font-medium text-green-800 dark:text-green-200">
                        Подключено: {{ activeConnection.call_center?.name }}
                    </p>
                    <p class="text-green-700 dark:text-green-300 text-xs mt-1">
                        С {{ formatConnectionDate(activeConnection.approved_at) }}
                    </p>
                    <form v-if="canEditSettings" class="mt-3" @submit.prevent="disconnectConnection(activeConnection.id)">
                        <button type="submit" class="btn-secondary btn-sm" :disabled="connectionBusy">
                            Отключить
                        </button>
                    </form>
                </div>

                <div v-else-if="pendingConnection" class="mb-4 p-3 rounded-md bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-sm">
                    <p class="font-medium text-amber-800 dark:text-amber-200">
                        Ожидает одобрения: {{ pendingConnection.call_center?.name }}
                    </p>
                    <p class="text-amber-700 dark:text-amber-300 text-xs mt-1">
                        Заявка отправлена {{ formatConnectionDate(pendingConnection.requested_at) }}
                    </p>
                </div>

                <div v-else-if="canEditSettings" class="space-y-3">
                    <div>
                        <label class="label mb-1">Код колл-центра</label>
                        <input
                            v-model="connectionCodeInput"
                            type="text"
                            class="w-full uppercase"
                            placeholder="ABCD1234"
                            maxlength="20"
                            :disabled="readOnly || connectionBusy"
                        />
                    </div>
                    <label class="flex items-start gap-2 text-sm cursor-pointer">
                        <input
                            v-model="includeExistingActive"
                            type="checkbox"
                            class="mt-0.5 rounded border-gray-300 dark:border-gray-600"
                            :disabled="readOnly || connectionBusy"
                        />
                        <span class="text-gray-700 dark:text-gray-300">
                            Передать колл-центру все активные заказы
                        </span>
                    </label>
                    <button
                        type="button"
                        class="btn-primary btn-sm"
                        :disabled="!connectionCodeInput.trim() || readOnly || connectionBusy"
                        @click="requestConnection"
                    >
                        {{ connectionBusy ? 'Отправка…' : 'Запросить подключение' }}
                    </button>
                </div>
                </div>
            </div>

            <!-- Call center: connection code + incoming requests -->
            <div v-if="canViewSettings && isCallCenter" class="card">
                <h2 class="section-title mb-4 pb-3 border-b border-gray-100 dark:border-gray-700">
                    Подключение магазинов
                </h2>

                <div class="mb-6">
                    <p class="label mb-1">Код подключения</p>
                    <div class="flex flex-col sm:flex-row gap-2 items-start sm:items-center">
                        <code class="text-lg font-mono tracking-widest bg-gray-100 dark:bg-gray-800 px-3 py-2 rounded">
                            {{ connectionCode || '—' }}
                        </code>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="btn-secondary btn-sm"
                                :disabled="!connectionCode"
                                @click="copyConnectionCode"
                            >
                                Копировать
                            </button>
                            <button
                                v-if="canEditSettings"
                                type="button"
                                class="btn-secondary btn-sm"
                                :disabled="readOnly || connectionBusy"
                                @click="regenerateConnectionCode"
                            >
                                Перегенерировать
                            </button>
                        </div>
                    </div>
                    <p class="text-xs text-muted mt-2">Передайте этот код администратору магазина для подключения.</p>
                </div>

                <div class="mb-6">
                    <p class="label mb-1">Распределять новые лиды</p>
                    <div class="flex items-center gap-3 mt-1">
                        <button
                            type="button"
                            :class="[
                                'relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-300',
                                isToggleOn('cc_round_robin') ? 'bg-indigo-600' : 'bg-gray-300 dark:bg-gray-600',
                                (!canEditSettings || readOnly || savingRoundRobin) && 'opacity-60 cursor-not-allowed',
                            ]"
                            :disabled="!canEditSettings || readOnly || savingRoundRobin"
                            @click="toggleRoundRobin"
                        >
                            <span :class="[
                                'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
                                isToggleOn('cc_round_robin') ? 'translate-x-6' : 'translate-x-1',
                            ]" />
                        </button>
                        <span class="text-sm text-muted">{{ isToggleOn('cc_round_robin') ? 'Включено' : 'Выключено' }}</span>
                    </div>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                        Оператор видит только свои; admin и manager — все. Выключено — все видят все заказы.
                    </p>
                </div>

                <div v-if="pendingConnections.length" class="mb-6">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Входящие заявки</h3>
                    <div class="space-y-2">
                        <div
                            v-for="conn in pendingConnections"
                            :key="conn.id"
                            class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 p-3 rounded border border-gray-200 dark:border-gray-700"
                        >
                            <div>
                                <p class="font-medium text-sm">{{ conn.store?.name }}</p>
                                <p class="text-xs text-muted">{{ formatConnectionDate(conn.requested_at) }}</p>
                                <p v-if="conn.include_existing_active" class="text-xs text-amber-700 dark:text-amber-300 mt-1">
                                    Все активные заказы
                                </p>
                            </div>
                            <div v-if="canEditSettings" class="flex gap-2">
                                <button
                                    type="button"
                                    class="btn-primary btn-sm"
                                    :disabled="connectionBusy"
                                    @click="approveConnection(conn.id)"
                                >
                                    Одобрить
                                </button>
                                <button
                                    type="button"
                                    class="btn-secondary btn-sm"
                                    :disabled="connectionBusy"
                                    @click="rejectConnection(conn.id)"
                                >
                                    Отклонить
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="activeConnections.length">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Активные магазины</h3>
                    <div class="space-y-2">
                        <div
                            v-for="conn in activeConnections"
                            :key="conn.id"
                            class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 p-3 rounded border border-gray-200 dark:border-gray-700"
                        >
                            <div>
                                <p class="font-medium text-sm">{{ conn.store?.name }}</p>
                                <p class="text-xs text-muted">с {{ formatConnectionDate(conn.approved_at) }}</p>
                            </div>
                            <button
                                v-if="canEditSettings"
                                type="button"
                                class="btn-secondary btn-sm"
                                :disabled="connectionBusy"
                                @click="disconnectConnection(conn.id)"
                            >
                                Отключить
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tenant settings (admin/manager view; admin edit) -->
            <form v-if="canViewSettings" @submit.prevent="save" class="space-y-6">

                <div
                    v-if="canViewSettings && !canEditSettings"
                    class="flex items-center gap-2 text-sm text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 rounded-md px-4 py-2"
                >
                    <span class="font-medium">Только просмотр</span>
                    <span class="text-amber-600 dark:text-amber-400">— изменение настроек доступно только администратору</span>
                </div>

                <!-- Setting group cards -->
                <div
                    v-for="(group, groupKey) in schema"
                    :id="'settings-' + groupKey"
                    :key="groupKey"
                    class="card scroll-mt-28"
                >
                    <button
                        v-if="isGroupCollapsible(groupKey)"
                        type="button"
                        class="w-full flex items-center justify-between text-left pb-3 mb-4 border-b border-gray-100 dark:border-gray-700"
                        @click="toggleGroup(groupKey)"
                    >
                        <h2 class="section-title">{{ group.label }}</h2>
                        <span class="text-muted text-sm">{{ isGroupOpen(groupKey) ? '▾' : '▸' }}</span>
                    </button>
                    <h2
                        v-else
                        class="section-title mb-4 pb-3 border-b border-gray-100 dark:border-gray-700"
                    >
                        {{ group.label }}
                    </h2>
                    <p v-if="!isGroupOpen(groupKey)" class="text-xs text-muted">
                        по желанию
                    </p>

                    <div v-show="isGroupOpen(groupKey)" class="space-y-4">
                        <template v-for="(meta, key) in group.keys" :key="key">
                            <template v-if="key !== 'cc_round_robin'">
                            <div v-if="isVisible(meta) && key === 'webhook_secret'" class="setting-row space-y-4">
                                <div>
                                    <p class="label mb-1">URL для заявок</p>
                                    <div class="flex flex-col sm:flex-row gap-2 items-start sm:items-center">
                                        <code class="text-sm font-mono break-all bg-gray-100 dark:bg-gray-800 px-3 py-2 rounded min-w-0">
                                            {{ webhookUrl || '—' }}
                                        </code>
                                        <button
                                            type="button"
                                            class="btn-secondary btn-sm"
                                            :disabled="!webhookUrl"
                                            @click="copyWebhookUrl"
                                        >
                                            {{ copiedField === 'url' ? 'Скопировано' : 'Копировать' }}
                                        </button>
                                    </div>
                                </div>

                                <div>
                                    <label class="label">{{ meta[0] }}</label>
                                    <div class="flex flex-col sm:flex-row gap-2 mt-1 items-stretch sm:items-center">
                                        <div class="relative flex-1 min-w-0">
                                            <input
                                                :type="webhookSecretVisible ? 'text' : 'password'"
                                                class="input pr-10"
                                                :value="webhookSecretDisplay"
                                                readonly
                                                autocomplete="off"
                                            />
                                            <button
                                                v-if="canEditSettings"
                                                type="button"
                                                class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                                :disabled="revealingSecret || !hasWebhookSecret"
                                                tabindex="-1"
                                                @click="toggleRevealWebhookSecret"
                                            >
                                                <span v-if="webhookSecretVisible">🙈</span>
                                                <span v-else>👁</span>
                                            </button>
                                        </div>
                                        <div v-if="canEditSettings" class="flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                class="btn-secondary btn-sm"
                                                :disabled="revealingSecret || !hasWebhookSecret"
                                                @click="copyWebhookSecret"
                                            >
                                                {{ copiedField === 'secret' ? 'Скопировано' : 'Копировать' }}
                                            </button>
                                            <button
                                                type="button"
                                                class="btn-secondary btn-sm"
                                                :disabled="generating || readOnly"
                                                @click="generateSecret"
                                            >
                                                {{ generating ? '…' : 'Сгенерировать' }}
                                            </button>
                                        </div>
                                    </div>
                                    <p v-if="webhookJustGenerated" class="text-xs text-amber-700 dark:text-amber-300 mt-1">
                                        Скопируйте сейчас, больше не отобразится.
                                    </p>
                                    <p v-else-if="meta[3]" class="text-xs text-gray-400 dark:text-gray-500 mt-1">{{ meta[3] }}</p>
                                    <p v-if="hasWebhookSecret && !webhookSecretVisible && !webhookJustGenerated"
                                       class="text-xs text-green-600 dark:text-green-400 mt-1">
                                        ✓ Сохранено
                                    </p>
                                </div>

                                <details class="mt-1">
                                    <summary class="text-xs text-indigo-600 dark:text-indigo-400 cursor-pointer select-none hover:text-indigo-700 dark:hover:text-indigo-300">
                                        Пример PHP для сайта
                                    </summary>
                                    <div class="mt-2 text-xs text-gray-600 dark:text-gray-400 space-y-2">
                                        <div class="flex items-start justify-between gap-2">
                                            <p>
                                                Вставьте в обработчик формы вместо Google Apps Script.
                                                Секрет — в заголовке <code class="font-mono">X-Webhook-Token</code>.
                                            </p>
                                            <button
                                                type="button"
                                                class="btn-secondary btn-sm flex-shrink-0"
                                                @click="copyWebhookExample"
                                            >
                                                {{ copiedField === 'example' ? 'Скопировано' : 'Копировать' }}
                                            </button>
                                        </div>
                                        <pre class="bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-md p-3 overflow-x-auto font-mono text-xs text-gray-700 dark:text-gray-300 whitespace-pre">{{ webhookExample }}</pre>
                                    </div>
                                </details>
                            </div>

                            <div v-else-if="isVisible(meta) && key === 'sms_rules'" class="setting-row space-y-3">
                                <p class="label">{{ meta[0] }}</p>
                                <div
                                    v-for="toggle in smsToggleDefs"
                                    :key="toggle.key"
                                    class="flex items-center gap-3"
                                >
                                    <button
                                        type="button"
                                        :class="[
                                            'relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-300',
                                            smsToggles[toggle.key] ? 'bg-indigo-600' : 'bg-gray-300 dark:bg-gray-600',
                                            (!canEditSettings || readOnly) && 'opacity-60 cursor-not-allowed',
                                        ]"
                                        :disabled="!canEditSettings || readOnly"
                                        @click="toggleSmsRule(toggle.key)"
                                    >
                                        <span :class="[
                                            'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
                                            smsToggles[toggle.key] ? 'translate-x-6' : 'translate-x-1',
                                        ]" />
                                    </button>
                                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ toggle.label }}</span>
                                </div>
                                <p v-if="meta[3]" class="text-xs text-gray-400 dark:text-gray-500">{{ meta[3] }}</p>
                            </div>

                            <!-- depends_on: hide if condition not met -->
                            <div v-else-if="isVisible(meta)" class="setting-row">
                                <label class="label">{{ meta[0] }}</label>

                                <!-- toggle -->
                                <div v-if="meta[1] === 'toggle'" class="flex items-center gap-3 mt-1">
                                    <button
                                        type="button"
                                        :class="[
                                            'relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-300',
                                            isToggleOn(key) ? 'bg-indigo-600' : 'bg-gray-300 dark:bg-gray-600',
                                            (!canEditSettings || readOnly) && 'opacity-60 cursor-not-allowed',
                                        ]"
                                        :disabled="!canEditSettings || readOnly"
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
                                    :disabled="!canEditSettings || readOnly"
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
                                    class="input mt-1 font-mono resize-y"
                                    :disabled="!canEditSettings || readOnly"
                                />

                                <!-- text / password -->
                                <div v-else class="flex flex-col sm:flex-row gap-2 mt-1">
                                    <div class="relative flex-1">
                                        <input
                                            :type="visibleKeys[key] ? 'text' : meta[1]"
                                            v-model="form[key]"
                                            :placeholder="passwordOrTextPlaceholder(key, meta)"
                                            class="input pr-10"
                                            :disabled="!canEditSettings || readOnly"
                                        />
                                        <button
                                            v-if="meta[1] === 'password' && canEditSettings"
                                            type="button"
                                            class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                            @click="toggleVisible(key)"
                                            tabindex="-1"
                                        >
                                            <span v-if="visibleKeys[key]">🙈</span>
                                            <span v-else>👁</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- Hint -->
                                <p v-if="meta[3]" class="text-xs text-gray-400 dark:text-gray-500 mt-1">{{ meta[3] }}</p>

                                <details
                                    v-if="key === 'auth_token_bp' && canEditSettings"
                                    class="mt-2"
                                >
                                    <summary class="text-xs text-indigo-600 dark:text-indigo-400 cursor-pointer select-none hover:text-indigo-700 dark:hover:text-indigo-300">
                                        Как получить токен?
                                    </summary>
                                    <div class="mt-2 text-xs text-gray-600 dark:text-gray-400 space-y-2">
                                        <p>
                                            Где взять токен белпочты (браузер Chrome; если другой браузер — те же шаги. Не сработает — писать в Telegram @vitali_rapeika)
                                        </p>
                                        <ol class="list-decimal pl-4 space-y-1">
                                            <li>Откройте кабинет белпочты в браузере</li>
                                            <li>Нажмите Mac — Option + ⌘ + I или Windows — F12</li>
                                            <li>В открывшемся окне откройте, если не открыта, вкладку Network</li>
                                            <li>Под полем Filter найдите Fetch/XHR и кликните, если не выбран</li>
                                            <li>Перезагрузите страницу кабинета белпочты</li>
                                            <li>В списке запросов найдите auth и кликните по нему</li>
                                            <li>Правее появится окно — в нём найдите Authorization</li>
                                            <li>Правее будут данные (начинаются с Bearer); тройным кликом выделить всё, скопировать</li>
                                            <li>Вставить скопированное в поле для токена и сохранить</li>
                                        </ol>
                                    </div>
                                </details>

                                <!-- Current value indicator (password only — text fields are prefilled) -->
                                <p v-if="meta[1] === 'password' && secretPreviewsLocal[key] && !form[key]"
                                   class="text-xs text-green-600 dark:text-green-400 mt-1">
                                    ✓ Сохранено
                                </p>
                                <p v-else-if="meta[1] === 'select' && currentValues[key] && !form[key]"
                                   class="text-xs text-green-600 dark:text-green-400 mt-1">
                                    ✓ Сохранено
                                </p>
                            </div>
                            </template>
                        </template>
                    </div>
                </div>

                <div v-if="canSkipOptional" class="flex items-center justify-between gap-3 text-sm">
                    <p class="text-muted">Остальные сервисы можно подключить позже.</p>
                    <button
                        type="button"
                        class="btn-secondary btn-sm"
                        @click="skipOptional"
                    >
                        Пока не нужно
                    </button>
                </div>

                <div v-if="canEditSettings" class="flex justify-end gap-3">
                    <button type="submit" class="btn-primary" :disabled="saving || readOnly">
                        {{ saving ? 'Сохраняю…' : 'Сохранить настройки' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { Inertia } from '@inertiajs/inertia'
import { usePage } from '@inertiajs/inertia-vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import { useTheme } from '@/composables/useTheme'
import { useSubscription } from '@/composables/useSubscription'
import { useOnboarding } from '@/composables/useOnboarding'
import { apiFetch } from '@/utils/api'

const { readOnly } = useSubscription()
const { dismissed, canSkipOptional, visible, currentStep } = useOnboarding()

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps({
    schema:            { type: Object, default: () => ({}) },
    current:           { type: Object, default: () => ({}) },
    secretPreviews:    { type: Object, default: () => ({}) },
    canViewSettings: { type: Boolean, default: false },
    canEditSettings: { type: Boolean, default: false },
    theme:             { type: String, default: 'system' },
    connectionData:    { type: Object, default: () => ({}) },
    webhook_url:       { type: String, default: '' },
})

const page = usePage()
const tenantType = computed(() => page.props.value.tenant?.type ?? 'store')
const isStore = computed(() => tenantType.value === 'store')
const isCallCenter = computed(() => tenantType.value === 'call_center')

const connectionCode = computed(() => props.connectionData?.connection_code ?? '')
const pendingConnections = computed(() => props.connectionData?.pending_connections ?? [])
const activeConnections = computed(() => props.connectionData?.active_connections ?? [])
const activeConnection = computed(() => props.connectionData?.active_connection ?? null)
const pendingConnection = computed(() => props.connectionData?.pending_connection ?? null)

const connectionCodeInput = ref('')
const includeExistingActive = ref(false)
const connectionBusy = ref(false)
const extraOpen = reactive({ call_center: false })
const expandedGroups = reactive({})
const ALWAYS_OPEN_GROUPS = ['shop', 'belpost']

const collapseOptionalGroups = computed(() =>
    visible.value && currentStep.value !== 'other'
)

function isGroupAlwaysOpen(groupKey) {
    return ALWAYS_OPEN_GROUPS.includes(groupKey)
}

function isGroupCollapsible(groupKey) {
    return collapseOptionalGroups.value && !isGroupAlwaysOpen(groupKey)
}

function isGroupOpen(groupKey) {
    if (isGroupAlwaysOpen(groupKey)) return true
    if (!collapseOptionalGroups.value) return true
    return !!expandedGroups[groupKey]
}

function toggleGroup(groupKey) {
    expandedGroups[groupKey] = !isGroupOpen(groupKey)
}

function toggleExtra(key) {
    extraOpen[key] = !extraOpen[key]
}

function restoreOnboarding() {
    Inertia.post('/onboarding/restore', {}, { preserveScroll: true })
}

function skipOptional() {
    Inertia.post('/onboarding/skip-optional', {}, { preserveScroll: true })
}

function formatConnectionDate(value) {
    if (!value) return '—'
    return new Date(value).toLocaleString('ru-RU', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })
}

async function requestConnection() {
    if (!connectionCodeInput.value.trim() || connectionBusy.value) return
    connectionBusy.value = true
    Inertia.post('/connections', {
        code: connectionCodeInput.value.trim(),
        include_existing_active: includeExistingActive.value,
    }, {
        onFinish: () => { connectionBusy.value = false },
        onSuccess: () => {
            connectionCodeInput.value = ''
            includeExistingActive.value = false
        },
    })
}

function approveConnection(id) {
    connectionBusy.value = true
    Inertia.post(`/connections/${id}/approve`, {}, {
        onFinish: () => { connectionBusy.value = false },
    })
}

function rejectConnection(id) {
    connectionBusy.value = true
    Inertia.post(`/connections/${id}/reject`, {}, {
        onFinish: () => { connectionBusy.value = false },
    })
}

function disconnectConnection(id) {
    connectionBusy.value = true
    Inertia.post(`/connections/${id}/disconnect`, {}, {
        onFinish: () => { connectionBusy.value = false },
    })
}

async function regenerateConnectionCode() {
    if (connectionBusy.value || readOnly.value) return
    connectionBusy.value = true
    try {
        const resp = await apiFetch('/settings/regenerate-connection-code', 'POST')
        const data = await resp.json()
        if (data.success) {
            Inertia.reload({ only: ['connectionData'] })
        }
    } finally {
        connectionBusy.value = false
    }
}

async function copyConnectionCode() {
    if (!connectionCode.value) return
    try {
        await navigator.clipboard.writeText(connectionCode.value)
    } catch {
        // fallback silent
    }
}

// ── Theme ─────────────────────────────────────────────────────────────────────
const { preference, setTheme } = useTheme()
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
const secretPreviewsLocal = ref({ ...props.secretPreviews })

const form = reactive((() => {
    const f = {}
    for (const group of Object.values(props.schema)) {
        for (const [key, meta] of Object.entries(group.keys)) {
            const type = meta[1]
            if (type === 'select') {
                f[key] = props.current[key] ?? (meta[4] ? Object.keys(meta[4])[0] : '')
            } else if (type === 'toggle') {
                f[key] = props.current[key] ?? ''
            } else if (type === 'password') {
                f[key] = ''
            } else if (type === 'custom') {
                f[key] = props.current[key] ?? ''
            } else {
                // text / textarea — show saved values
                f[key] = props.current[key] ?? ''
            }
        }
    }
    return f
})())

const visibleKeys = reactive({})
const saving      = ref(false)
const savingRoundRobin = ref(false)
const generating  = ref(false)
const revealingSecret = ref(false)
const revealedWebhookSecret = ref('')
const webhookSecretVisible = ref(false)
const webhookJustGenerated = ref(false)
const copiedField = ref('')
let copiedTimer = null

const webhookUrl = computed(() => props.webhook_url || '')
const hasWebhookSecret = computed(() =>
    !!secretPreviewsLocal.value.webhook_secret || !!revealedWebhookSecret.value
)
const webhookSecretDisplay = computed(() => {
    if (webhookSecretVisible.value && revealedWebhookSecret.value) {
        return revealedWebhookSecret.value
    }
    return secretPreviewsLocal.value.webhook_secret || ''
})
const webhookExample = computed(() => {
    const url = webhookUrl.value || 'https://example.com/api/webhook/lead'
    return [
        '// --- ОТПРАВКА ЗАЯВКИ В CRM ---',
        '$formData = [',
        "    'name'    => $name ?? '',",
        "    'offer'   => $offer ?? '',",
        "    'phone'   => $phone ?? '',",
        "    'options' => $options ?? 0,",
        "    'source'  => 'site',",
        '];',
        '',
        `$crmUrl = '${url}';`,
        "$token  = 'СЮДА_СЕКРЕТ'; // скопируйте секрет кнопкой выше",
        '',
        '$dataString = json_encode($formData, JSON_UNESCAPED_UNICODE);',
        'exec("curl -s -X POST'
            + " -H 'Content-Type: application/json'"
            + " -H 'X-Webhook-Token: $token'"
            + " -d '$dataString' '$crmUrl'"
            + ' > /dev/null 2>&1 &");',
    ].join('\n')
})

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
    if (!props.canEditSettings || readOnly.value) return
    const next = isToggleOn(key) ? '' : '1'
    form[key] = next
}

function toggleRoundRobin() {
    if (!props.canEditSettings || readOnly.value || savingRoundRobin.value) return
    const next = isToggleOn('cc_round_robin') ? '' : '1'
    form.cc_round_robin = next
    savingRoundRobin.value = true
    Inertia.post('/settings', { settings: { cc_round_robin: next } }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            currentValues.value.cc_round_robin = next
        },
        onFinish: () => { savingRoundRobin.value = false },
    })
}

const smsToggleDefs = [
    { key: 'shipped', label: 'Отправка' },
    { key: 'arrived', label: 'В отделении' },
    { key: 'reminder1', label: 'Напоминание 5 день' },
    { key: 'reminder2', label: 'Напоминание 10 день' },
]

function parseSmsToggles(rules) {
    const s = String(rules || '')
    const day1 = String(form.sms_reminder_day_1 || '5').trim() || '5'
    const day2 = String(form.sms_reminder_day_2 || '10').trim() || '10'
    return {
        shipped: s.includes('Отправка'),
        arrived: s.includes('В отделении'),
        reminder1: s.includes(`Напоминание ${day1} день`) || s.includes('Напоминание 5 день'),
        reminder2: s.includes(`Напоминание ${day2} день`) || s.includes('Напоминание 10 день'),
    }
}

const smsToggles = reactive(parseSmsToggles(props.current.sms_rules || form.sms_rules || ''))

function toggleSmsRule(key) {
    if (!props.canEditSettings || readOnly.value) return
    smsToggles[key] = !smsToggles[key]
}

function assembleSmsRules() {
    const day1 = String(form.sms_reminder_day_1 || '5').trim() || '5'
    const day2 = String(form.sms_reminder_day_2 || '10').trim() || '10'
    const parts = []
    if (smsToggles.shipped) parts.push('Отправка')
    if (smsToggles.arrived) parts.push('В отделении')
    if (smsToggles.reminder1) parts.push(`Напоминание ${day1} день`)
    if (smsToggles.reminder2) parts.push(`Напоминание ${day2} день`)
    return parts.join(',')
}

function maskedPlaceholder(key) {
    return secretPreviewsLocal.value[key] ?? ''
}

function passwordOrTextPlaceholder(key, meta) {
    const type = meta[1]
    if (type === 'password') {
        return maskedPlaceholder(key) || meta[2]
    }
    return meta[2]
}

function maskClientSecret(value) {
    if (!value) return ''
    return String(value).slice(0, 4) + '••••••••'
}

function settingType(key) {
    for (const group of Object.values(props.schema)) {
        if (group.keys[key]) return group.keys[key][1]
    }
    return 'text'
}

function toggleVisible(key) {
    visibleKeys[key] = !visibleKeys[key]
}

// ── Save ──────────────────────────────────────────────────────────────────────
function save() {
    if (!props.canEditSettings || readOnly.value) return
    const settings = {}

    for (const group of Object.values(props.schema)) {
        for (const [key, meta] of Object.entries(group.keys)) {
            if (key === 'webhook_secret' || key === 'sms_rules') {
                continue
            }
            const type = meta[1]
            const raw  = form[key]

            if (type === 'toggle') {
                settings[key] = raw === '1' ? '1' : ''
            } else if (type === 'select') {
                if (raw && String(raw).trim() !== '') {
                    settings[key] = String(raw).trim()
                }
            } else if (type === 'custom') {
                continue
            } else {
                if (raw && String(raw).trim() !== '') {
                    settings[key] = String(raw).trim()
                }
            }
        }
    }

    if (props.schema.sms && props.schema.sms.keys && props.schema.sms.keys.sms_rules !== undefined) {
        settings.sms_rules = assembleSmsRules()
    }

    saving.value = true
    Inertia.post('/settings', { settings }, {
        preserveState: true,
        onSuccess: () => {
            Object.entries(settings).forEach(([k, v]) => {
                const type = settingType(k)
                if (type === 'password') {
                    // Never keep full secret in client state after save
                    secretPreviewsLocal.value[k] = maskClientSecret(v)
                    delete currentValues.value[k]
                } else {
                    currentValues.value[k] = v
                }
            })
            for (const group of Object.values(props.schema)) {
                for (const [key, meta] of Object.entries(group.keys)) {
                    const type = meta[1]
                    if (type === 'password') {
                        form[key] = ''
                    } else if (type === 'text' || type === 'textarea') {
                        form[key] = currentValues.value[key] ?? form[key]
                    }
                }
            }
        },
        onFinish: () => { saving.value = false },
    })
}

// ── Webhook secret ────────────────────────────────────────────────────────────
function markCopied(field) {
    copiedField.value = field
    clearTimeout(copiedTimer)
    copiedTimer = setTimeout(() => { copiedField.value = '' }, 2000)
}

async function copyText(value, field) {
    if (!value) return
    try {
        await navigator.clipboard.writeText(value)
        markCopied(field)
    } catch {
        // fallback silent
    }
}

function copyWebhookUrl() {
    copyText(webhookUrl.value, 'url')
}

function copyWebhookExample() {
    copyText(webhookExample.value, 'example')
}

async function fetchWebhookSecret() {
    if (revealedWebhookSecret.value) {
        return revealedWebhookSecret.value
    }
    revealingSecret.value = true
    try {
        const resp = await apiFetch('/settings/reveal-webhook-secret', 'POST')
        const data = await resp.json()
        if (data.success && data.secret) {
            revealedWebhookSecret.value = data.secret
            return data.secret
        }
    } finally {
        revealingSecret.value = false
    }
    return ''
}

async function toggleRevealWebhookSecret() {
    if (webhookSecretVisible.value) {
        webhookSecretVisible.value = false
        webhookJustGenerated.value = false
        return
    }
    const secret = await fetchWebhookSecret()
    if (secret) {
        webhookSecretVisible.value = true
    }
}

async function copyWebhookSecret() {
    const secret = webhookSecretVisible.value && revealedWebhookSecret.value
        ? revealedWebhookSecret.value
        : await fetchWebhookSecret()
    if (secret) {
        await copyText(secret, 'secret')
    }
}

async function generateSecret() {
    if (!props.canEditSettings || readOnly.value) return
    if (hasWebhookSecret.value) {
        if (!window.confirm('Старый секрет перестанет работать. Сгенерировать новый?')) {
            return
        }
    }
    generating.value = true
    try {
        const resp = await apiFetch('/settings/generate-webhook-secret', 'POST')
        const data = await resp.json()
        if (data.success) {
            revealedWebhookSecret.value = data.secret
            secretPreviewsLocal.value.webhook_secret = maskClientSecret(data.secret)
            delete currentValues.value.webhook_secret
            if (form.webhook_secret !== undefined) {
                form.webhook_secret = ''
            }
            webhookSecretVisible.value = true
            webhookJustGenerated.value = true
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
