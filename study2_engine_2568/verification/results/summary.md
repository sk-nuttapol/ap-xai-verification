# Verification of SYNTHETIC_WORK_ASSESSMENT_2568_V1

Run: 2026-10-29T02:48:49+00:00 · PHP 8.3.6

| Check | Result |
|---|---|
| Generated cases | 10000 (5 seeds × 2000) |
| Decision agreement with independent oracle | 10000/10000 |
| Trace agreement (quota status, vote check, per-work admissibility and conditions) | 10000/10000 |
| Route × outcome cells populated | 27/27 |
| Boundary assertions passed | 458/458 |
| Negative (invalid-input) assertions passed | 20/20 |
| Mutants generated / stillborn | 292 / 0 |
| Killed / survived (timeouts) | 262 / 30 (0) |
| Survivors flagged likely equivalent/unreachable (heuristic) | 30 |

Survived mutants are listed in mutants.csv and must be classified by hand as equivalent or as test gaps.
