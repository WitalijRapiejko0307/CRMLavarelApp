const BULK_STATUS_WARNINGS = {
    'Отправлено': 'Будет выполнено списание товаров со склада для всех выбранных заказов.',
    'Возврат':    'Будет выполнен возврат товаров на склад (для заказов, ранее отправленных).',
    'Завершен':   'Будет выполнен учёт выручки по товарам (Белпочта/Европочта).',
}

export function bulkStatusWarning(status, confirmStatuses) {
    if (!confirmStatuses.includes(status)) {
        return ''
    }
    return BULK_STATUS_WARNINGS[status] ?? ''
}
