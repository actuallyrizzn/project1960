# OCR stack (CL-O1)

## Decision

**Local tesseract + poppler** on the worker host (prefer **NewDev** for batch OCR).

| Piece | Binary | Role |
|-------|--------|------|
| Text-layer PDFs | `pdftotext` (poppler-utils) | Fast path — no OCR when PDF already has text |
| Scanned PDFs | `pdftoppm` → `tesseract` | Rasterize pages, OCR each PNG |
| Cloud OCR | — | **Not used** (cost; keep Venice budget for extract) |

Proven on NewDev (`64.95.11.220`): tesseract **5.3.4**, poppler-utils present.

## Install (Ubuntu)

```bash
apt-get install -y tesseract-ocr tesseract-ocr-eng poppler-utils
```

## Sample commands

```bash
# Text layer
pdftotext -layout /path/to/doc.pdf -

# OCR path
pdftoppm -png -r 200 /path/to/doc.pdf /tmp/page
tesseract /tmp/page-1.png stdout -l eng
```

## App wiring

- Pending docs: `courtlistener_documents.ocr_status = pending`
- Worker: `Project1960\CourtListener\OcrWorker` + `bin/ocr-docs.php`
- Engine: `TesseractOcrEngine` (injectable; tests use a fake)

Multihost may omit tesseract until cron is pointed at NewDev with a shared DB/docs mount — document that in ops when wiring cron.
