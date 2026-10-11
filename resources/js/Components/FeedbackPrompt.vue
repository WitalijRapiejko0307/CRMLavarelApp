<template>
    <div
        v-if="open"
        class="modal-overlay p-4"
        @click.self="postpone"
    >
        <div
            class="modal-box relative max-w-lg max-h-[min(90vh,720px)] overflow-y-auto"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="titleId"
        >
            <button
                type="button"
                class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 flex-shrink-0"
                title="Закрыть"
                :disabled="laterProcessing"
                @click="postpone"
            >
                ✕
            </button>

            <h2 :id="titleId" class="section-title mb-1 pr-8">
                {{ title }}
            </h2>
            <p class="text-xs text-muted mb-5">
                Ответы увидит владелец продукта, это займёт около минуты.
            </p>

            <form v-if="isEarly" class="space-y-5" @submit.prevent="submitEarly">
                <div>
                    <p class="label mb-2">Насколько удобно вести заказы в CRM?</p>
                    <div class="flex flex-wrap gap-2" role="group" aria-label="Насколько удобно вести заказы в CRM?">
                        <button
                            v-for="n in 5"
                            :key="'orders-' + n"
                            type="button"
                            :aria-pressed="earlyForm.orders_score === n"
                            :class="scoreButtonClass(earlyForm.orders_score === n)"
                            @click="earlyForm.orders_score = n"
                        >
                            {{ n }}
                        </button>
                    </div>
                    <p v-if="earlyForm.errors.orders_score" class="mt-1 text-xs text-red-600 dark:text-red-400">
                        {{ earlyForm.errors.orders_score }}
                    </p>
                </div>
                <div>
                    <p class="label mb-2">Насколько понятны Белпочта и Европочта?</p>
                    <div class="flex flex-wrap gap-2" role="group" aria-label="Насколько понятны Белпочта и Европочта?">
                        <button
                            v-for="n in 5"
                            :key="'postal-' + n"
                            type="button"
                            :aria-pressed="earlyForm.postal_score === n"
                            :class="scoreButtonClass(earlyForm.postal_score === n)"
                            @click="earlyForm.postal_score = n"
                        >
                            {{ n }}
                        </button>
                    </div>
                    <p v-if="earlyForm.errors.postal_score" class="mt-1 text-xs text-red-600 dark:text-red-400">
                        {{ earlyForm.errors.postal_score }}
                    </p>
                </div>

                <fieldset>
                    <legend class="label mb-2">Чего не хватает в первую очередь?</legend>
                    <div class="space-y-2">
                        <label
                            v-for="opt in missingOptions"
                            :key="opt.value"
                            class="flex items-center gap-2 text-sm text-body cursor-pointer"
                        >
                            <input
                                v-model="earlyForm.missing"
                                type="radio"
                                name="missing"
                                :value="opt.value"
                                class="text-indigo-600 focus:ring-indigo-500 dark:bg-gray-900 dark:border-gray-600"
                            />
                            <span>{{ opt.label }}</span>
                        </label>
                    </div>
                    <p v-if="earlyForm.errors.missing" class="mt-1 text-xs text-red-600 dark:text-red-400">
                        {{ earlyForm.errors.missing }}
                    </p>
                </fieldset>

                <div v-if="earlyForm.missing === 'other'">
                    <label for="feedback-missing-other" class="label mb-1">Уточните</label>
                    <input
                        id="feedback-missing-other"
                        v-model="earlyForm.missing_other"
                        type="text"
                        maxlength="200"
                        class="w-full"
                        :class="{ 'border-red-500': earlyForm.errors.missing_other }"
                    />
                    <p v-if="earlyForm.errors.missing_other" class="mt-1 text-xs text-red-600 dark:text-red-400">
                        {{ earlyForm.errors.missing_other }}
                    </p>
                </div>

                <div>
                    <label for="feedback-improve" class="label mb-1">Что улучшить в первую очередь?</label>
                    <textarea
                        id="feedback-improve"
                        v-model="earlyForm.improve"
                        rows="3"
                        maxlength="1000"
                        class="w-full"
                        :class="{ 'border-red-500': earlyForm.errors.improve }"
                    />
                    <p v-if="earlyForm.errors.improve" class="mt-1 text-xs text-red-600 dark:text-red-400">
                        {{ earlyForm.errors.improve }}
                    </p>
                </div>

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-1">
                    <button
                        type="button"
                        class="btn-secondary justify-center"
                        :disabled="earlyForm.processing || laterProcessing"
                        @click="postpone"
                    >
                        Позже
                    </button>
                    <button
                        type="submit"
                        class="btn-primary justify-center"
                        :disabled="earlyForm.processing || laterProcessing"
                        :class="{ 'opacity-50 cursor-not-allowed': earlyForm.processing }"
                    >
                        {{ earlyForm.processing ? 'Отправка…' : 'Отправить' }}
                    </button>
                </div>
            </form>

            <form v-else-if="isAdvanced" class="space-y-5" @submit.prevent="submitAdvanced">
                <div>
                    <p class="label mb-2">Общая оценка</p>
                    <div class="flex flex-wrap gap-2" role="group" aria-label="Общая оценка">
                        <button
                            v-for="n in 5"
                            :key="'score-' + n"
                            type="button"
                            :aria-pressed="advancedForm.score === n"
                            :class="scoreButtonClass(advancedForm.score === n)"
                            @click="advancedForm.score = n"
                        >
                            {{ n }}
                        </button>
                    </div>
                    <p v-if="advancedForm.errors.score" class="mt-1 text-xs text-red-600 dark:text-red-400">
                        {{ advancedForm.errors.score }}
                    </p>
                </div>

                <div>
                    <label for="feedback-comment" class="label mb-1">Что изменилось и чего всё ещё не хватает?</label>
                    <textarea
                        id="feedback-comment"
                        v-model="advancedForm.comment"
                        rows="5"
                        required
                        maxlength="5000"
                        class="w-full"
                        :class="{ 'border-red-500': advancedForm.errors.comment }"
                    />
                    <p v-if="advancedForm.errors.comment" class="mt-1 text-xs text-red-600 dark:text-red-400">
                        {{ advancedForm.errors.comment }}
                    </p>
                </div>

                <div>
                    <label for="feedback-telegram" class="label mb-1">Ник Telegram (по желанию)</label>
                    <input
                        id="feedback-telegram"
                        v-model="advancedForm.telegram"
                        type="text"
                        maxlength="64"
                        class="w-full"
                        :class="{ 'border-red-500': advancedForm.errors.telegram }"
                    />
                    <p v-if="advancedForm.errors.telegram" class="mt-1 text-xs text-red-600 dark:text-red-400">
                        {{ advancedForm.errors.telegram }}
                    </p>
                </div>

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-1">
                    <button
                        type="button"
                        class="btn-secondary justify-center"
                        :disabled="advancedForm.processing || laterProcessing"
                        @click="postpone"
                    >
                        Позже
                    </button>
                    <button
                        type="submit"
                        class="btn-primary justify-center"
                        :disabled="advancedForm.processing || laterProcessing"
                        :class="{ 'opacity-50 cursor-not-allowed': advancedForm.processing }"
                    >
                        {{ advancedForm.processing ? 'Отправка…' : 'Отправить' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/inertia-vue3'
import { Inertia } from '@inertiajs/inertia'

const page = usePage()

const stage = computed(() => {
    const value = page.props.value.feedback_prompt
    return value === 'early' || value === 'advanced' ? value : null
})

const dismissedLocally = ref(false)
const laterProcessing = ref(false)

const open = computed(() => stage.value !== null && !dismissedLocally.value)

const isEarly = computed(() => stage.value === 'early')
const isAdvanced = computed(() => stage.value === 'advanced')

const titleId = 'feedback-prompt-title'

const title = computed(() =>
    isAdvanced.value ? 'Как CRM спустя месяц работы?' : 'Как вам CRM за первые дни?'
)

const missingOptions = [
    { value: 'speed', label: 'Скорость' },
    { value: 'blanks', label: 'Бланки и трекинг' },
    { value: 'stock', label: 'Склад' },
    { value: 'reports', label: 'Отчёты' },
    { value: 'other', label: 'Другое' },
]

const earlyForm = useForm({
    stage: 'early',
    orders_score: null,
    postal_score: null,
    missing: '',
    missing_other: '',
    improve: '',
})

const advancedForm = useForm({
    stage: 'advanced',
    score: null,
    comment: '',
    telegram: '',
})

watch(stage, (value, previous) => {
    if (value === previous) return
    dismissedLocally.value = false
    laterProcessing.value = false
    if (value === 'early') {
        earlyForm.reset()
        earlyForm.clearErrors()
    } else if (value === 'advanced') {
        advancedForm.reset()
        advancedForm.clearErrors()
    }
})

function scoreButtonClass(selected) {
    return [
        'min-w-[2.5rem] h-10 px-3 rounded-md border text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800',
        selected
            ? 'bg-indigo-600 border-indigo-600 text-white'
            : 'bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:border-indigo-400 dark:hover:border-indigo-500',
    ]
}

function onPromptHandled() {
    dismissedLocally.value = true
}

function submitEarly() {
    earlyForm.post('/feedback', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: onPromptHandled,
    })
}

function submitAdvanced() {
    advancedForm.post('/feedback', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: onPromptHandled,
    })
}

function postpone() {
    const current = stage.value
    if (!current || laterProcessing.value) return
    laterProcessing.value = true
    Inertia.post('/feedback/later', { stage: current }, {
        preserveScroll: true,
        onSuccess: onPromptHandled,
        onFinish: () => {
            laterProcessing.value = false
        },
    })
}
</script>
