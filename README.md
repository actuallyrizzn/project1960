# Project 1960

Data journalism on **18 U.S.C. § 1960** / DOJ press releases (Operation Chokepoint 2.0 / crypto-adjacent money-transmission cases).

**Public site (target):** `https://project1960.rizzn.net` — PHP explorer + PHP scraper on multihost.  
**Board:** [DSC Tasks #65](https://tasks.decisionsciencecorp.com/admin/project.php?id=65) · slice map [Doc #1308](https://tasks.decisionsciencecorp.com/admin/doc.php?id=1308)

## Layout

| Path | Role |
|------|------|
| `public/` | Multihost PHP docroot (`index.php`, `includes/`, `assets/`) |
| `legacy/` | Previous Python Flask app, DOJ scraper, Venice enrich/verify |
| `LICENSE` | CC BY-SA 4.0 |
| `env.example` | Shared env hints (Venice, DB path, CourtListener) |

Until cutover, the live Flask explorer still runs from the NewDev copy (`/root/justice`). This repo’s **`legacy/`** tree is the canonical Python source after R0.

## Legacy Python (interim)

```bash
cd legacy
pip install -r requirements.txt
cp ../env.example .env   # or symlink
python scraper.py
python app.py
```

Full legacy docs: [`legacy/README.md`](legacy/README.md) and [`legacy/docs/`](legacy/docs/).

## CourtListener (Phase 2)

Deep-enrich uses the existing SDK: [actuallyrizzn/courtlistener-sdk](https://github.com/actuallyrizzn/courtlistener-sdk) (PHP + Python). Do not reinvent the HTTP client.

## License

Creative Commons Attribution-ShareAlike 4.0 International — see [LICENSE](LICENSE).
