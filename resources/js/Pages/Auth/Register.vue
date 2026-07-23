<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-100 dark:bg-gray-950">
        <div class="w-full max-w-md">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-indigo-700 dark:text-indigo-400">BaseCRM</h1>
                <p class="text-muted mt-1 text-sm">Регистрация компании</p>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md dark:shadow-none dark:border dark:border-gray-700 p-8">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-6">Создать аккаунт</h2>

                <form @submit.prevent="submit" class="space-y-5">
                    <div>
                        <label for="company_name" class="label mb-1">Название компании</label>
                        <input
                            id="company_name"
                            v-model="form.company_name"
                            type="text"
                            required
                            class="w-full"
                            :class="{ 'border-red-500': form.errors.company_name }"
                        />
                        <p v-if="form.errors.company_name" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.company_name }}</p>
                    </div>

                    <div>
                        <label for="name" class="label mb-1">Ваше имя</label>
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            required
                            class="w-full"
                            :class="{ 'border-red-500': form.errors.name }"
                        />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.name }}</p>
                    </div>

                    <div>
                        <label for="email" class="label mb-1">Email</label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            autocomplete="email"
                            required
                            class="w-full"
                            :class="{ 'border-red-500': form.errors.email }"
                        />
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <label for="password" class="label mb-1">Пароль</label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            autocomplete="new-password"
                            required
                            class="w-full"
                            :class="{ 'border-red-500': form.errors.password }"
                        />
                        <p v-if="form.errors.password" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.password }}</p>
                    </div>

                    <div>
                        <label for="password_confirmation" class="label mb-1">Подтверждение пароля</label>
                        <input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            required
                            class="w-full"
                        />
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full btn-primary justify-center py-2.5"
                        :class="{ 'opacity-50 cursor-not-allowed': form.processing }"
                    >
                        <span v-if="form.processing">Регистрация…</span>
                        <span v-else>Зарегистрироваться</span>
                    </button>
                </form>

                <p class="mt-6 text-center text-sm text-muted">
                    Уже есть аккаунт?
                    <Link href="/login" class="text-indigo-600 dark:text-indigo-400 hover:underline">Войти</Link>
                </p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/inertia-vue3'

const form = useForm({
    company_name: '',
    name:         '',
    email:        '',
    password:     '',
    password_confirmation: '',
})

function submit() {
    form.post('/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>
