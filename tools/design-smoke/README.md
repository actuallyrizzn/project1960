# Design smoke (S7)

Python Playwright against a **child** `php -S` on an ephemeral port with a fixture SQLite DB. Server is always torn down.

```bash
# Needs playwright + chromium (reuse another project's design-smoke .venv if needed)
python3 tools/design-smoke/verify.py
```

Outputs under `tools/design-smoke/out/` (gitignored): `*_desktop.png`, `*_mobile.png`, `report.txt`.

## Admin smoke (AD-U1)

Live (or `BASE_URL`) login → home → users → api-keys, desktop + mobile:

```bash
BASE_URL=https://project1960.rizzn.net python3 tools/design-smoke/verify_admin.py
# Creds: ~/.ssh/project1960-admin.pass (P1960_ADMIN_USERNAME / P1960_ADMIN_PASSWORD)
```

Outputs under `tools/design-smoke/out-admin/` (gitignored).
