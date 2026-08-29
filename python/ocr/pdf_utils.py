"""PDF handling: text-layer detection and page-by-page rasterization.

Uses PyMuPDF (fitz), which can open a PDF and touch one page at a time
without loading the whole document's rendered image set into memory —
important for the large multi-page scans this pipeline sees in practice.
"""
from __future__ import annotations

from typing import Iterator, Optional

import fitz  # PyMuPDF
import numpy as np

MIN_CHARS_PER_PAGE_FOR_TEXT_LAYER = 20


def open_pdf(path: str) -> fitz.Document:
    return fitz.open(path)


def page_count(document: fitz.Document) -> int:
    return document.page_count


def extract_text_layer(document: fitz.Document, max_pages: int) -> Optional[list[str]]:
    """Returns per-page text if the PDF has a reliable selectable text layer.

    Returns None when the PDF is image-based (scanned) and should instead
    be rasterized and OCR'd. A page is only trusted if it clears a minimum
    character count — a handful of stray embedded characters (form
    artifacts, watermarks) should not skip OCR for an otherwise scanned page.
    """
    pages_to_check = min(document.page_count, max_pages)
    texts: list[str] = []

    for index in range(pages_to_check):
        text = document.load_page(index).get_text("text") or ""
        texts.append(text)

    usable_pages = sum(1 for text in texts if len(text.strip()) >= MIN_CHARS_PER_PAGE_FOR_TEXT_LAYER)

    if pages_to_check == 0 or (usable_pages / pages_to_check) < 0.6:
        return None

    return texts


def extract_text_layer_words(
    document: fitz.Document, max_pages: int
) -> Optional[list[list[tuple[str, float, float, float, float]]]]:
    """Like extract_text_layer, but returns word-level boxes (text, x0, y0,
    x1, y1) per page instead of plain text — lets the layout-aware field
    and table extraction work directly off exact, non-OCR'd text when a
    reliable text layer exists, which is both faster and more accurate
    than rasterizing and OCR'ing a born-digital PDF.
    """
    pages_to_check = min(document.page_count, max_pages)
    pages_words: list[list[tuple[str, float, float, float, float]]] = []
    usable_pages = 0

    for index in range(pages_to_check):
        page = document.load_page(index)
        words = page.get_text("words")
        text_length = sum(len(w[4]) for w in words)

        if text_length >= MIN_CHARS_PER_PAGE_FOR_TEXT_LAYER:
            usable_pages += 1

        pages_words.append([(w[4], w[0], w[1], w[2], w[3]) for w in words])

    if pages_to_check == 0 or (usable_pages / pages_to_check) < 0.6:
        return None

    return pages_words


def render_pages(document: fitz.Document, dpi: int, max_pages: int) -> Iterator[np.ndarray]:
    """Yields one page at a time as a BGR numpy array, at the given DPI."""
    zoom = dpi / 72.0
    matrix = fitz.Matrix(zoom, zoom)
    pages_to_render = min(document.page_count, max_pages)

    for index in range(pages_to_render):
        page = document.load_page(index)
        pixmap = page.get_pixmap(matrix=matrix, colorspace=fitz.csRGB)

        image = np.frombuffer(pixmap.samples, dtype=np.uint8).reshape(
            pixmap.height, pixmap.width, pixmap.n
        )

        # RGB -> BGR for OpenCV.
        yield image[:, :, ::-1].copy()

        pixmap = None
