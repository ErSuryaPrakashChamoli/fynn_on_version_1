---
paths:
  - 'python/ocr/**'
---

# Ocr

## Table columns come from page-level gutters, never per-row even splits
Since 2026-09-18 extraction.extract_table assigns boxes to template fields using column boundaries detected across ALL rows of a page (_detect_column_boundaries: x-ranges that ≤10% of rows cover are gutters; the widest field_count-1 win). Splitting each row's own x-span into equal slots put "haryana" in the mobile field (digit-only normalisation then blanked it) and the mobile in loan_amount on the Axis enquiry PDF, because the name column is far wider than the rest. Do not reintroduce a per-row split — it is only the fallback when a page exposes no gutters.

After bucketing, _reconcile_fields pulls date/mobile/amount/pan/email values out of any column by pattern (nearest first) and moves stray words left in a typed column to the nearest empty text field, so merged OCR boxes and off-by-one columns self-correct. number/decimal template types are the "amount" kind and are normalised to plain digits ("15,00,000" -> "1500000") because AiCustomerRecordForm validates them with ->numeric(). Covered by python/ocr/tests/test_core.py (UnequalColumnTableTests); run: python/ocr/.venv/bin/python3 -m unittest discover -s python/ocr/tests -p "test_*.py".
