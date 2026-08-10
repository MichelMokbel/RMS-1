import { Editor, Node } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import Link from '@tiptap/extension-link'
import TextAlign from '@tiptap/extension-text-align'
import Underline from '@tiptap/extension-underline'
import Sortable from 'sortablejs'

const editors = new WeakMap()
const sortables = new WeakMap()
const saveTimers = new WeakMap()

const QuotationPricing = Node.create({
    name: 'quotationPricing',
    group: 'block',
    atom: true,
    selectable: true,
    parseHTML: () => [{ tag: 'div[data-quotation-pricing]' }],
    renderHTML: () => ['div', {
        'data-quotation-pricing': '',
        class: 'my-3 rounded-md border border-primary-200 bg-primary-50 px-4 py-3 text-center text-sm font-medium text-primary-800',
    }, 'Price per person and minimum order will appear here'],
})

const QuotationImage = Node.create({
    name: 'quotationImage',
    group: 'block',
    atom: true,
    selectable: true,
    addAttributes: () => ({
        assetId: { default: null },
        alt: { default: '' },
        widthMm: { default: null },
        alignment: { default: 'center' },
        direction: { default: 'auto' },
    }),
    parseHTML: () => [{ tag: 'div[data-quotation-image]' }],
    renderHTML: ({ node }) => ['div', {
        'data-quotation-image': String(node.attrs.assetId || ''),
        class: 'my-3 rounded-md border border-dashed border-neutral-300 bg-neutral-50 px-4 py-8 text-center text-sm text-neutral-500',
    }, node.attrs.alt || `Registered image #${node.attrs.assetId || ''}`],
})

const PageBreak = Node.create({
    name: 'pageBreak',
    group: 'block',
    atom: true,
    selectable: true,
    parseHTML: () => [{ tag: 'div[data-page-break]' }],
    renderHTML: () => ['div', {
        'data-page-break': '',
        class: 'my-5 border-t-2 border-dashed border-neutral-300 py-2 text-center text-xs font-medium uppercase tracking-wide text-neutral-400',
    }, 'Page break'],
})

const DocumentSpacer = Node.create({
    name: 'documentSpacer',
    group: 'block',
    atom: true,
    selectable: true,
    addAttributes: () => ({ heightMm: { default: 10 } }),
    parseHTML: () => [{ tag: 'div[data-document-spacer]' }],
    renderHTML: ({ node }) => ['div', {
        'data-document-spacer': String(node.attrs.heightMm || 10),
        class: 'my-2 rounded border border-dashed border-neutral-200 py-2 text-center text-xs text-neutral-400',
    }, `Spacer (${node.attrs.heightMm || 10} mm)`],
})

function livewireFor(element) {
    return element.closest('[wire\\:id]')?.__livewire
}

function initEditors(root = document) {
    root.querySelectorAll('[data-quotation-editor]').forEach((element) => {
        if (editors.has(element)) return

        let content = { type: 'doc', content: [{ type: 'paragraph' }] }
        try {
            content = JSON.parse(element.dataset.content || '')
        } catch (_) {
            content = { type: 'doc', content: [{ type: 'paragraph' }] }
        }

        const editor = new Editor({
            element,
            content,
            extensions: [
                StarterKit.configure({
                    heading: { levels: [1, 2, 3] },
                    blockquote: false,
                    code: false,
                    codeBlock: false,
                    horizontalRule: false,
                    strike: false,
                }),
                Underline,
                Link.configure({ openOnClick: false, protocols: ['http', 'https', 'mailto'] }),
                TextAlign.configure({ types: ['heading', 'paragraph'] }),
                QuotationPricing,
                QuotationImage,
                PageBreak,
                DocumentSpacer,
            ],
            editorProps: {
                attributes: {
                    class: element.dataset.freeForm === 'true'
                        ? 'min-h-[36rem] rounded-md border border-neutral-300 bg-white px-6 py-5 text-sm leading-6 text-neutral-900 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-50'
                        : 'min-h-28 rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm leading-6 text-neutral-900 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-50',
                    dir: element.dataset.direction || 'auto',
                },
            },
            onUpdate: ({ editor: current }) => {
                const component = livewireFor(element)
                const path = element.dataset.model
                if (component && path) {
                    component.set(path, current.getJSON(), false)
                    if (element.dataset.autosave !== 'false') {
                        window.clearTimeout(saveTimers.get(element))
                        saveTimers.set(element, window.setTimeout(() => component.call('autosave'), 1000))
                    }
                }
            },
        })

        const toolbar = element.previousElementSibling
        toolbar?.querySelectorAll('[data-editor-action]').forEach((button) => {
            button.addEventListener('click', () => {
                const action = button.dataset.editorAction
                const chain = editor.chain().focus()
                if (action === 'bold') chain.toggleBold().run()
                if (action === 'italic') chain.toggleItalic().run()
                if (action === 'underline') chain.toggleUnderline().run()
                if (action === 'bulletList') chain.toggleBulletList().run()
                if (action === 'orderedList') chain.toggleOrderedList().run()
                if (action === 'heading2') chain.toggleHeading({ level: 2 }).run()
                if (action === 'alignLeft') chain.setTextAlign('left').run()
                if (action === 'alignCenter') chain.setTextAlign('center').run()
                if (action === 'alignRight') chain.setTextAlign('right').run()
                if (action === 'pricing') {
                    const { state, view } = editor
                    let existingPosition = null
                    let existingSize = 0
                    state.doc.descendants((node, position) => {
                        if (node.type.name === 'quotationPricing') {
                            existingPosition = position
                            existingSize = node.nodeSize
                            return false
                        }
                        return true
                    })

                    const transaction = state.tr
                    if (existingPosition !== null) {
                        transaction.delete(existingPosition, existingPosition + existingSize)
                    }
                    transaction.replaceSelectionWith(state.schema.nodes.quotationPricing.create())
                    view.dispatch(transaction.scrollIntoView())
                    view.focus()
                }
                if (action === 'removePricing') {
                    const { state, view } = editor
                    const transaction = state.tr
                    const ranges = []
                    state.doc.descendants((node, position) => {
                        if (node.type.name === 'quotationPricing') ranges.push([position, position + node.nodeSize])
                    })
                    ranges.reverse().forEach(([from, to]) => transaction.delete(from, to))
                    if (transaction.docChanged) view.dispatch(transaction.scrollIntoView())
                    view.focus()
                }
                if (action === 'image') {
                    const picker = toolbar.querySelector('[data-editor-image-picker]')
                    const assetId = Number.parseInt(picker?.value || '', 10)
                    if (assetId > 0) {
                        const alt = picker.options[picker.selectedIndex]?.text || ''
                        chain.insertContent({ type: 'quotationImage', attrs: { assetId, alt, alignment: 'center' } }).run()
                    }
                }
                if (action === 'pageBreak') chain.insertContent({ type: 'pageBreak' }).run()
                if (action === 'spacer') {
                    const requested = Number.parseInt(window.prompt('Spacer height in millimetres', '10') || '10', 10)
                    const heightMm = Math.max(2, Math.min(100, Number.isFinite(requested) ? requested : 10))
                    chain.insertContent({ type: 'documentSpacer', attrs: { heightMm } }).run()
                }
                if (action === 'link') {
                    const href = window.prompt('Link URL (http, https or mailto)')
                    if (href) chain.extendMarkRange('link').setLink({ href }).run()
                }
            })
        })

        editors.set(element, editor)
    })
}

function initSortables(root = document) {
    root.querySelectorAll('[data-quotation-block-list]').forEach((element) => {
        if (sortables.has(element)) return
        const sortable = Sortable.create(element, {
            animation: 150,
            handle: '[data-drag-handle]',
            draggable: '[data-block-id]',
            onEnd: () => {
                const ids = Array.from(element.querySelectorAll(':scope > [data-block-id]'))
                    .map((node) => node.dataset.blockId)
                livewireFor(element)?.call('reorderBlocks', ids)
            },
        })
        sortables.set(element, sortable)
    })
}

function initialize(root = document) {
    initEditors(root)
    initSortables(root)
}

document.addEventListener('DOMContentLoaded', () => initialize())
document.addEventListener('livewire:navigated', () => initialize())
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('morph.updated', ({ el }) => queueMicrotask(() => initialize(el)))
})
