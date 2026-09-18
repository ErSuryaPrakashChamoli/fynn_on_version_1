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
from ocr.ocr_types import OcrLine


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


class UnequalColumnTableTests(unittest.TestCase):
    """A born-digital enquiry list (Name | State | Mobile | Loan Amount) whose
    name column is far wider than the rest. Splitting each row's span into
    equal slots put the state in the mobile field (where digit-only
    normalisation wiped it) and the mobile in the loan-amount field; the
    column boundaries must come from the gutters shared across all rows."""

    SCHEMA = [
        {"key": "customer_name", "label": "Customer Name", "type": "text"},
        {"key": "state", "label": "State", "type": "text"},
        {"key": "mobile_number", "label": "Mobile Number", "type": "mobile"},
        {"key": "loan_amount", "label": "Loan Amount", "type": "decimal"},
    ]

    ROWS = [
        ("Mrs. MEGHA S", "karnataka", "7829077476", "22,00,000"),
        ("Mr. CHANDRA PRAKASH TOMAR", "haryana", "7011974208", "25,00,000"),
        ("Kanaka Suryakanth Kanaka Suryakanth", "telangana", "8328294347", "15,00,000"),
        ("G HARIKA", "andhrapradesh", "9550566019", "20,00,000"),
        ("Mr. PRAJAPATI KALPESHKUMAR JAYANTIBHAI", "gujarat", "9879152911", "15,00,000"),
        ("Mr. V ERANNA", "karnataka", "8310202595", "1,00,000"),
        ("Mrs. DEEPIKA DOGRA", "haryana", "8920284019", "15,00,000"),
    ]

    # Column x-ranges in PDF points, mirroring an Excel export: text is
    # left-aligned in the two text columns and right-aligned in the numbers.
    COLUMNS = [(52, 270), (270, 347), (347, 437), (437, 487)]

    @staticmethod
    def _words(text: str, x: float, y: float, char_width: float = 5.0, space: float = 2.5) -> list[OcrLine]:
        boxes = []
        for word in text.split():
            width = len(word) * char_width
            boxes.append(OcrLine(word, 1.0, x, y, x + width, y + 10))
            x += width + space
        return boxes

    def _page(self) -> list[OcrLine]:
        page: list[OcrLine] = []
        for index, (name, state, mobile, amount) in enumerate(self.ROWS):
            y = 80 + index * 16
            page += self._words(name, self.COLUMNS[0][0] + 3, y)
            page += self._words(state, self.COLUMNS[1][0] + 3, y)
            page += self._words(mobile, self.COLUMNS[2][1] - 3 - len(mobile) * 5.0, y)
            page += self._words(amount, self.COLUMNS[3][1] - 3 - len(amount) * 5.0, y)
        return page

    def test_word_boxes_land_in_their_own_columns(self):
        result = extract_table([self._page()], self.SCHEMA)

        self.assertEqual(len(result["rows"]), len(self.ROWS))
        for row, (name, state, mobile, amount) in zip(result["rows"], self.ROWS):
            self.assertEqual(row["data"], {
                "customer_name": name,
                "state": state,
                "mobile_number": mobile,
                "loan_amount": amount.replace(",", ""),
            })

    def test_title_line_spanning_columns_does_not_hide_gutters(self):
        page = self._words("Axis Bank enquiry list for September 2026 all branches combined", 52, 40)
        page += self._page()

        result = extract_table([page], self.SCHEMA)

        data_rows = [row for row in result["rows"] if row["data"]["mobile_number"]]
        self.assertEqual(len(data_rows), len(self.ROWS))
        self.assertEqual(data_rows[-1]["data"]["state"], "haryana")
        self.assertEqual(data_rows[-1]["data"]["mobile_number"], "8920284019")

    def test_single_row_without_gutters_still_recovers_by_type(self):
        """One row on its own gives no cross-row gutters, so the even split
        is used; a state that then falls into the mobile slot must move to
        the empty text field, and a mobile glued to the amount must be
        pulled apart by pattern."""
        page = []
        y = 80
        page += self._words("Mrs. DEEPIKA DOGRA", 55, y)
        page += self._words("haryana", 273, y)
        page += self._words("8920284019", 384, y)
        page += self._words("15,00,000", 439, y)

        result = extract_table([page], self.SCHEMA)

        self.assertEqual(result["rows"][0]["data"], {
            "customer_name": "Mrs. DEEPIKA DOGRA",
            "state": "haryana",
            "mobile_number": "8920284019",
            "loan_amount": "1500000",
        })

    def test_merged_mobile_and_amount_box_is_split(self):
        page = [
            OcrLine("Mrs. DEEPIKA DOGRA", 0.9, 55, 80, 150, 90),
            OcrLine("haryana", 0.9, 273, 80, 310, 90),
            OcrLine("8920284019 15,00,000", 0.9, 384, 80, 484, 90),
        ]

        result = extract_table([page], self.SCHEMA)

        self.assertEqual(result["rows"][0]["data"]["mobile_number"], "8920284019")
        self.assertEqual(result["rows"][0]["data"]["loan_amount"], "1500000")
        self.assertEqual(result["rows"][0]["data"]["state"], "haryana")

    def test_amount_never_takes_the_mobile_number(self):
        schema = [
            {"key": "customer_name", "label": "Customer Name", "type": "text"},
            {"key": "loan_amount", "label": "Loan Amount", "type": "decimal"},
            {"key": "mobile_number", "label": "Mobile Number", "type": "mobile"},
        ]
        page = [OcrLine("RAHUL KUMAR 9876543210", 0.9, 10, 10, 200, 20)]

        result = extract_table([page], schema)

        self.assertEqual(result["rows"][0]["data"], {
            "customer_name": "RAHUL KUMAR",
            "loan_amount": None,
            "mobile_number": "9876543210",
        })


if __name__ == "__main__":
    unittest.main()
