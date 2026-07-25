import { ref, onUnmounted } from 'vue'

const GAP = 4
const MIN_MENU_HEIGHT = 120

export function useDropdownPosition(triggerRef, menuRef, { maxMenuHeight = 240, minWidth = 160 } = {}) {
    const menuStyle = ref({})
    const openUp = ref(false)

    let scrollParents = []
    let onClose = null

    function updatePosition() {
        const el = triggerRef.value
        if (!el) return

        const rect = el.getBoundingClientRect()
        const spaceBelow = window.innerHeight - rect.bottom - GAP
        const spaceAbove = rect.top - GAP
        const shouldOpenUp = spaceBelow < maxMenuHeight && spaceAbove > spaceBelow
        openUp.value = shouldOpenUp

        const available = shouldOpenUp ? spaceAbove : spaceBelow
        const height = Math.min(maxMenuHeight, Math.max(MIN_MENU_HEIGHT, available))
        const width = Math.max(rect.width, minWidth)

        let left = rect.left
        if (left + width > window.innerWidth - GAP) {
            left = Math.max(GAP, window.innerWidth - width - GAP)
        }

        menuStyle.value = {
            position: 'fixed',
            left: `${left}px`,
            top: shouldOpenUp ? `${rect.top - height - GAP}px` : `${rect.bottom + GAP}px`,
            width: `${width}px`,
            maxHeight: `${height}px`,
            overflowY: 'auto',
            zIndex: 60,
        }
    }

    function getScrollParents(element) {
        const parents = []
        let node = element?.parentElement
        while (node) {
            const { overflow, overflowY, overflowX } = getComputedStyle(node)
            const scrollable = [overflow, overflowY, overflowX].some(v => /auto|scroll|overlay/.test(v))
            if (scrollable) parents.push(node)
            node = node.parentElement
        }
        return parents
    }

    function handleScroll(event) {
        const menu = menuRef?.value
        if (menu && (menu === event.target || menu.contains(event.target))) return
        onClose?.()
    }

    function attachListeners(closeFn) {
        onClose = closeFn
        updatePosition()
        window.addEventListener('resize', updatePosition)
        window.addEventListener('scroll', handleScroll, true)
        scrollParents = getScrollParents(triggerRef.value)
        scrollParents.forEach(p => p.addEventListener('scroll', handleScroll, { passive: true }))
    }

    function detachListeners() {
        window.removeEventListener('resize', updatePosition)
        window.removeEventListener('scroll', handleScroll, true)
        scrollParents.forEach(p => p.removeEventListener('scroll', handleScroll))
        scrollParents = []
        onClose = null
    }

    onUnmounted(detachListeners)

    return { menuStyle, openUp, updatePosition, attachListeners, detachListeners }
}
