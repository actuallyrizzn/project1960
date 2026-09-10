# Design smoke (S7)

Python Playwright against a **child** `php -S` on an ephemeral port with a fixture SQLite DB. Server is always torn down.

```bash
# Needs playwright + chromium (reuse another project's design-smoke .venv if needed)
python3 tools/design-smoke/verify.py
```

Outputs under `tools/design-smoke/out/` (gitignored): `*_desktop.png`, `*_mobile.png`, `report.txt`.
