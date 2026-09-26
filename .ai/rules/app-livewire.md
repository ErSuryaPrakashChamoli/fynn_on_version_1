---
paths:
  - 'app/Livewire/**'
---

# App Livewire

## Never name a Livewire method or property after a $wire alias — it is silently unreachable
Livewire's $wire proxy checks its own `aliases` map BEFORE the component's state (generateWireObject in livewire/livewire/dist/livewire.js). A public method or property with one of those names can never be reached from the browser.

Reserved: on, el, id, js, get, set, refs, call, hook, watch, dirty, effect, commit, errors, island, upload, entangle, dispatch, intercept, interceptAction, interceptMessage, interceptRequest, dispatchTo, dispatchSelf, dispatchEl, dispatchRef, removeUpload, cancelUpload, uploadMultiple.

This cost a day on DailyCommitmentPrompt: wire:click="commit" resolved to $wire.$commit (Livewire's internal flush-pending-updates), so the "Give commitment" button returned 200 with the modal re-rendered and unchanged, and the employee was stuck behind a full-screen prompt with nothing written. There is no JS error and no failed request — the only visible symptom is a button that does nothing. The method is now giveCommitment().

Livewire::test()->call('commit') calls the method directly and passes regardless, so feature tests cannot see this. tests/Unit/LivewireReservedNamesTest reflects over app/Livewire and app/Filament and fails on any clash — keep its RESERVED list in step with the Livewire version when upgrading.

## Bell must extend the panel's DatabaseNotifications; announcements block until acknowledged
CategorizedDatabaseNotifications must extend Filament\Livewire\DatabaseNotifications, not Filament\Notifications\Livewire\DatabaseNotifications: only the panel class supplies getTrigger() (the topbar bell button + unread badge). Extending the package base silently renders no bell at all. Covered by AnnouncementTest::test_topbar_bell_renders_with_the_unread_count_badge.

Announcements (Setting → Announcements, AnnouncementService::publish) go to company / roles / designations. Each recipient gets an announcement_recipients row (acknowledgement is tracked there, NOT via notifications.read_at — "Mark all as read" in the bell must not bypass it) plus a bell notification (category 'announcement', viewData.announcement_id) kept for re-reading. AnnouncementPrompt blocks the LMS for pending rows of live announcements (is_active, not expired) until acknowledged; the sender is auto-acknowledged. Delivery uses notifyNow() — Filament's sendToDatabase() queues, and nothing shows until a worker runs (demo has its own). ReminderPopup::pendingQuery() excludes this category — keep it excluded.
