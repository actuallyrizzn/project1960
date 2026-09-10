#!/usr/bin/env python3
"""Project 1960 explorer visual smoke.

Starts a bounded `php -S` child on an ephemeral port with a fixture SQLite DB.
Always tears down the server. Desktop + mobile screenshots for key routes.

  python3 tools/design-smoke/verify.py
"""
from __future__ import annotations

import os
import shutil
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.request
from contextlib import contextmanager
from pathlib import Path

OUT = Path(__file__).resolve().parent / "out"
REPO = Path(__file__).resolve().parents[2]
PUBLIC = REPO / "public"
DESKTOP = {"width": 1280, "height": 800}
MOBILE = {"width": 390, "height": 844}

ROUTES = [
    ("home", "/"),
    ("cases", "/cases/"),
    ("case_detail", "/case.php?id=fixture-case-1"),
    ("enrichment", "/enrichment/"),
    ("about", "/about/"),
    ("health", "/health/"),
]


def free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])


def wait_http(url: str, timeout: float = 10.0) -> None:
    deadline = time.time() + timeout
    last: Exception | None = None
    while time.time() < deadline:
        try:
            urllib.request.urlopen(url, timeout=1.5)
            return
        except (urllib.error.URLError, TimeoutError, OSError) as exc:
            last = exc
            time.sleep(0.15)
    raise RuntimeError(f"php -S never answered {url}: {last}")


def stop_php(proc: subprocess.Popen[bytes]) -> None:
    if proc.poll() is not None:
        return
    proc.terminate()
    try:
        proc.wait(timeout=3)
    except subprocess.TimeoutExpired:
        proc.kill()
        proc.wait(timeout=3)


def build_fixture_db(path: Path) -> None:
    php = shutil.which("php")
    if not php:
        raise RuntimeError("php not on PATH")
    if path.exists():
        path.unlink()
    seed = Path(__file__).resolve().parent / "seed-fixture.php"
    subprocess.check_call([php, str(seed), str(path)], cwd=str(REPO))
    if not path.is_file():
        raise RuntimeError(f"fixture db missing after seed: {path}")


@contextmanager
def php_server(db_path: Path):
    php = shutil.which("php")
    if not php:
        raise RuntimeError("php not on PATH")
    port = free_port()
    env = os.environ.copy()
    env["DATABASE_PATH"] = str(db_path)
    proc = subprocess.Popen(
        [php, "-S", f"127.0.0.1:{port}", "-t", str(PUBLIC)],
        cwd=str(PUBLIC),
        env=env,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    try:
        wait_http(f"http://127.0.0.1:{port}/")
        yield f"http://127.0.0.1:{port}"
    finally:
        stop_php(proc)


def shot(page, name: str) -> Path:
    dest = OUT / f"{name}.png"
    page.screenshot(path=str(dest), full_page=False)
    print(f"shot {dest}")
    return dest


def main() -> int:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("python playwright missing", file=sys.stderr)
        return 1

    OUT.mkdir(parents=True, exist_ok=True)
    for old in OUT.glob("*.png"):
        old.unlink()

    db_path = OUT / "fixture.db"
    build_fixture_db(db_path)

    paths: list[Path] = []
    with php_server(db_path) as base:
        # Sanity: HTML contains Project 1960
        with urllib.request.urlopen(base + "/", timeout=5) as resp:
            body = resp.read().decode("utf-8", errors="replace")
        if "Project 1960" not in body:
            print("home missing brand", file=sys.stderr)
            return 1

        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            try:
                for label, path in ROUTES:
                    for vp_name, vp in (("desktop", DESKTOP), ("mobile", MOBILE)):
                        page = browser.new_page(viewport=vp)
                        page.goto(base + path, wait_until="networkidle", timeout=30000)
                        # Brand must appear
                        page.locator(".navbar-brand").wait_for(timeout=8000)
                        paths.append(shot(page, f"{label}_{vp_name}"))
                        page.close()
            finally:
                browser.close()

    report = OUT / "report.txt"
    report.write_text(
        "Project 1960 S7 visual smoke\n"
        f"routes={len(ROUTES)} viewports=2 shots={len(paths)}\n"
        + "\n".join(p.name for p in paths)
        + "\n",
        encoding="utf-8",
    )
    print(f"wrote {report}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
