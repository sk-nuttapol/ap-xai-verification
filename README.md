# Reproducibility package: explainable rule-based decision support for academic-promotion screening

[![DOI](https://zenodo.org/badge/DOI/10.5281/zenodo.23756467.svg)](https://doi.org/10.5281/zenodo.23756467)

Companion package for the manuscript *"Reproducible validation of an explainable rule-based decision support system: oracle conformance, exhaustive mutation analysis, and blinded expert judgment in academic-promotion screening"*.

All cases in this package are **synthetic**. The package contains no applicant records, no personnel data and no expert-level responses. Expert results are given as aggregates only, in line with the study's exemption determination and consent terms.

## Contents

| Folder | What it contains | Manuscript section |
|---|---|---|
| `study1_engine_2564/` | Engine v1.0.1 (criteria-2564-v1), seed-locked generator, independent decision-table oracle, baselines, ablation, multi-seed results, rule snapshot, schema | 3.2–3.5, 4.1–4.3 |
| `study1_engine_2564/replay_2026-10-29/` | Replay script (reproduces the five recorded batch hashes) and source-level mutation runner, with coverage (165 cells), boundary (990 assertions) and 363-row mutant results | 3.4, 4.1–4.2 |
| `study2_engine_2568/engine_src/` | Source of the scoped 2025 engine `SYNTHETIC_WORK_ASSESSMENT_2568_V1` exactly as deployed (SHA-256 below) | 3.6 |
| `study2_engine_2568/verification/` | `verify_2568_scoped_engine.php` (generator, independent oracle, coverage, boundary, negative tests, token-level mutation) and its results, including `mutants_reviewed.csv` | 3.7, 4.4 |
| `study2_engine_2568/expert_study/frozen_cases/` | The 12 frozen synthetic cases as shown to experts (Thai), with reference decision, system explanation and counterfactual status | 3.8 |
| `study2_engine_2568/expert_study/aggregates/` | Agreement summary, per-case agreement, confusion matrix, rating summaries, participant summary | 4.5–4.6 |
| `study2_engine_2568/expert_study/analysis/` | `expert_analysis.py`, which regenerates the aggregates from the private study export | 3.10 |

## Reproducing the Study 1 analyses

Requirements: PHP ≥ 8.2. No database is needed.

```bash
cd study1_engine_2564/replay_2026-10-29
php replay_study1.php .. ./out      # 5 batch hashes, 10,000/10,000 agreement, coverage, boundary
php mutation_study1.php .. ./out    # 363 mutants: 333 killed, 30 equivalent
```

Expected results: every recorded batch hash in `multi_seed_results.csv` is reproduced exactly; 165/165 coverage cells; 990/990 boundary assertions (198 per seed); mutation score 333/333. Running both scripts regenerates the result files in that folder byte for byte (see its `SHA256SUMS.txt`).

## Reproducing the Study 2 verification

Requirements: PHP ≥ 8.2 with the tokenizer extension. No database or network access is needed.

The engine refuses to decide unless the SHA-256 digests of the two source regulations match the literals in its code. Both PDFs are therefore included, byte-identical to the copies used in production: `engine_src/outputs/เกณฑ์/2564.pdf` (B.E. 2564 criteria, SHA-256 154410e8…2d27e) and `engine_src/outputs/เกณฑ์/2568-3-1.pdf` (B.E. 2568 No. 3 amendment, SHA-256 c9fbab10…4289b). They are announcements of the Civil Service Commission in Higher Education Institutions published in the Royal Thai Government Gazette and are not subject to copyright under section 7 of the Thai Copyright Act B.E. 2537 (1994).

From the package root:

```bash
php study2_engine_2568/verification/verify_2568_scoped_engine.php \
    --app=study2_engine_2568/engine_src --out=./verify_out
```

Defaults: seeds 2026102901–2026102905, 2,000 cases per seed, mutation on 458 boundary + 20 negative + 2,000 generated cases. A full run takes about 2–3 minutes on a laptop. **Run it in an isolated environment, never inside a production container**: it is CPU-intensive.

Expected results (`verification/results/summary.md`): decision and trace agreement 10,000/10,000; 27/27 route × outcome cells; 458/458 boundary and 20/20 negative assertions; 292 mutants, 262 killed, 30 surviving and reviewed as equivalent in `mutants_reviewed.csv`, mutation score 262/262. The files `boundary.csv`, `cases.csv`, `coverage_conditions.csv`, `coverage_route_outcome.csv`, `disagreements.json`, `negative.csv`, and `seeds.csv` are reproduced byte for byte; `summary.json` and `summary.md` differ only in the run timestamp.

## Source integrity (scoped 2025 engine)

| File | SHA-256 |
|---|---|
| includes/Research2568MixedPreviewEvaluator.php | 599f61df363e39fb9b61355e9905c5e6f6bd1ff8e70bef0d9ca1d4d918f4c018 |
| includes/Research2568WorkPolicyDraft.php | 3475cf0c4abe83b13bbab2367cf97cd5fde16ee52a065d14369a60aa9c970fe6 |
| includes/ResearchDraftWorkQuotas.php | eae474b13e85aa5d33cd1992879b3206de1871c7275b3d08e54baaafdd379a32 |
| includes/Research2568StructureDraft.php | 235b0f0648874b52f112e0d708a5fbeee9c2cae7d2b6d3383d688bda75e1d726 |
| config/research_reference_component_drafts.php | f2179686e4a6dbe9fa74469ff7de081cf2e592b05ea3dd201cffca025385b5c3 |
| outputs/เกณฑ์/2564.pdf | 154410e89c4fc76598b702e51ed130f297208712fda25d07d34b60292fe2d27e |
| outputs/เกณฑ์/2568-3-1.pdf | c9fbab10667fafd735e8bb5555a1b0235101562034bffd52f2cd514162d4289b |

These are the sources deployed in production. The results in `verification/results/` (package v1.2.0 and later) were produced on exactly these files, so `summary.json` lists the same digests.

**Provenance note.** In package versions up to v1.1.0 the regulation PDFs were not included, and the published results had been produced with the two expected-hash literals replaced by digests of placeholder files. The original summaries are kept as `results/archive/summary_placeholder_pdf_run.{json,md}`. Re-running the unmodified sources with the real PDFs gave byte-identical case, boundary, coverage, negative-test, and seed files and the same mutation outcome. Version v1.2.0 also fixes a harness bug that made a relative `--app` path fail, and removes a PHP 8.3 deprecation warning; neither change affects any result.

## Licence

Code: MIT (see `LICENSE`). Data, cases and aggregates: CC BY 4.0. Please cite the article and this package (`CITATION.cff`).
