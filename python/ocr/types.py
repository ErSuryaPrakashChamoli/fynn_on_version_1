"""Shared, dependency-light data types.

Deliberately has no numpy/opencv/paddleocr imports so extraction.py's
layout logic (and its tests) can be exercised without the full OCR stack
installed.
"""
from __future__ import annotations

from dataclasses import dataclass


@dataclass
class OcrLine:
    text: str
    confidence: float
    left: float
    top: float
    right: float
    bottom: float

    @property
    def center_y(self) -> float:
        return (self.top + self.bottom) / 2

    @property
    def center_x(self) -> float:
        return (self.left + self.right) / 2


def mean_confidence(lines: list[OcrLine]) -> float | None:
    if not lines:
        return None

    return sum(line.confidence for line in lines) / len(lines)
