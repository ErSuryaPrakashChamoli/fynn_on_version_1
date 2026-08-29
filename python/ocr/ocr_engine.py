#!/usr/bin/env python3
"""Fynn OCR engine — invoked once per document by App\\Services\\Ocr\\PythonOcrService.

Usage:
    python3 ocr_engine.py <document_path> --config <config_json_path>

Contract: stdout carries exactly one JSON object and nothing else. All
diagnostic/debug output goes to stderr, and never includes recognized
field values (PAN/mobile/etc.) or full OCR text, since documents are
customer KYC/financial data.

Run as one process per document (not a long-running service): OCR here is
CPU-bound and already dispatched from a Laravel queue job, so a persistent
service would add operational surface (health checks, restarts, a second
thing that can go down) without a throughput win at current volume. The
PaddleOCR model load cost (the main reason a persistent service is
sometimes preferred) is paid once per document already, not once per page,
since this script loops over every page of a document within one process
(see ocr_backend._get_engine caching). Revisit only if per-document
end-to-end latency becomes the bottleneck at higher volume.
"""
from __future__ import annotations

import argparse
import json
import mimetypes
import os
import sys
import time
import traceback
from typing import Any, Optional

import cv2
import numpy as np

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from ocr.extraction import extract_fields, extract_table
from ocr.ocr_backend import OcrLine, mean_confidence, run_ocr
from ocr.pdf_utils import extract_text_layer_words, open_pdf, page_count, render_pages
from ocr.preprocessing import preprocess

DEFAULT_PDF_DPI = 200
DEFAULT_MAX_PAGES = 300
IMAGE_EXTENSIONS = {".jpg", ".jpeg", ".png", ".tif", ".tiff", ".bmp"}


class OcrEngineError(Exception):
    def __init__(self, message: str, error_type: str = "ocr_failed"):
        super().__init__(message)
        self.error_type = error_type


def log(message: str) -> None:
    print(f"[ocr_engine] {message}", file=sys.stderr, flush=True)


def load_config(config_path: str) -> dict[str, Any]:
    try:
        with open(config_path, "r", encoding="utf-8") as handle:
            return json.load(handle)
    except (OSError, json.JSONDecodeError) as exc:
        raise OcrEngineError(f"Unable to read config file: {exc}", "invalid_config") from exc


def words_to_lines(words: list[tuple[str, float, float, float, float]]) -> list[OcrLine]:
    return [
        OcrLine(text=text, confidence=1.0, left=x0, top=y0, right=x1, bottom=y1)
        for text, x0, y0, x1, y1 in words
        if text.strip()
    ]


def detect_kind(document_path: str) -> str:
    extension = os.path.splitext(document_path)[1].lower()

    if extension == ".pdf":
        return "pdf"

    if extension in IMAGE_EXTENSIONS:
        return "image"

    mime, _ = mimetypes.guess_type(document_path)
    if mime == "application/pdf":
        return "pdf"
    if mime and mime.startswith("image/"):
        return "image"

    raise OcrEngineError(f"Unsupported file type: {extension or mime}", "unsupported_type")


def process_image_file(document_path: str) -> tuple[list[list[OcrLine]], dict[str, Any], list[dict[str, Any]]]:
    image = cv2.imread(document_path)

    if image is None:
        raise OcrEngineError("Unable to read image file (corrupt or unsupported format).", "invalid_image")

    processed = preprocess(image)
    lines = run_ocr(processed)

    page_meta = {
        "page": 1,
        "width": int(image.shape[1]),
        "height": int(image.shape[0]),
        "line_count": len(lines),
        "mean_confidence": mean_confidence(lines),
    }

    return [lines], {"page_count": 1}, [page_meta]


def process_pdf_file(
    document_path: str, dpi: int, max_pages: int
) -> tuple[list[list[OcrLine]], dict[str, Any], list[dict[str, Any]]]:
    try:
        document = open_pdf(document_path)
    except Exception as exc:  # PyMuPDF raises plain Exception/RuntimeError on corrupt files.
        raise OcrEngineError(f"Unable to open PDF (corrupt or password-protected): {exc}", "invalid_pdf") from exc

    try:
        total_pages = page_count(document)

        if total_pages == 0:
            raise OcrEngineError("PDF has no pages.", "invalid_pdf")

        if total_pages > max_pages:
            log(f"PDF has {total_pages} pages; processing only the first {max_pages}.")

        text_layer_words = extract_text_layer_words(document, max_pages)

        if text_layer_words is not None:
            log("Using embedded PDF text layer (no OCR needed).")
            pages_lines = [words_to_lines(words) for words in text_layer_words]
            page_meta = [
                {
                    "page": index + 1,
                    "line_count": len(lines),
                    "mean_confidence": 1.0,
                    "source": "text_layer",
                }
                for index, lines in enumerate(pages_lines)
            ]
            return pages_lines, {"page_count": total_pages}, page_meta

        log(f"No reliable text layer; rasterizing and OCR'ing {min(total_pages, max_pages)} page(s).")

        pages_lines = []
        page_meta = []

        for index, image in enumerate(render_pages(document, dpi, max_pages)):
            processed = preprocess(image)
            lines = run_ocr(processed)
            pages_lines.append(lines)
            page_meta.append({
                "page": index + 1,
                "width": int(image.shape[1]),
                "height": int(image.shape[0]),
                "line_count": len(lines),
                "mean_confidence": mean_confidence(lines),
                "source": "ocr",
            })
            del image, processed

        return pages_lines, {"page_count": total_pages}, page_meta
    finally:
        document.close()


def build_result(
    pages_lines: list[list[OcrLine]],
    document_meta: dict[str, Any],
    page_meta: list[dict[str, Any]],
    mode: str,
    schema_fields: list[dict[str, Any]],
    document_type: Optional[str],
    engine_label: str,
    started_at: float,
) -> dict[str, Any]:
    all_lines = [line for page in pages_lines for line in page]
    text = "\n".join(" ".join(line.text for line in page) for page in pages_lines)
    confidence = mean_confidence(all_lines)

    result: dict[str, Any] = {
        "success": True,
        "document": {
            "page_count": document_meta.get("page_count", len(pages_lines)),
            "document_type": document_type,
        },
        "text": text,
        "confidence": confidence,
        "pages": page_meta,
        "processing": {
            "engine": engine_label,
            "processing_time_ms": int((time.perf_counter() - started_at) * 1000),
        },
    }

    if mode == "table" and schema_fields:
        table = extract_table(pages_lines, schema_fields)
        result["headers"] = table["headers"]
        result["rows"] = table["rows"]
    else:
        result["fields"] = extract_fields(all_lines)

    return result


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("document_path")
    parser.add_argument("--config", required=True)
    args = parser.parse_args()

    started_at = time.perf_counter()

    try:
        if not os.path.isfile(args.document_path):
            raise OcrEngineError(f"Document not found: {args.document_path}", "file_not_found")

        config = load_config(args.config)
        mode = config.get("mode", "text")
        schema_fields = config.get("schema_fields") or []
        document_type = config.get("document_type")
        dpi = int(config.get("pdf_dpi", DEFAULT_PDF_DPI))
        max_pages = int(config.get("max_pages", DEFAULT_MAX_PAGES))

        kind = detect_kind(args.document_path)

        if kind == "pdf":
            pages_lines, document_meta, page_meta = process_pdf_file(args.document_path, dpi, max_pages)
            engine_label = "PyMuPDF+PaddleOCR"
        else:
            pages_lines, document_meta, page_meta = process_image_file(args.document_path)
            engine_label = "PaddleOCR"

        result = build_result(
            pages_lines, document_meta, page_meta, mode, schema_fields,
            document_type, engine_label, started_at,
        )

        print(json.dumps(result))
        return 0

    except OcrEngineError as exc:
        log(f"OCR engine error ({exc.error_type}): {exc}")
        print(json.dumps({
            "success": False,
            "error": str(exc),
            "error_type": exc.error_type,
            "processing": {"processing_time_ms": int((time.perf_counter() - started_at) * 1000)},
        }))
        return 1

    except ImportError as exc:
        log(f"Missing Python dependency: {exc}")
        log(traceback.format_exc())
        print(json.dumps({
            "success": False,
            "error": f"Missing Python dependency: {exc}",
            "error_type": "missing_dependency",
            "processing": {"processing_time_ms": int((time.perf_counter() - started_at) * 1000)},
        }))
        return 1

    except Exception as exc:  # noqa: BLE001 - last-resort guard so stdout always carries valid JSON.
        log(f"Unexpected error: {exc}")
        log(traceback.format_exc())
        print(json.dumps({
            "success": False,
            "error": "Unexpected OCR engine error. See server logs for details.",
            "error_type": "unexpected_error",
            "processing": {"processing_time_ms": int((time.perf_counter() - started_at) * 1000)},
        }))
        return 1


if __name__ == "__main__":
    sys.exit(main())
