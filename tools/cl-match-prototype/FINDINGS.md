# CL match confidence prototype — findings

See Tasks Doc #1358 for the full write-up. Summary:

1. Old scoring never auto-accepted exact/core docket alone (+0.50 < 0.65).
2. Press party often ≠ CL caption on multi-defendant dockets.
3. Same PACER docket → many CL ids → false ambiguous (gap 0).
4. Normalize to `YY-cr-NNNN` + court filter; auto-accept core+court at 0.70.
5. Prefer known-good seeds in drip (`loadSeeds`).

Artifacts: `known-good-seeds.json`, `run_prototype.php`, `results.json`.
