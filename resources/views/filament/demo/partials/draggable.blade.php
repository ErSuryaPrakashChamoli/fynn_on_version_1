{{--
    Alpine attributes that make a floating demo switcher draggable anywhere
    on screen (pointer events, so mouse and touch). Include inside the
    position: fixed element's opening tag:

        <div class="..." @include('filament.demo.partials.draggable', ['storageKey' => '...'])>

    The dropped spot is kept in localStorage under $storageKey and clamped
    to the viewport. Filament's dropdown trigger opens on mousedown, before
    a drag can be told apart from a click, so that mousedown is held back
    and replayed on release only when the pointer didn't move (5px or
    more counts as a drag). The element gets `is-dragging` while moving.
--}}
    x-data="{
        storageKey: @js($storageKey),
        dragging: false,
        moved: false,
        startX: 0,
        startY: 0,
        offsetX: 0,
        offsetY: 0,
        heldTrigger: null,
        replayingPress: false,
        init() {
            let saved = null;

            try {
                saved = JSON.parse(localStorage.getItem(this.storageKey));
            } catch (e) {}

            if (saved && Number.isFinite(saved.left) && Number.isFinite(saved.top)) {
                this.$nextTick(() => this.place(saved.left, saved.top));
            }

            window.addEventListener('resize', () => {
                if (this.$el.style.left) {
                    this.place(parseFloat(this.$el.style.left), parseFloat(this.$el.style.top));
                }
            });
        },
        place(left, top) {
            const maxLeft = window.innerWidth - this.$el.offsetWidth - 8;
            const maxTop = window.innerHeight - this.$el.offsetHeight - 8;

            this.$el.style.left = Math.max(8, Math.min(left, maxLeft)) + 'px';
            this.$el.style.top = Math.max(8, Math.min(top, maxTop)) + 'px';
            this.$el.style.right = 'auto';
            this.$el.style.bottom = 'auto';
        },
        start(event) {
            if (event.button !== 0) {
                return;
            }

            const rect = this.$el.getBoundingClientRect();

            this.dragging = true;
            this.moved = false;
            this.startX = event.clientX;
            this.startY = event.clientY;
            this.offsetX = event.clientX - rect.left;
            this.offsetY = event.clientY - rect.top;
        },
        move(event) {
            if (! this.dragging) {
                return;
            }

            if (! this.moved && Math.hypot(event.clientX - this.startX, event.clientY - this.startY) < 5) {
                return;
            }

            this.moved = true;
            this.place(event.clientX - this.offsetX, event.clientY - this.offsetY);
        },
        holdPressUntilRelease(event) {
            {{-- A touch tap's compatibility mousedown arrives after the pointer is already up. --}}
            if (this.replayingPress || ! this.dragging) {
                return;
            }

            const trigger = event.target.closest('.fi-dropdown-trigger');

            if (! trigger || event.button !== 0) {
                return;
            }

            event.stopPropagation();
            this.heldTrigger = trigger;
        },
        stop() {
            if (! this.dragging) {
                return;
            }

            this.dragging = false;

            const trigger = this.heldTrigger;
            this.heldTrigger = null;

            if (trigger && ! this.moved) {
                this.replayingPress = true;
                trigger.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true, button: 0 }));
                this.replayingPress = false;
            }

            if (this.moved) {
                try {
                    localStorage.setItem(this.storageKey, JSON.stringify({
                        left: parseFloat(this.$el.style.left),
                        top: parseFloat(this.$el.style.top),
                    }));
                } catch (e) {}
            }
        },
        swallowClickAfterDrag(event) {
            if (this.moved) {
                event.preventDefault();
                event.stopPropagation();
                this.moved = false;
            }
        },
    }"
    x-bind:class="{ 'is-dragging': dragging && moved }"
    x-on:pointerdown="start($event)"
    x-on:pointermove.window="move($event)"
    x-on:pointerup.window="stop()"
    x-on:pointercancel.window="stop()"
    x-on:mousedown.capture="holdPressUntilRelease($event)"
    x-on:click.capture="swallowClickAfterDrag($event)"
