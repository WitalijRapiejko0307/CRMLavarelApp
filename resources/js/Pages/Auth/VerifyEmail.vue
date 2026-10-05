<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-100 dark:bg-gray-950">
        <div class="w-full max-w-md">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-indigo-700 dark:text-indigo-400">BaseCRM</h1>
                <p class="text-muted mt-1 text-sm">Подтверждение email</p>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md dark:shadow-none dark:border dark:border-gray-700 p-8">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-2">Введите код из письма</h2>
                <p class="text-sm text-muted mb-6">
                    Мы отправили 6-значный код на <span class="font-medium text-gray-700 dark:text-gray-200">{{ email }}</span>.
                </p>

                <div v-if="flashMessage" class="mb-4 text-sm text-green-700 dark:text-green-300 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded-md px-3 py-2">
                    {{ flashMessage }}
                </div>

                <form @submit.prevent="submit" class="space-y-5">
                    <div>
                        <label for="code" class="label mb-1">Код подтверждения</label>
                        <input
                            id="code"
                            v-model="form.code"
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="6"
                            required
                            class="w-full tracking-widest text-center text-lg"
                            :class="{ 'border-red-500': form.errors.code }"
                        />
                        <p v-if="form.errors.code" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.code }}</p>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full btn-primary justify-center py-2.5"
                        :class="{ 'opacity-50 cursor-not-allowed': form.processing }"
                    >
                        <span v-if="form.processing">Проверка…</span>
                        <span v-else>Подтвердить</span>
                    </button>
                </form>

                <div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-3 text-sm">
                    <button
                        type="button"
                        class="text-indigo-600 dark:text-indigo-400 hover:underline disabled:opacity-50"
                        :disabled="resendForm.processing || resendCooldown > 0"
                        @click="resend"
                    >
                        <span v-if="resendCooldown > 0">Отправить снова ({{ resendCooldown }} с)</span>
                        <span v-else-if="resendForm.processing">Отправка…</span>
                        <span v-else>Отправить код снова</span>
                    </button>
                    <button
                        type="button"
                        class="text-muted hover:text-gray-700 dark:hover:text-gray-200"
                        @click="logout"
                    >
                        Выйти
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onUnmounted, ref } from 'vue'
import { useForm, usePage } from '@inertiajs/inertia-vue3'
import { Inertia } from '@inertiajs/inertia'

const props = defineProps({
    email: { type: String, required: true },
})

const page = usePage()
const flashMessage = computed(() => page.props.value.flash?.message)

const form = useForm({
    code: '',
})

const resendForm = useForm({})
const resendCooldown = ref(0)
let cooldownTimer = null

function startCooldown(seconds = 60) {
    resendCooldown.value = seconds
    if (cooldownTimer) {
        clearInterval(cooldownTimer)
    }
    cooldownTimer = setInterval(() => {
        if (resendCooldown.value <= 1) {
            resendCooldown.value = 0
            clearInterval(cooldownTimer)
            cooldownTimer = null
            return
        }
        resendCooldown.value -= 1
    }, 1000)
}

function submit() {
    form.post('/email/verify', {
        onFinish: () => {},
    })
}

function resend() {
    resendForm.post('/email/verify/resend', {
        preserveScroll: true,
        onSuccess: () => startCooldown(60),
    })
}

function logout() {
    Inertia.post('/logout')
}

onUnmounted(() => {
    if (cooldownTimer) {
        clearInterval(cooldownTimer)
    }
})
</script>
