"""Dependency-light regression tests for the layout/extraction logic.

Deliberately avoids importing anything that needs PaddleOCR/OpenCV/PyMuPDF
(ocr_backend.py, pdf_utils.py, preprocessing.py) so these can run in any
Python environment as a fast sanity check of the actual row/field
assignment algorithm, independent of OCR model accuracy.

Run with:  python3 -m unittest discover -s python/ocr/tests -p "test_*.py"
"""
from __future__ import annotations

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))

from ocr import validators
from ocr.extraction import extract_fields, extract_table
from ocr.types import OcrLine


class ValidatorTests(unittest.TestCase):
    def test_pan_validation(self):
        self.assertTrue(validators.is_valid_pan("ABCDE1234F"))
        self.assertFalse(validators.is_valid_pan("ABCDE1234"))
        self.assertFalse(validators.is_valid_pan("1BCDE1234F"))

    def test_find_pan_in_noisy_text(self):
        self.assertEqual(validators.find_pan("PAN Number: abcde1234f (verified)"), "ABCDE1234F")

    def test_find_mobile_rejects_longer_sequences(self):
        self.assertIsNone(validators.find_mobile("account number 98765432109876"))
        self.assertEqual(validators.find_mobile("call 9876543210 now"), "9876543210")

    def test_find_mobile_rejects_landline_prefix(self):
        self.assertIsNone(validators.find_mobile("0123456789"))

    def test_normalize_date_dd_mm_yyyy(self):
        self.assertEqual(validators.normalize_date("12/03/2024"), "2024-03-12")

    def test_normalize_date_dashes(self):
        self.assertEqual(validators.normalize_date("05-01-99"), "1999-01-05")

    def test_normalize_amount_strips_currency(self):
        self.assertEqual(validators.normalize_amount("Rs. 45,000.50"), "45000.50")


class FieldExtractionTests(unittest.TestCase):
    def test_inline_label_value_lines(self):
        lines = [
            OcrLine("Name: RAHUL KUMAR", 0.90, 10, 10, 200, 30),
            OcrLine("PAN: ABCDE1234F", 0.95, 10, 40, 200, 60),
            OcrLine("Mobile: 9876543210", 0.92, 10, 70, 200, 90),
        ]

        fields = extract_fields(lines)

        self.assertEqual(fields["customer_name"]["value"], "RAHUL KUMAR")
        self.assertEqual(fields["pan_number"]["value"], "ABCDE1234F")
        self.assertEqual(fields["mobile_no"]["value"], "9876543210")

    def test_label_and_value_in_separate_boxes_same_row(self):
        """Reproduces the prompt's two-column layout case: label and value
        are two different OCR boxes, associated by coordinates rather than
        both appearing on one text line."""
        lines = [
            OcrLine("Name", 0.90, 10, 10, 60, 30),
            OcrLine("RAHUL KUMAR", 0.90, 100, 10, 250, 30),
        ]

        fields = extract_fields(lines)

        self.assertEqual(fields["customer_name"]["value"], "RAHUL KUMAR")

    def test_pan_pattern_fallback_when_no_label_found(self):
        lines = [OcrLine("Some header text ABCDE1234F trailing", 0.8, 10, 10, 300, 30)]

        fields = extract_fields(lines)

        self.assertEqual(fields["pan_number"]["value"], "ABCDE1234F")
        self.assertEqual(fields["pan_number"]["source"], "pattern")

    def test_invalid_pan_value_is_rejected(self):
        lines = [OcrLine("PAN: 1234INVALID", 0.9, 10, 10, 200, 30)]

        fields = extract_fields(lines)

        self.assertNotIn("pan_number", fields)


class TableExtractionTests(unittest.TestCase):
    SCHEMA = [
        {"key": "created_on", "label": "Created On", "type": "date"},
        {"key": "full_name", "label": "Full Name", "type": "text"},
        {"key": "mobile_number", "label": "Mobile Number", "type": "mobile"},
    ]

    def test_one_box_per_column(self):
        pages_lines = [[
            OcrLine("12/03/2024", 0.9, 10, 10, 90, 30),
            OcrLine("RAHUL KUMAR", 0.9, 150, 10, 300, 30),
            OcrLine("9876543210", 0.9, 350, 10, 450, 30),
        ]]

        result = extract_table(pages_lines, self.SCHEMA)

        self.assertEqual(result["headers"], ["Created On", "Full Name", "Mobile Number"])
        self.assertEqual(len(result["rows"]), 1)
        self.assertEqual(result["rows"][0]["data"], {
            "created_on": "2024-03-12",
            "full_name": "RAHUL KUMAR",
            "mobile_number": "9876543210",
        })

    def test_merged_row_splits_by_format_pattern(self):
        """Dense/noisy scans often merge a whole row into one OCR box —
        the old PHP pipeline had the same failure mode and handled it the
        same way: pull out format-detectable values first, then assign
        leftover text to the remaining field."""
        pages_lines = [[
            OcrLine("12/03/2024 RAHUL KUMAR 9876543210", 0.85, 10, 10, 450, 30),
        ]]

        result = extract_table(pages_lines, self.SCHEMA)

        self.assertEqual(result["rows"][0]["data"], {
            "created_on": "2024-03-12",
            "full_name": "RAHUL KUMAR",
            "mobile_number": "9876543210",
        })

    def test_multiple_rows_across_pages(self):
        page1 = [
            OcrLine("Created On", 0.9, 10, 5, 90, 20),
            OcrLine("Full Name", 0.9, 150, 5, 300, 20),
            OcrLine("Mobile Number", 0.9, 350, 5, 450, 20),
            OcrLine("01/01/2024", 0.9, 10, 40, 90, 60),
            OcrLine("ANITA SHARMA", 0.9, 150, 40, 300, 60),
            OcrLine("9123456789", 0.9, 350, 40, 450, 60),
        ]
        page2 = [
            OcrLine("02/01/2024", 0.9, 10, 40, 90, 60),
            OcrLine("VIJAY SINGH", 0.9, 150, 40, 300, 60),
            OcrLine("9988776655", 0.9, 350, 40, 450, 60),
        ]

        result = extract_table([page1, page2], self.SCHEMA)

        # The header row on page 1 must be dropped, not counted as data.
        self.assertEqual(len(result["rows"]), 2)
        self.assertEqual(result["rows"][0]["data"]["full_name"], "ANITA SHARMA")
        self.assertEqual(result["rows"][1]["data"]["full_name"], "VIJAY SINGH")

    def test_empty_page_produces_no_rows(self):
        result = extract_table([[]], self.SCHEMA)
        self.assertEqual(result["rows"], [])


if __name__ == "__main__":
    unittest.main()
