---
paths:
  - 'app/Filament/**, app/Providers/AppServiceProvider.php, app/Helpers/helpers.php'
---

# Helpers

## Every rupee input uses TextInput::indianAmount() — Indian commas in the box, words underneath, digits saved
User decision 2026-09-27: any field where an amount is typed must show Indian grouping (12,50,000.50) and the amount in words. Use ->indianAmount() (AppServiceProvider macro), NOT ->numeric(): it turns numeric off (a number input cannot hold commas), adds a "₹" prefix unless one is set (reads the raw $prefixLabel so a closure prefix is not evaluated), formats on hydrate and on blur via indianNumberFormat() (keeps paise and sign; indianCurrencyFormat() stays whole-rupee for old callers), strips commas on dehydrate, validates with a pattern rule, and adds amountInWords() (now indianAmountInWordsWithPaise). Pass min:/max: to the macro instead of minValue()/maxValue() — on a text field those check the string LENGTH. Read-only computed figures: ->indianAmount(words: false, allowNegative: true), chained after disabled(). Do not also give the field its own formatStateUsing/dehydrateStateUsing (single callbacks; the macro sets both). Rules that compare the raw $value against the DB must strip commas first (see OtherBankIncentiveSlabForm min_achievement). Never test disabled/visibility inside the macro — the form container does not exist yet. Customer journey amounts (CustomerForm $currencyField etc.) predate this and already do the same by hand. Plain number <input>s in Livewire prompts cannot show commas: show "₹{indianNumberFormat} · {words}" under them. Not amounts (leave numeric): rates, %, counts, minutes, PIN, sort order. Covered by tests/Feature/IndianAmountFieldTest.php (also counts the macro per file).
