#!/usr/bin/env python3
"""AD-U1 — admin Playwright smoke against live (or BASE_URL).

Loads ~/.ssh/project1960-admin.pass (or P1960_ADMIN_* env).
Desktop + mobile: login wall, home, users, api-keys.

  BASE_URL=https://project1960.rizzn.net python3 tools/design-smoke/verify_admin.py
"""
from __future__ import annotations

import os
import sys
from pathlib import Path

OUT = Path(__file__).resolve().parent / "out-admin"
DESKTOP = {"width": 1280, "height": 800}
MOBILE = {"width": 390, "height": 844}
PASS_FILE = Path(os.path.expanduser("~/.ssh/project1960-admin.pass"))


def load_creds() -> tuple[str, str]:
    env = dict(os.environ)
    if PASS_FILE.is_file():
        for line in PASS_FILE.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip().strip('"').strip("'")
    user = env.get("P1960_ADMIN_USERNAME") or env.get("P1960_ADMIN_EMAIL")
    password = env.get("P1960_ADMIN_PASSWORD")
    if not user or not password:
        raise SystemExit("missing P1960_ADMIN_USERNAME/PASSWORD (pass file or env)")
    return user, password


def main() -> int:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("python playwright missing", file=sys.stderr)
        return 1

    base = (os.environ.get("BASE_URL") or "https://project1960.rizzn.net").rstrip("/")
    user, password = load_creds()
    OUT.mkdir(parents=True, exist_ok=True)
    for old in OUT.glob("*.png"):
        old.unlink()

    shots: list[Path] = []
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        try:
            for vp_name, vp in (("desktop", DESKTOP), ("mobile", MOBILE)):
                context = browser.new_context(viewport=vp)
                page = context.new_page()

                page.goto(f"{base}/admin/login/", wait_until="domcontentloaded", timeout=60000)
                page.locator("form").first.wait_for(timeout=15000)
                dest = OUT / f"login_{vp_name}.png"
                page.screenshot(path=str(dest), full_page=False)
                shots.append(dest)
                print(f"shot {dest}")

                page.fill("#username", user)
                page.fill("#password", password)
                page.click('button[type="submit"]')
                page.wait_for_url("**/admin/**", timeout=30000)
                page.locator("nav, .navbar, aside, .admin-nav, a").first.wait_for(timeout=15000)

                for label, path in (
                    ("home", "/admin/"),
                    ("users", "/admin/users/"),
                    ("keys", "/admin/api-keys/"),
                ):
                    page.goto(f"{base}{path}", wait_until="domcontentloaded", timeout=60000)
                    page.wait_for_timeout(400)
                    dest = OUT / f"{label}_{vp_name}.png"
                    page.screenshot(path=str(dest), full_page=False)
                    shots.append(dest)
                    print(f"shot {dest}")
                    body = page.content()
                    if "Sign in" in body and "csrf_token" in body and label != "login":
                        print(f"unexpected login wall on {label} {vp_name}", file=sys.stderr)
                        return 1

                context.close()
        finally:
            browser.close()

    report = OUT / "report.txt"
    report.write_text(
        "Project 1960 AD-U1 admin smoke\n"
        f"base={base}\n"
        f"shots={len(shots)}\n"
        + "\n".join(p.name for p in shots)
        + "\n",
        encoding="utf-8",
    )
    print(f"wrote {report}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
