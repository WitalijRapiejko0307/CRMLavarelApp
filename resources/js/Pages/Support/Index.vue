<template>
    <AppLayout>
        <template #header>
            <PageHeader>
                <template #title>
                    <h1 class="page-title">Поддержка</h1>
                </template>
            </PageHeader>
        </template>

        <div class="max-w-2xl mx-auto space-y-6">
            <div class="card">
                <p class="text-sm text-muted mb-4">
                    Опишите вопрос или проблему — мы ответим на вашу почту.
                    Или напишите нам в Telegram:
                    <a
                        :href="telegram_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-indigo-600 dark:text-indigo-400 hover:underline font-medium"
                    >
                        @{{ telegramUsername }}
                    </a>
                </p>

                <form @submit.prevent="submit" class="space-y-5">
                    <div>
                        <label for="reply_email" class="label mb-1">Email для ответа</label>
                        <input
                            id="reply_email"
                            v-model="form.reply_email"
                            type="email"
                            required
                            maxlength="255"
                            autocomplete="email"
                            class="w-full"
                            :class="{ 'border-red-500': form.errors.reply_email }"
                        />
                        <p v-if="form.errors.reply_email" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.reply_email }}</p>
                    </div>

                    <div>
                        <label for="subject" class="label mb-1">Тема</label>
                        <input
                            id="subject"
                            v-model="form.subject"
                            type="text"
                            required
                            maxlength="200"
                            class="w-full"
                            :class="{ 'border-red-500': form.errors.subject }"
                        />
                        <p v-if="form.errors.subject" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.subject }}</p>
                    </div>

                    <div>
                        <label for="message" class="label mb-1">Сообщение</label>
                        <textarea
                            id="message"
                            v-model="form.message"
                            rows="6"
                            required
                            maxlength="5000"
                            class="w-full"
                            :class="{ 'border-red-500': form.errors.message }"
                        />
                        <p v-if="form.errors.message" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.message }}</p>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="btn-primary"
                        :class="{ 'opacity-50 cursor-not-allowed': form.processing }"
                    >
                        <span v-if="form.processing">Отправка…</span>
                        <span v-else>Отправить</span>
                    </button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/inertia-vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'

const props = defineProps({
    telegram_url: { type: String, required: true },
    reply_email: { type: String, default: '' },
})

const telegramUsername = computed(() => {
    const parts = props.telegram_url.split('/')
    return parts[parts.length - 1] || ''
})

const form = useForm({
    reply_email: props.reply_email,
    subject: '',
    message: '',
})

function submit() {
    form.post('/support', {
        preserveScroll: true,
        onSuccess: () => form.reset('message'),
    })
}
</script>
