"""Format validation/normalization for common Indian KYC/financial fields.

Kept deliberately small and format-only (regex/parsing), never business
rules — field-to-customer-column mapping and approval logic stay in
Laravel (App\\Services\\Ocr\\OcrFieldExtractionService / AiDocumentMappingService).
"""
from __future__ import annotations

import re
from datetime import datetime
from typing import Optional

PAN_RE = re.compile(r"\b([A-Z]{5}[0-9]{4}[A-Z])\b")
MOBILE_RE = re.compile(r"(?<!\d)([6-9]\d{9})(?!\d)")
EMAIL_RE = re.compile(r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b")

DATE_PATTERNS = [
    r"\b\d{1,2}[/\-.]\d{1,2}[/\-.]\d{2,4}\b",
    r"\b\d{4}[/\-.]\d{1,2}[/\-.]\d{1,2}\b",
    r"\b\d{1,2}\s+[A-Za-z]{3,9}\s+\d{2,4}\b",
]
DATE_RE = re.compile("|".join(f"(?:{p})" for p in DATE_PATTERNS))


def find_pan(text: str) -> Optional[str]:
    match = PAN_RE.search(text.upper())
    return match.group(1) if match else None


def is_valid_pan(value: str) -> bool:
    return bool(PAN_RE.fullmatch(value.strip().upper()))


def find_mobile(text: str) -> Optional[str]:
    digits_only = re.sub(r"\D+", " ", text)
    match = MOBILE_RE.search(digits_only)
    return match.group(1) if match else None


def find_email(text: str) -> Optional[str]:
    match = EMAIL_RE.search(text)
    return match.group(0) if match else None


def contains_date(text: str) -> bool:
    return DATE_RE.search(text) is not None


def normalize_date(text: str) -> Optional[str]:
    """Best-effort normalization to YYYY-MM-DD. Returns None if unparseable."""
    match = DATE_RE.search(text)
    if not match:
        return None

    raw = match.group(0)

    for separator in ("/", "-", "."):
        parts = raw.split(separator)
        if len(parts) == 3 and all(p.strip().isdigit() for p in parts):
            a, b, c = (p.strip() for p in parts)
            try:
                if len(a) == 4:
                    dt = datetime(int(a), int(b), int(c))
                else:
                    day, month, year = int(a), int(b), int(c)
                    if year < 100:
                        year += 2000 if year < 70 else 1900
                    dt = datetime(year, month, day)
                return dt.strftime("%Y-%m-%d")
            except ValueError:
                continue

    for fmt in ("%d %B %Y", "%d %b %Y", "%d %B %y", "%d %b %y"):
        try:
            return datetime.strptime(raw, fmt).strftime("%Y-%m-%d")
        except ValueError:
            continue

    return None


def normalize_amount(text: str) -> Optional[str]:
    """Strips currency symbols/labels and thousands separators.

    Currency prefixes like "Rs." leave a stray leading '.' after digit-only
    stripping, and a label with its own punctuation ("Rs." + a real decimal
    point) can leave more than one '.' — both are cleaned up so only a
    single, correctly-positioned decimal point survives.
    """
    cleaned = re.sub(r"[^0-9.]", "", text).lstrip(".")

    if cleaned.count(".") > 1:
        whole, _, fraction = cleaned.partition(".")
        cleaned = whole + "." + fraction.replace(".", "")

    return cleaned if cleaned not in ("", ".") else None
