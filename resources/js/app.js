import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/inertia-vue3'
import { InertiaProgress } from '@inertiajs/progress'
import { initTheme } from './composables/useTheme'
import { Inertia } from '@inertiajs/inertia'
import '../css/app.css'

// Событие регистрации уходит один раз: сервер кладёт event_id во flash только после создания кабинета
Inertia.on('success', event => {
    const eventId = event.detail.page.props.flash?.registered_event_id
    if (!eventId) return
    if (window.fbq) window.fbq('track', 'CompleteRegistration', {}, { eventID: eventId })
    if (window.ttq) window.ttq.track('CompleteRegistration', {}, { event_id: eventId })
})

InertiaProgress.init({
    color: '#4f46e5',
    includeCSS: true,
    showSpinner: true,
})

createInertiaApp({
    resolve: name => require(`./Pages/${name}`).default,
    setup({ el, App, props, plugin }) {
        const userTheme = props.initialPage?.props?.auth?.user?.theme ?? null
        initTheme(userTheme)

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el)
    },
    title: title => title ? `${title} — BaseCRM` : 'BaseCRM',
})
