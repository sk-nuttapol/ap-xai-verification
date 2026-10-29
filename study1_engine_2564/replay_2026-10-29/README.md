# Study 1 replay and mutation analysis (regenerated 2026-10-29)

The original Study 1 package did not archive the rule-scenario coverage file, the boundary-assertion file,
or the mutation runner and its 363-row result. They were regenerated from the locked sources in the parent
folder (`ResearchSimulation.php`, SHA-256 64b62cf2…4448; `ResearchIndependentOracle.php`; `rule_snapshot.json`).

## Scripts
- `replay_study1.php` regenerates the five batches (seeds 2026080201–2026080205, 2,000 cases each) through the
  generator `sim-generator-1.0.1` and engine `explainable-rule-engine-1.0.1`. It compares each case with the
  independent oracle and checks every batch hash against `multi_seed_results.csv`.
- `mutation_study1.php` builds 11 first-order operators × 33 rules = 363 source-level mutants of
  `engineEvaluate` and runs 1,008 inputs per mutant: q, a ∈ {τ−1, τ, τ+1} (non-negative) × missing × conflict.

Run from this folder, with PHP ≥ 8.2 and no database:

    php replay_study1.php ..  .
    php mutation_study1.php ..  .

## Results (PHP 8.3.6)
- All 5 recorded batch hashes were reproduced exactly. Decisions and failed-rule traces agreed on 10,000/10,000 cases.
- Coverage: 165/165 rule-scenario cells were populated and all agreed (`study1_rule_scenario_coverage.csv`).
- Boundary: 990/990 assertions passed, 198 per seed and 165 per boundary type (`study1_boundary_assertions.csv`).
- Mutation: 363 mutants, 333 killed, 30 surviving. All 30 survivors are equivalent: AUTHOR_MINUS_1 and AUTHOR_DELETE
  on the 15 rules with τa = 0. Score 333/333 (`study1_mutants.csv`, `study1_mutation_summary.json`).
