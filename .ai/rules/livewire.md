---
paths:
  - 'resources/views/livewire/**'
---

# Livewire

## Key every block a Livewire branch can swap, or its inputs rebind to the wrong property
Livewire morphs the DOM in place and only re-initialises Alpine on elements it has just ADDED (see the `added` hook in livewire.js; `updated` does not re-run directives). An element the morph REUSES across an @if/@else swap keeps the wire:model / wire:click binding it was first initialised with, even though the rendered attribute now says something else.

This broke the daily commitment prompt: the "Amount (₹)" and "Number of OTPs" inputs share one slot, so after picking OTP the box still wrote to `amount`. `count` stayed null, commit() failed its own `< 1` guard and "Give commitment" did nothing. Same latent bug was in monthly-target-prompt (bulk row, per-employee row, inactivity form).

Rules for these prompts:
- Give each branch of a swap its own wire:key (commit-amount / commit-count, bulk-amount / bulk-count, row-amount-{id} / row-count-{id}).
- Key repeater rows (wire:key="case-{{ $index }}") — removeCase() re-indexes, so survivors otherwise inherit the bindings of the rows above them.
- Write @selected() on <option> yourself. Alpine's x-model only corrects the select once it boots; until then the browser shows option[0], which is a different stage from the one the server holds.

Livewire::test()->html() catches all of this — assert on the wire:key and the selected option. SQLite/PHPUnit alone never will, because the property is set directly and the DOM is never involved.
