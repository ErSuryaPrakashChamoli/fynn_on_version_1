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

import re
from typing import Any, Optional

from . import validators
from .types import OcrLine

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


def _field_kind(field: dict[str, Any]) -> str:
    key = str(field.get("key", "")).lower()
    label = str(field.get("label", "")).lower()
    field_type = str(field.get("type", "")).lower()

    if field_type == "date" or "date" in key or "created" in key or "date" in label:
        return "date"

    if field_type in ("mobile", "phone") or "mobile" in key or "contact" in key or "phone" in key:
        return "mobile"

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

        for cluster in row_clusters:
            cluster.sort(key=lambda l: l.left)
            row = _assign_row_to_fields(cluster, field_keys, field_kinds, headers)

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


def _looks_like_header(cluster_text: str, headers: list[str]) -> bool:
    normalized = _normalize_label(cluster_text)
    matches = sum(1 for header in headers if _normalize_label(header) in normalized)
    return matches >= max(1, len(headers) - 1)


def _assign_row_to_fields(
    cluster: list[OcrLine],
    field_keys: list[str],
    field_kinds: list[str],
    headers: list[str],
) -> Optional[dict[str, Any]]:
    field_count = len(field_keys)
    cluster_text = " | ".join(l.text for l in cluster)

    if _looks_like_header(cluster_text, headers):
        return None

    data: dict[str, Optional[str]] = {key: None for key in field_keys}
    confidences: list[float] = []

    if len(cluster) >= field_count:
        # One (or more) OCR box per column: bucket extra boxes into the
        # nearest field slot by even x-position split, then read each
        # slot's concatenated text.
        buckets: list[list[OcrLine]] = [[] for _ in range(field_count)]
        min_x = cluster[0].left
        max_x = cluster[-1].right
        span = max(1.0, max_x - min_x)

        for box in cluster:
            fraction = (box.center_x - min_x) / span
            slot = min(field_count - 1, int(fraction * field_count))
            buckets[slot].append(box)

        for index, key in enumerate(field_keys):
            text = " ".join(b.text for b in buckets[index]).strip()
            confidences.extend(b.confidence for b in buckets[index])
            data[key] = _normalize_table_value(text, field_kinds[index]) if text else None
    else:
        # Fewer boxes than fields: values are merged onto shared OCR lines
        # (common on dense/noisy scans). Pull out format-detectable values
        # (date/mobile) by pattern, then assign whatever text remains to
        # the first unassigned text-type field.
        remaining = cluster_text
        confidences.extend(b.confidence for b in cluster)

        for index, kind in enumerate(field_kinds):
            key = field_keys[index]

            if kind == "date":
                value = validators.normalize_date(remaining)
                if value:
                    data[key] = value
                    remaining = re.sub(re.escape(_first_date_match(remaining) or ""), "", remaining, count=1)

            elif kind == "mobile":
                value = validators.find_mobile(remaining)
                if value:
                    data[key] = value
                    remaining = remaining.replace(value, "", 1)

        text_slots = [i for i, kind in enumerate(field_kinds) if kind == "text" and data[field_keys[i]] is None]
        leftover = _clean_leftover_text(remaining)

        if len(text_slots) == 1 and leftover:
            data[field_keys[text_slots[0]]] = leftover
        elif text_slots and leftover:
            data[field_keys[text_slots[0]]] = leftover

    if all(value is None for value in data.values()):
        return None

    present = sum(1 for value in data.values() if value)
    confidence = round(sum(confidences) / len(confidences), 4) if confidences else None
    row_confidence = round((present / field_count) * (confidence or 1.0), 4) if field_count else confidence

    return {"data": data, "confidence": row_confidence, "source_row": cluster_text}


def _first_date_match(text: str) -> Optional[str]:
    match = validators.DATE_RE.search(text)
    return match.group(0) if match else None


def _clean_leftover_text(text: str) -> Optional[str]:
    cleaned = re.sub(r"[|]+", " ", text)
    cleaned = re.sub(r"\s+", " ", cleaned).strip(" |:-")
    return cleaned or None


def _normalize_table_value(text: str, kind: str) -> Optional[str]:
    if kind == "date":
        return validators.normalize_date(text) or text.strip()
    if kind == "mobile":
        return validators.find_mobile(text) or re.sub(r"\D+", "", text) or None
    return text.strip() or None
