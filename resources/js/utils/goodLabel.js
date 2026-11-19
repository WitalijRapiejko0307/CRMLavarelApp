/**
 * "Product (variation)" label for position i of an order.
 * order.variations is parallel to order.goods; on a length mismatch it is ignored.
 */
export function goodLabel(order, i) {
    const goods = order?.goods ?? []
    const name = String(goods[i] ?? '')
    const variations = order?.variations
    const v = Array.isArray(variations) && variations.length === goods.length ? variations[i] : null

    if (!v) return name

    const vName = v.name && String(v.name).trim()
    return `${name} (${vName || 'Вариация ' + v.number})`
}
