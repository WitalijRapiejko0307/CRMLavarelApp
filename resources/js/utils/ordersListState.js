// Запоминает фильтры/сортировку/страницу списка заказов (в рамках вкладки),
// чтобы после захода в заказ и возврата список оставался в том же статусе.
const KEY = 'orders_list_query'

export function saveOrdersListQuery(search) {
    try {
        sessionStorage.setItem(KEY, search || '')
    } catch (e) { /* storage недоступен */ }
}

export function getOrdersListQuery() {
    try {
        return sessionStorage.getItem(KEY) || ''
    } catch (e) {
        return ''
    }
}

export function ordersListUrl() {
    const q = getOrdersListQuery()
    return q ? `/orders${q.startsWith('?') ? q : '?' + q}` : '/orders'
}
