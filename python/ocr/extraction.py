"""Layout-aware field and table extraction from OCR line results.

Two extraction modes, matching the two call sites in the existing Laravel
pipeline (App\\Services\\Ocr\\OcrDocumentProcessor):

- `extract_fields`: single-document label/value extraction (PAN card, single
  KYC form) for a small, universal set of formats Python can validate by
  pattern (name/mobile/pan/email/salary). Business-specific fields (loan
  amount, application no, bank name, ...) are intentionally left to
  Laravel's existing OcrFieldExtractionService/AiDocumentMappingService,
  which already know the Data Template / Customer schema.
- `extract_table`: schema-driven, repeated-row documents (e.g. an enquiry
  list of Date | Name | Mobile[, Extra]), using OCR box coordinates to
  reconstruct rows and assign columns in template field order.
"""
from __future__ import annotations

import math
import re
from typing import Any, Optional

from . import validators
from .ocr_types import OcrLine

# Universal, format-detectable fields only — see module docstring.
LABEL_KEYWORDS: dict[str, list[str]] = {
    "customer_name": ["name", "customer name", "applicant name", "full name"],
    "mobile_no": ["mobile", "mobile no", "mobile number", "phone", "phone number", "contact no", "contact number"],
    "pan_number": ["pan", "pan no", "pan number"],
    "email": ["email", "email id", "e mail"],
    "salary": ["salary", "monthly salary", "net salary"],
}


def _normalize_label(text: str) -> str:
    return re.sub(r"\s+", " ", text.strip().lower().strip(":-"))


def _split_inline(text: str) -> Optional[tuple[str, str]]:
    match = re.match(r"^(.{2,40}?)\s*[:\-]\s*(.+)$", text.strip())
    if match:
        return match.group(1), match.group(2)
    return None


def _same_row(a: OcrLine, b: OcrLine, tolerance: float) -> bool:
    return abs(a.center_y - b.center_y) <= tolerance


def _validate_and_clean(key: str, value: str) -> Optional[str]:
    value = value.strip(" \t\n\r:;-|,")
    if value == "":
        return None

    if key == "pan_number":
        candidate = validators.find_pan(value) or value.upper()
        return candidate if validators.is_valid_pan(candidate) else None

    if key == "mobile_no":
        return validators.find_mobile(value)

    if key == "email":
        return validators.find_email(value) or value

    if key == "salary":
        return validators.normalize_amount(value)

    return value


def extract_fields(lines: list[OcrLine]) -> dict[str, dict[str, Any]]:
    fields: dict[str, dict[str, Any]] = {}

    lines_by_row = sorted(lines, key=lambda l: (l.center_y, l.left))
    row_tolerance = _median_line_height(lines) * 0.6 or 12.0

    for index, line in enumerate(lines_by_row):
        inline = _split_inline(line.text)
        label_text = _normalize_label(inline[0]) if inline else _normalize_label(line.text)

        key = next((k for k, labels in LABEL_KEYWORDS.items() if label_text in labels), None)
        if key is None or key in fields:
            continue

        if inline is not None:
            cleaned = _validate_and_clean(key, inline[1])
            if cleaned:
                fields[key] = {"value": cleaned, "confidence": round(line.confidence, 4), "source": "inline"}
            continue

        neighbor = _nearest_value_neighbor(line, lines_by_row, index, row_tolerance)
        if neighbor is not None:
            cleaned = _validate_and_clean(key, neighbor.text)
            if cleaned:
                fields[key] = {
                    "value": cleaned,
                    "confidence": round(min(line.confidence, neighbor.confidence) * 0.95, 4),
                    "source": "layout",
                }

    full_text = "\n".join(line.text for line in lines)

    if "pan_number" not in fields:
        pan = validators.find_pan(full_text)
        if pan:
            fields["pan_number"] = {"value": pan, "confidence": 0.9, "source": "pattern"}

    if "mobile_no" not in fields:
        mobile = validators.find_mobile(full_text)
        if mobile:
            fields["mobile_no"] = {"value": mobile, "confidence": 0.85, "source": "pattern"}

    if "email" not in fields:
        email = validators.find_email(full_text)
        if email:
            fields["email"] = {"value": email, "confidence": 0.85, "source": "pattern"}

    return fields


def _nearest_value_neighbor(
    label: OcrLine,
    lines: list[OcrLine],
    label_index: int,
    row_tolerance: float,
) -> Optional[OcrLine]:
    best: Optional[OcrLine] = None
    best_distance = float("inf")

    for other in lines:
        if other is label:
            continue

        same_row = _same_row(label, other, row_tolerance) and other.left > label.right
        below = other.top > label.bottom and abs(other.left - label.left) <= (label.right - label.left) + 40

        if not (same_row or below):
            continue

        distance = (
            abs(other.center_y - label.center_y) + abs(other.left - label.right)
            if same_row
            else (other.top - label.bottom) * 2
        )

        if distance < best_distance:
            best_distance = distance
            best = other

    return best


def _median_line_height(lines: list[OcrLine]) -> float:
    heights = sorted(l.bottom - l.top for l in lines)
    if not heights:
        return 0.0
    mid = len(heights) // 2
    return heights[mid] if len(heights) % 2 else (heights[mid - 1] + heights[mid]) / 2


TYPED_KINDS = ("date", "mobile", "amount", "pan", "email")

# Indian-style (12,34,567) and western (1,234,567) grouping, plain decimals,
# and plain integers — tried in that order so a grouped amount is taken whole
# instead of as its first digit group.
AMOUNT_RE = re.compile(
    r"(?<![\d,.])(?:\d{1,3}(?:,\d{2,3})+(?:\.\d+)?|\d+\.\d+|\d+)(?![\d,.])"
)

# Share of a page's rows that may still cover an x-range for it to count as a
# column gutter — lets a title line or a stray note span columns without
# hiding the real boundaries between them.
GUTTER_MAX_COVERAGE_RATIO = 0.1

# Minimum gutter width as a fraction of the median box height (≈ font size):
# a word space is ~0.25em, a table-cell gutter is wider.
GUTTER_MIN_WIDTH_RATIO = 0.3


def _field_kind(field: dict[str, Any]) -> str:
    key = str(field.get("key", "")).lower()
    label = str(field.get("label", "")).lower()
    field_type = str(field.get("type", "")).lower()

    if field_type == "date" or "date" in key or "created" in key or "date" in label:
        return "date"

    if field_type in ("mobile", "phone") or "mobile" in key or "contact" in key or "phone" in key:
        return "mobile"

    if field_type == "pan" or key == "pan" or "pan_" in key or key.endswith("_pan"):
        return "pan"

    if field_type == "email" or "email" in key:
        return "email"

    if field_type in ("number", "decimal", "integer", "amount", "currency") or any(
        token in key for token in ("amount", "salary", "income", "emi")
    ):
        return "amount"

    return "text"


def extract_table(pages_lines: list[list[OcrLine]], schema_fields: list[dict[str, Any]]) -> dict[str, Any]:
    field_kinds = [_field_kind(field) for field in schema_fields]
    field_keys = [str(field.get("key", "")) for field in schema_fields]
    headers = [str(field.get("label") or field.get("key") or "") for field in schema_fields]
    field_count = len(schema_fields)

    all_rows: list[dict[str, Any]] = []
    raw_text_parts: list[str] = []

    for lines in pages_lines:
        if not lines:
            continue

        raw_text_parts.append(" ".join(l.text for l in lines))
        row_clusters = _cluster_rows(lines)
        boundaries = _detect_column_boundaries(row_clusters, field_count)

        for cluster in row_clusters:
            cluster.sort(key=lambda l: l.left)
            row = _assign_row_to_fields(cluster, field_keys, field_kinds, headers, boundaries)

            if row is not None:
                all_rows.append(row)

    return {"headers": headers, "rows": all_rows, "raw_text": "\n".join(raw_text_parts)}


def _cluster_rows(lines: list[OcrLine]) -> list[list[OcrLine]]:
    ordered = sorted(lines, key=lambda l: l.center_y)
    tolerance = max(10.0, _median_line_height(lines) * 0.7)

    clusters: list[list[OcrLine]] = []
    for line in ordered:
        if clusters and abs(line.center_y - clusters[-1][-1].center_y) <= tolerance:
            clusters[-1].append(line)
        else:
            clusters.append([line])

    return clusters


def _detect_column_boundaries(clusters: list[list[OcrLine]], field_count: int) -> Optional[list[float]]:
    """Finds the x positions that separate the page's columns.

    Columns in a real table are rarely equal-width (a name column is much
    wider than a mobile column), so splitting each row's span evenly puts
    values in the wrong field. Instead, look across every row on the page:
    the x-ranges that (almost) no row's boxes cover are the gutters between
    columns, while the small gaps between words inside one cell get covered
    by other rows' words. The widest ``field_count - 1`` gutters are the
    column boundaries. Returns None when the page does not expose enough
    gutters (single merged OCR line per row, too few rows, ...), in which
    case the caller falls back to the per-row heuristics.
    """
    if field_count < 2 or not clusters:
        return None

    boxes = [box for cluster in clusters for box in cluster]
    min_x = int(math.floor(min(box.left for box in boxes)))
    max_x = int(math.ceil(max(box.right for box in boxes)))
    width = max_x - min_x

    if width <= 0:
        return None

    coverage = [0] * (width + 1)

    for cluster in clusters:
        covered: set[int] = set()
        for box in cluster:
            start = max(0, int(math.floor(box.left)) - min_x)
            end = min(width, int(math.ceil(box.right)) - min_x)
            covered.update(range(start, end + 1))
        for index in covered:
            coverage[index] += 1

    allowed = int(len(clusters) * GUTTER_MAX_COVERAGE_RATIO)
    min_gutter = max(1.0, _median_line_height(boxes) * GUTTER_MIN_WIDTH_RATIO)

    gutters: list[tuple[int, int, int]] = []
    run_start: Optional[int] = None

    for index, count in enumerate(coverage):
        if count <= allowed:
            if run_start is None:
                run_start = index
            continue

        if run_start is not None:
            # A run touching the left edge is margin, not a gutter.
            if run_start > 0:
                gutter_width = index - run_start
                if gutter_width >= min_gutter:
                    gutters.append((gutter_width, run_start, index - 1))
            run_start = None

    if len(gutters) < field_count - 1:
        return None

    chosen = sorted(gutters, reverse=True)[: field_count - 1]

    return sorted(min_x + (start + end + 1) / 2 for _, start, end in chosen)


def _looks_like_header(cluster_text: str, headers: list[str]) -> bool:
    normalized = _normalize_label(cluster_text)
    matches = sum(1 for header in headers if _normalize_label(header) in normalized)
    return matches >= max(1, len(headers) - 1)


def _assign_row_to_fields(
    cluster: list[OcrLine],
    field_keys: list[str],
    field_kinds: list[str],
    headers: list[str],
    boundaries: Optional[list[float]] = None,
) -> Optional[dict[str, Any]]:
    field_count = len(field_keys)
    cluster_text = " | ".join(l.text for l in cluster)

    if _looks_like_header(cluster_text, headers):
        return None

    buckets: list[list[OcrLine]] = [[] for _ in range(field_count)]

    if boundaries is not None:
        # Page-level column gutters: a box belongs to the column its centre
        # falls in, whatever this particular row's span happens to be.
        for box in cluster:
            slot = sum(1 for boundary in boundaries if box.center_x > boundary)
            buckets[min(field_count - 1, slot)].append(box)
    elif len(cluster) >= field_count:
        # No detectable gutters (e.g. a single row): bucket boxes into the
        # nearest field slot by an even x-position split of this row.
        min_x = cluster[0].left
        max_x = cluster[-1].right
        span = max(1.0, max_x - min_x)

        for box in cluster:
            fraction = (box.center_x - min_x) / span
            slot = min(field_count - 1, int(fraction * field_count))
            buckets[slot].append(box)
    else:
        # Fewer boxes than fields: values are merged onto shared OCR lines
        # (common on dense/noisy scans). Boxes carrying a format-detectable
        # value (date/mobile/amount/...) pool into the first typed slot and
        # the reconciliation below pulls each value into its own field.
        # Plain-text boxes map one-to-one onto the text fields, in order,
        # when the counts line up; otherwise they pool into the first text
        # field.
        text_slots = [i for i, kind in enumerate(field_kinds) if kind not in TYPED_KINDS]
        typed_slot = next((i for i, kind in enumerate(field_kinds) if kind in TYPED_KINDS), 0)
        text_boxes = [box for box in cluster if not _looks_typed(box.text)]
        typed_boxes = [box for box in cluster if _looks_typed(box.text)]

        buckets[typed_slot].extend(typed_boxes)

        if text_slots and len(text_boxes) == len(text_slots):
            for slot, box in zip(text_slots, text_boxes):
                buckets[slot].append(box)
        else:
            buckets[text_slots[0] if text_slots else 0].extend(text_boxes)

    bucket_texts = [" ".join(b.text for b in bucket).strip() for bucket in buckets]
    data = _reconcile_fields(bucket_texts, field_keys, field_kinds)

    if all(value is None for value in data.values()):
        return None

    confidences = [box.confidence for box in cluster]
    present = sum(1 for value in data.values() if value)
    confidence = round(sum(confidences) / len(confidences), 4) if confidences else None
    row_confidence = round((present / field_count) * (confidence or 1.0), 4) if field_count else confidence

    return {"data": data, "confidence": row_confidence, "source_row": cluster_text}


def _reconcile_fields(
    bucket_texts: list[str],
    field_keys: list[str],
    field_kinds: list[str],
) -> dict[str, Optional[str]]:
    """Turns per-column text into field values, correcting for boxes that
    merged across columns or landed one column off.

    1. Each typed field (date/mobile/amount/pan/email) takes a matching
       value from its own column's text.
    2. A typed field still empty searches the other columns, nearest
       first — this is how a mobile and amount that OCR glued into one box
       both end up in their own fields.
    3. Text fields take whatever text is left in their column.
    4. Non-matching text left in a typed column (a state name that fell into
       the mobile column, say) moves to the nearest empty text field rather
       than being discarded.
    """
    count = len(field_keys)
    texts = list(bucket_texts)
    data: dict[str, Optional[str]] = {key: None for key in field_keys}

    for index, kind in enumerate(field_kinds):
        if kind in TYPED_KINDS and texts[index]:
            value, texts[index] = _take_typed_value(texts[index], kind, own_column=True)
            data[field_keys[index]] = value

    for index, kind in enumerate(field_kinds):
        if kind not in TYPED_KINDS or data[field_keys[index]] is not None:
            continue

        for other in sorted(range(count), key=lambda j: (abs(j - index), j)):
            if other == index or not texts[other]:
                continue

            value, remaining = _take_typed_value(texts[other], kind, own_column=False)
            if value is not None:
                data[field_keys[index]] = value
                texts[other] = remaining
                break

    for index, kind in enumerate(field_kinds):
        if kind not in TYPED_KINDS:
            data[field_keys[index]] = _clean_leftover_text(texts[index])
            texts[index] = ""

    for index, kind in enumerate(field_kinds):
        leftover = _clean_leftover_text(texts[index])
        if not leftover:
            continue

        target = next(
            (
                j
                for j in sorted(range(count), key=lambda j: (abs(j - index), j))
                if field_kinds[j] not in TYPED_KINDS and not data[field_keys[j]]
            ),
            None,
        )

        if target is not None:
            data[field_keys[target]] = leftover
            texts[index] = ""

    return data


def _looks_typed(text: str) -> bool:
    """Whether a box carries something a typed field could claim (a number,
    a date, an email, a PAN) rather than only words."""
    return (
        sum(1 for char in text if char.isdigit()) >= 4
        or "@" in text
        or validators.find_pan(text) is not None
    )


def _take_typed_value(text: str, kind: str, own_column: bool) -> tuple[Optional[str], str]:
    """Extracts one value of ``kind`` from ``text``; returns (value, leftover)."""
    if kind == "date":
        match = validators.DATE_RE.search(text)
        if match is None:
            return None, text
        value = validators.normalize_date(match.group(0)) or match.group(0)
        return value, _remove_span(text, match.span())

    if kind == "mobile":
        value = validators.find_mobile(text)
        if value is not None:
            return value, _remove_first(text, value)

        if own_column:
            # OCR noise inside an otherwise all-digit cell ("89202B4019"):
            # keep the digits rather than lose the whole number, but only
            # when the cell really is a number and not a word from the
            # neighbouring column.
            digits = re.sub(r"\D+", "", text)
            letters = re.sub(r"[^A-Za-z]+", "", text)
            if len(digits) >= 8 and len(letters) <= 2:
                return digits, ""

        return None, text

    if kind == "amount":
        for match in AMOUNT_RE.finditer(text):
            candidate = match.group(0)
            # A bare 10-digit run starting 6-9 is a mobile number, not money.
            if validators.MOBILE_RE.fullmatch(candidate):
                continue
            value = validators.normalize_amount(candidate)
            if value is not None:
                return value, _remove_span(text, match.span())
        return None, text

    if kind == "pan":
        value = validators.find_pan(text)
        return (value, _remove_first(text, value, ignore_case=True)) if value else (None, text)

    if kind == "email":
        value = validators.find_email(text)
        return (value, _remove_first(text, value)) if value else (None, text)

    return None, text


def _remove_span(text: str, span: tuple[int, int]) -> str:
    return text[: span[0]] + " " + text[span[1]:]


def _remove_first(text: str, value: str, ignore_case: bool = False) -> str:
    pattern = re.compile(re.escape(value), re.IGNORECASE if ignore_case else 0)
    replaced, count = pattern.subn(" ", text, count=1)
    if count:
        return replaced

    # find_mobile() matches across separators ("98765 43210"), so the value
    # may not appear verbatim — drop those digit groups instead.
    digit_groups = re.findall(r"\d+", text)
    if "".join(digit_groups).find(value) >= 0:
        return re.sub(r"[\d\s\-()]+", " ", text, count=1)

    return text


def _clean_leftover_text(text: str) -> Optional[str]:
    cleaned = re.sub(r"[|]+", " ", text)
    cleaned = re.sub(r"\s+", " ", cleaned).strip(" |:-,;")
    return cleaned or None
