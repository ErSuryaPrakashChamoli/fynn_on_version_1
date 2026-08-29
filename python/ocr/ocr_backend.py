"""PaddleOCR wrapper.

Loaded lazily and cached at module scope so a single document invocation
(which may loop over many pages) pays the model-load cost once, not once
per page. Each Laravel Process call is still its own OS process (see
ocr_engine.py docstring for why), so this only helps within one document.
"""
from __future__ import annotations

import numpy as np

from .types import OcrLine, mean_confidence

_ocr_instance = None


def _get_engine():
    global _ocr_instance

    if _ocr_instance is None:
        from paddleocr import PaddleOCR

        _ocr_instance = PaddleOCR(
            use_angle_cls=True,
            lang="en",
            show_log=False,
        )

    return _ocr_instance


def run_ocr(image: np.ndarray) -> list[OcrLine]:
    engine = _get_engine()
    result = engine.ocr(image, cls=True)

    lines: list[OcrLine] = []

    if not result or result[0] is None:
        return lines

    for box, (text, confidence) in result[0]:
        xs = [point[0] for point in box]
        ys = [point[1] for point in box]

        text = (text or "").strip()
        if text == "":
            continue

        lines.append(OcrLine(
            text=text,
            confidence=float(confidence),
            left=float(min(xs)),
            top=float(min(ys)),
            right=float(max(xs)),
            bottom=float(max(ys)),
        ))

    return lines
