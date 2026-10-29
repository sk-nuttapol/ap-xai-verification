# Reproducibility Package — International Experiments 1.0

This package contains synthetic technical experiments only. It contains no expert ratings, human-participant data, personal data, or historical personnel data.

## Locked versions

- Experiment: `international-experiments-1.0.0`
- Generator: `sim-generator-1.0.1`
- Independent oracle: `independent-decision-table-oracle-1.0.0`
- Baseline comparator: `baseline-comparator-1.0.0`
- Rule snapshot: `criteria-2564-v1`
- Explainable algorithm: `explainable-rule-engine-1.0.1`

## Experiment design

- Seeds: `2026080201`–`2026080205`
- Cases per seed: 2,000
- Total cases: 10,000
- Batches: 21–25
- Comparators: explainable engine, decision table, and opaque rule engine
- Ablations: full, no explanation, no counterfactual, and decision only
- Failure evaluation: observed failures are reported separately from controlled fault-injection tests

## Files

- `experiment_metadata.json`: design, locked versions, batches, and variability summary
- `multi_seed_results.csv`: results for every seed
- `baseline_comparison.csv`: predictive and artifact-availability comparison
- `ablation_results.csv`: component-removal results
- `failure_modes.csv`: observed and controlled fault-injection findings
- `rule_snapshot.json`: immutable rule snapshot used by the experiments
- `independent_oracle_input.schema.json`: JSON Schema for oracle inputs
- `independent_oracle_decision_table.json`: versioned decision table
- `ResearchSimulation.php`: generator and explainable engine source
- `ResearchIndependentOracle.php`: independent test oracle source
- `ResearchBaselineComparator.php`: baseline source
- `qa_research_simulation.php`: simulation QA test
- `verify_final_research_package.php`: package-verification test
- `SHA256SUMS.txt`: integrity manifest

## Re-run

From the project root, with the research database available:

```powershell
php scripts\run_international_research_experiments.php
```

The runner is idempotent for matching batch name, seed, generator version, rule version, algorithm version, and case count. Verify all files using `SHA256SUMS.txt` after export.

## Interpretation

The independent oracle is implemented separately from the production engine, but both operationalize the same frozen policy specification. Agreement therefore demonstrates conformance to that specification, not the substantive correctness or fairness of the source policy. Ablation results measure artifact availability and cannot establish human usefulness without an approved expert study.

