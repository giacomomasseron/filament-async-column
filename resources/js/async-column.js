document.addEventListener('alpine:init', () => {
    // Must stay in sync with GiacomoMasseroni\AsyncColumn\Livewire\AsyncColumnHook::METHOD
    // - this string is the one place that constant is unavoidably duplicated, because
    // the PHP constant cannot be read from plain JS with no build step.
    const RESOLVE_METHOD = 'resolveAsyncColumns'

    window.Alpine.store('asyncColumn', {
        // Map<componentId, Map<token, el[]>> - grouped by Livewire component (its
        // wire:id) rather than one page-wide bag. BatchResolver::resolve() only ever
        // resolves columns against the CALLED component's own table, so a page with
        // several tables (e.g. a resource table plus a relation manager) must issue
        // one request PER TABLE. Routing every pending cell into a single component's
        // call - which a page-wide bag would do - silently strands every other
        // component's cells on their skeleton forever.
        pending: new Map(),

        // Map<componentId, Map<token, html>> - likewise keyed by component. A
        // CellToken only encodes columnName + recordKey, so two different tables using
        // the same column name against a record with the same id produce
        // byte-identical tokens. An unscoped cache would let one table's resolved HTML
        // get painted into another table's cell for a *different* underlying record -
        // a data-correctness bug, not just a missed cache hit.
        cache: new Map(),

        // Elements currently enqueued or in-flight (added to `pending` but not yet
        // applied or marked failed). Needed alongside the `asyncColumnSettled` dataset
        // flag below: this covers the in-between window where a cell has been sent to
        // the server but no response has come back yet, so there is no dataset flag
        // yet to detect the duplicate against. Without this, the Livewire morph-hook
        // safety net (see the bottom of this file) could enqueue - and request - the
        // same cell twice if it fires while x-init's own registration is still
        // in-flight.
        tracked: new WeakSet(),

        // WeakMap<el, string> - the cell's server-rendered loading markup, captured
        // before we ever overwrite it. renderFailed() replaces that markup with the
        // error text, so without a copy there is nothing to put back when the user
        // clicks retry, and the retry reads as a no-op until the response lands.
        // Whatever the developer configured via ->loadingState() is preserved, not a
        // hardcoded skeleton.
        loading: new WeakMap(),

        scheduled: false,
        observer: null,

        register(el) {
            const token = el.dataset.asyncColumnToken

            if (! token) {
                return
            }

            // Already reached a terminal state (resolved or failed) and nothing about
            // the DOM has changed since. Set by apply()/markFailed() below. Livewire's
            // own morph strips this attribute back off whenever it re-sends the
            // server's fresh (unresolved) placeholder markup for this cell - see the
            // morph-hook comment at the bottom of this file - so this flag reads as
            // "settled" only while that's still true, and clears itself exactly when
            // a re-registration is actually warranted.
            if (el.dataset.asyncColumnSettled === '1') {
                return
            }

            // Capture the loading markup while the element still holds it - before
            // the cache repaint below, and before anything else can overwrite it. On
            // a post-morph re-registration the server has just re-sent fresh
            // placeholder markup, so this correctly re-captures rather than going
            // stale.
            if (! this.loading.has(el)) {
                this.loading.set(el, el.innerHTML)
            }

            const componentId = this.componentIdFor(el)

            // Repaint instantly from the client-side cache: after a sort or a
            // paginate, rows we already resolved should not hit the server again.
            if (componentId && this.cache.get(componentId)?.has(token)) {
                el.innerHTML = this.cache.get(componentId).get(token)
                el.dataset.asyncColumnSettled = '1'

                return
            }

            // Already enqueued or observed by an earlier register() call on this
            // exact element - must not enqueue (and therefore request) it a second
            // time.
            if (this.tracked.has(el)) {
                return
            }

            if (el.dataset.asyncColumnWhenVisible === '1') {
                this.tracked.add(el)
                this.observe(el)

                return
            }

            this.enqueue(el)
        },

        observe(el) {
            if (! this.observer) {
                this.observer = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (! entry.isIntersecting) {
                            return
                        }

                        this.observer.unobserve(entry.target)
                        this.enqueue(entry.target)
                    })
                })
            }

            this.observer.observe(el)
        },

        enqueue(el) {
            const token = el.dataset.asyncColumnToken
            const componentId = this.componentIdFor(el)

            // No enclosing Livewire component root: there is nowhere to route this
            // token, so it is dropped rather than being folded into some other
            // component's batch.
            if (! componentId) {
                return
            }

            this.tracked.add(el)

            if (! this.pending.has(componentId)) {
                this.pending.set(componentId, new Map())
            }

            const cells = this.pending.get(componentId)

            if (! cells.has(token)) {
                cells.set(token, [])
            }

            cells.get(token).push(el)
            this.schedule()
        },

        // One animation frame of debounce coalesces everything pending into as many
        // requests as there are distinct Livewire components with pending cells -
        // still exactly one request per component, no matter how many rows (including
        // several rows across several tables scrolling into view together) triggered
        // it within that frame.
        schedule() {
            if (this.scheduled) {
                return
            }

            this.scheduled = true

            requestAnimationFrame(() => {
                this.scheduled = false
                this.flush()
            })
        },

        flush() {
            if (this.pending.size === 0) {
                return
            }

            const batches = this.pending
            this.pending = new Map()

            batches.forEach((cells, componentId) => {
                let component = null

                // The component may have been removed from the page (wire:navigate
                // away, a relation manager's modal closing, ...) between enqueue and
                // this flush, or window.Livewire.find() may throw on an id it no
                // longer knows about. Either way this must not throw here, and it must
                // not stop any OTHER component's batch in this same flush from being
                // sent.
                try {
                    component = window.Livewire.find(componentId)
                } catch (error) {
                    component = null
                }

                if (! component) {
                    this.markFailed(cells)

                    return
                }

                try {
                    component.call(RESOLVE_METHOD, Array.from(cells.keys()))
                        .then((result) => this.apply(componentId, cells, result))
                        .catch(() => this.markFailed(cells))
                } catch (error) {
                    this.markFailed(cells)
                }
            })
        },

        apply(componentId, batch, result) {
            const cells = (result && result.cells) || {}

            // Any token the server declined to return - max_batch_size truncation, a
            // record deleted between paint and resolve, a column toggled off
            // mid-flight, an array/data-source table whose scopedQuery() is null, ...
            // - must not be left showing its loading skeleton forever with no way to
            // retry. Everything requested but absent from the response degrades to a
            // failed (and, if retryable, clickable) cell exactly like a network error
            // would.
            const missing = new Map()

            batch.forEach((elements, token) => {
                const cell = cells[token]

                if (! cell) {
                    missing.set(token, elements)

                    return
                }

                if (! cell.error) {
                    if (! this.cache.has(componentId)) {
                        this.cache.set(componentId, new Map())
                    }

                    this.cache.get(componentId).set(token, cell.html)
                }

                elements.forEach((el) => {
                    el.innerHTML = cell.html
                    el.dataset.asyncColumnError = cell.error ? '1' : '0'
                    el.dataset.asyncColumnSettled = '1'
                    this.tracked.delete(el)

                    if (cell.error) {
                        this.appendRetryAffordance(el)
                    }
                })
            })

            if (missing.size > 0) {
                this.markFailed(missing)
            }
        },

        markFailed(batch) {
            batch.forEach((elements) => {
                elements.forEach((el) => this.renderFailed(el))
            })
        },

        // Stops the loading skeleton and makes the failure visible, instead of
        // leaving a cell shimmering forever with only the (invisible)
        // data-async-column-error="1" attribute as evidence anything went wrong.
        // Both labels are static, translated, developer-trusted strings rendered by
        // placeholder.blade.php as data attributes (never the underlying exception -
        // see BatchResolver::buildErrorCell()'s own comment on that), so they are
        // written via textContent rather than innerHTML: correct either way here,
        // but this way nothing downstream has to reason about escaping to stay safe.
        renderFailed(el) {
            el.dataset.asyncColumnError = '1'
            el.dataset.asyncColumnSettled = '1'
            this.tracked.delete(el)

            el.innerHTML = ''

            const errorLabel = el.dataset.asyncColumnErrorLabel

            if (errorLabel) {
                const message = document.createElement('span')
                message.textContent = errorLabel
                el.appendChild(message)
            }

            this.appendRetryAffordance(el)
        },

        // Shared by renderFailed() (client-side failures) and apply()'s error branch
        // (a resolver that threw server-side). Both are failures the user can retry
        // by clicking, so both need the visible cue - otherwise whether the hint
        // appears depends on where the failure happened, which is meaningless to the
        // person looking at the cell.
        appendRetryAffordance(el) {
            if (el.dataset.asyncColumnRetryable !== '1') {
                return
            }

            const label = el.dataset.asyncColumnRetryLabel

            if (! label) {
                return
            }

            el.appendChild(document.createTextNode(' '))

            const retry = document.createElement('span')
            retry.className = 'fi-async-column-retry'
            retry.textContent = label
            el.appendChild(retry)
        },

        retry(el) {
            delete el.dataset.asyncColumnError
            delete el.dataset.asyncColumnSettled

            // Put the loading markup back, so the click has visible effect straight
            // away. Without this the error text sits there unchanged until the
            // response lands and the retry reads as a dead button.
            if (this.loading.has(el)) {
                el.innerHTML = this.loading.get(el)
            }

            const token = el.dataset.asyncColumnToken
            const componentId = this.componentIdFor(el)

            if (componentId) {
                this.cache.get(componentId)?.delete(token)
            }

            this.enqueue(el)
        },

        componentIdFor(el) {
            const root = el.closest('[wire\\:id]')

            return root ? root.getAttribute('wire:id') : null
        },
    })

    // Defensive safety net for Livewire's DOM morph (a drag-sort, a $refresh, any
    // update that patches a keyed row in place rather than replacing it): Alpine's
    // morph integration only re-runs x-init on nodes the morph ADDS, never on ones it
    // merely UPDATES (verified against vendor/livewire/livewire's bundled
    // @alpinejs/morph integration - Livewire.hook('morph.updated', ...) below is the
    // exact, real hook name for livewire/livewire 4.3.4, confirmed against that
    // vendor source, not guessed). A placeholder element that survives such a morph
    // has its children patched back to the server's fresh (unresolved) skeleton
    // markup without x-init ever re-running, so without this it would sit there
    // forever - never consulting the client-side cache this file otherwise exists to
    // use for exactly this situation ("after a sort or paginate, rows we already
    // resolved should not hit the server again").
    //
    // register() call is deferred with queueMicrotask(): morph.updated fires for an
    // element BEFORE morphdom patches that same element's *children*
    // (patchAttributes -> updated() trigger -> patchChildren, in that order, per
    // @alpinejs/morph's context.patch()), so calling register() synchronously here -
    // and specifically a cache repaint via el.innerHTML - would be immediately
    // clobbered by morphdom's own patchChildren call reverting it back to the
    // server's skeleton right after. Deferring to a microtask runs this after the
    // whole (synchronous) morph has finished.
    //
    // register() itself is idempotent (see the `tracked` WeakSet and the
    // asyncColumnSettled dataset flag above), so this is safe to call even when
    // x-init did already re-run for a given element: nothing here double-enqueues or
    // fires a duplicate request.
    if (typeof window.Livewire !== 'undefined') {
        window.Livewire.hook('morph.updated', ({ el }) => {
            if (! el?.dataset?.asyncColumnToken) {
                return
            }

            queueMicrotask(() => {
                window.Alpine.store('asyncColumn')?.register(el)
            })
        })
    }
})
