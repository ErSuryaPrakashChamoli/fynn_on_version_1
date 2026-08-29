"""Image preprocessing to improve OCR accuracy on scanned/photographed documents.

Runs before every OCR pass: grayscale, deskew, denoise, contrast enhancement.
Orientation (90/180/270) is left to PaddleOCR's own angle classifier
(use_angle_cls=True) rather than reimplemented here, since it already reads
the actual text direction rather than guessing from geometry.
"""
from __future__ import annotations

import cv2
import numpy as np


def preprocess(image: np.ndarray) -> np.ndarray:
    gray = to_grayscale(image)
    denoised = denoise(gray)
    deskewed = deskew(denoised)
    enhanced = enhance_contrast(deskewed)
    return cv2.cvtColor(enhanced, cv2.COLOR_GRAY2BGR)


def to_grayscale(image: np.ndarray) -> np.ndarray:
    if len(image.shape) == 2:
        return image
    return cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)


def denoise(gray: np.ndarray) -> np.ndarray:
    return cv2.fastNlMeansDenoising(gray, h=10, templateWindowSize=7, searchWindowSize=21)


def enhance_contrast(gray: np.ndarray) -> np.ndarray:
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    return clahe.apply(gray)


def deskew(gray: np.ndarray) -> np.ndarray:
    """Corrects small skew (a few degrees) from scanning/photographing.

    Estimates the dominant text-line angle from the minimum-area bounding
    box of thresholded foreground pixels. Large angles (>15 degrees) are
    treated as a bad estimate (likely no dominant text mass, e.g. a mostly
    blank page) and left uncorrected rather than risking a wrong rotation.
    """
    threshold = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY_INV | cv2.THRESH_OTSU)[1]
    coordinates = cv2.findNonZero(threshold)

    if coordinates is None or len(coordinates) < 50:
        return gray

    angle = cv2.minAreaRect(coordinates)[-1]

    if angle < -45:
        angle = -(90 + angle)
    else:
        angle = -angle

    if abs(angle) < 0.1 or abs(angle) > 15:
        return gray

    (height, width) = gray.shape[:2]
    center = (width // 2, height // 2)
    matrix = cv2.getRotationMatrix2D(center, angle, 1.0)

    return cv2.warpAffine(
        gray,
        matrix,
        (width, height),
        flags=cv2.INTER_CUBIC,
        borderMode=cv2.BORDER_REPLICATE,
    )
