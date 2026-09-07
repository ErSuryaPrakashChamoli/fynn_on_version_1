---
paths:
  - 'resources/views/**/daily-commitment/**'
---

# Daily Commitment

## Daily Commitment amounts: full Indian grouping plus the word statement
Every rupee figure in this module shows indianAmount() (₹12,50,000) with indianAmountInWords() ("Twelve Lakh Fifty Thousand") underneath. shortIndianAmount() ("₹12.5 L") was deliberately removed from the module — do not reintroduce it here (it is still used elsewhere in the app).

Use the components rather than calling the helpers inline: <x-daily-commitment.amount :value="..." :count="$countMode" /> in tables, and <x-daily-commitment.kpi :amount="..." :count="..." /> on cards (`:value` is only for non-money figures). `count` mode is for OTP headcounts: plain number_format, no symbol and no words.

Filament amount inputs in the module carry ->live(onBlur: true) with a ->helperText() echoing the same two forms, so a stray zero is caught before a locked commitment is saved.
