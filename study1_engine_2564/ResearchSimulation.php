<?php

final class ResearchSimulation
{
    public const GENERATOR_VERSION = 'sim-generator-1.0.1';

    public static function isAllowed(): bool
    {
        return in_array(strtolower(Auth::roleName()), ['admin', 'hr', 'researcher'], true);
    }

    public static function createBatch(
        PDO $pdo,
        int $studyId,
        int $caseCount,
        int $seed,
        string $ruleVersion,
        string $algorithmVersion,
        ?string $batchName = null
    ): int {
        if (!self::isAllowed()) {
            throw new RuntimeException('ไม่มีสิทธิ์สร้างชุดข้อมูลจำลอง');
        }
        if ($caseCount < 100 || $caseCount > 10000) {
            throw new InvalidArgumentException('จำนวนกรณีต้องอยู่ระหว่าง 100 ถึง 10,000');
        }
        if ($seed < 1) {
            throw new InvalidArgumentException('random seed ต้องเป็นจำนวนเต็มบวก');
        }
        $ruleVersion = trim($ruleVersion);
        $algorithmVersion = trim($algorithmVersion);
        if ($ruleVersion === '' || $algorithmVersion === '') {
            throw new InvalidArgumentException('ต้องระบุ rule version และ algorithm version');
        }

        $lockedRules = self::rules($pdo, $ruleVersion);
        if (!$lockedRules) {
            throw new RuntimeException('ไม่พบกฎสำหรับเวอร์ชันที่ระบุ');
        }
        $batchCode = 'SIM-' . date('Ymd-His') . '-' . substr(hash('sha256', $seed . '|' . microtime(true)), 0, 8);
        $distribution = [
            'decision' => ['PASS' => 35, 'FAIL' => 45, 'INSUFFICIENT_DATA' => 15, 'CONFLICT' => 5],
            'position' => ['ASST_PROF' => 40, 'ASSOC_PROF' => 35, 'PROF' => 25],
            'difficulty' => ['EASY' => 25, 'MODERATE' => 40, 'HARD' => 25, 'BOUNDARY' => 10],
            'boundary_types' => ['BELOW', 'EXACT', 'ABOVE', 'MISSING', 'CONFLICT', 'IRRELEVANT_CHANGE'],
            'rule_snapshot' => $lockedRules,
            'rule_snapshot_hash' => hash('sha256', self::json($lockedRules)),
        ];

        $stmt = $pdo->prepare(
            "INSERT INTO simulation_batches
             (study_id,batch_code,batch_name,random_seed,generator_version,rule_version,
              algorithm_version,target_case_count,distribution_json,status,started_at,created_by)
             VALUES (?,?,?,?,?,?,?,?,?,'RUNNING',NOW(6),?)"
        );
        $stmt->execute([
            $studyId, $batchCode, $batchName ?: "ชุดจำลอง {$caseCount} กรณี",
            $seed, self::GENERATOR_VERSION, $ruleVersion, $algorithmVersion, $caseCount,
            self::json($distribution), Auth::user()['USERLOGIN'] ?? (string)($_SESSION['user_id'] ?? 'system'),
        ]);
        $batchId = (int)$pdo->lastInsertId();

        try {
            self::runBatch($pdo, $batchId);
        } catch (Throwable $e) {
            $fail = $pdo->prepare("UPDATE simulation_batches SET status='FAILED',error_message=?,completed_at=NOW(6) WHERE batch_id=?");
            $fail->execute([mb_substr($e->getMessage(), 0, 2000), $batchId]);
            throw $e;
        }
        return $batchId;
    }

    public static function rerunHash(PDO $pdo, int $batchId): string
    {
        $batch = self::batch($pdo, $batchId);
        $rules = self::lockedRules($pdo, $batch);
        $cases = self::generateCaseDefinitions(
            $rules,
            (int)$batch['target_case_count'],
            (int)$batch['random_seed'],
            (string)$batch['rule_version'],
            (string)$batch['algorithm_version']
        );
        return hash('sha256', implode('|', array_column($cases, 'case_hash')));
    }

    public static function batch(PDO $pdo, int $batchId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM simulation_batches WHERE batch_id=?');
        $stmt->execute([$batchId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('ไม่พบ simulation batch');
        }
        return $row;
    }

    private static function runBatch(PDO $pdo, int $batchId): void
    {
        $batch = self::batch($pdo, $batchId);
        $rules = self::lockedRules($pdo, $batch);
        if (!$rules) {
            throw new RuntimeException('ไม่พบกฎสำหรับสร้างข้อมูลจำลอง');
        }
        $definitions = self::generateCaseDefinitions(
            $rules,
            (int)$batch['target_case_count'],
            (int)$batch['random_seed'],
            (string)$batch['rule_version'],
            (string)$batch['algorithm_version']
        );

        $pdo->beginTransaction();
        try {
            $insertCase = $pdo->prepare(
                "INSERT INTO simulation_cases
                 (batch_id,case_no,case_code,case_source,position_code,criteria_year,rule_code,
                  difficulty_level,input_json,expected_decision,actual_decision,is_correct,
                  expected_failed_rules_json,actual_failed_rules_json,evidence_json,explanation_json,
                  counterfactual_json,processing_time_ms,case_hash,output_hash,evaluated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(6))"
            );
            $insertBoundary = $pdo->prepare(
                "INSERT INTO boundary_tests
                 (batch_id,simulation_case_id,rule_code,boundary_type,tested_field,input_value,
                  boundary_value,expected_decision,actual_decision,passed,assertion_message)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            );
            $insertExplanation = $pdo->prepare(
                "INSERT INTO explanation_evaluations
                 (batch_id,simulation_case_id,evaluator_type,evaluator_code,explanation_version,
                  fidelity_score,evidence_precision,evidence_recall,completeness_score,
                  non_misleading_score,overall_score,passed,findings_json,comments)
                 VALUES (?,?,'AUTOMATED','AUTO',?,?,?,?,?,?,?,?,?,?)"
            );
            $insertCounterfactual = $pdo->prepare(
                "INSERT INTO counterfactual_evaluations
                 (batch_id,simulation_case_id,counterfactual_version,target_decision,
                  changed_features_json,changed_feature_count,resulting_decision,validity_score,
                  minimality_score,actionability_score,rule_consistency_score,overall_score,passed,failure_reason)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );

            $coverage = [];
            $passed = 0;
            foreach ($definitions as $definition) {
                $started = hrtime(true);
                $actual = self::engineEvaluate($definition['input'], $definition['rule']);
                $elapsedMs = max(0.001, (hrtime(true) - $started) / 1_000_000);
                $isCorrect = $actual['decision'] === $definition['expected_decision']
                    && $actual['failed_rules'] === $definition['expected_failed_rules'];
                $passed += $isCorrect ? 1 : 0;

                $explanation = self::buildExplanation($actual, $definition['input'], $definition['rule']);
                $counterfactual = self::buildCounterfactual($actual, $definition['input'], $definition['rule']);
                $outputHash = hash('sha256', self::json([
                    'decision' => $actual['decision'],
                    'failed_rules' => $actual['failed_rules'],
                    'explanation' => $explanation,
                    'counterfactual' => $counterfactual,
                ]));
                $insertCase->execute([
                    $batchId, $definition['case_no'], $definition['case_code'], $definition['case_source'],
                    $definition['position_code'], $definition['criteria_year'], $definition['rule_code'],
                    $definition['difficulty'], self::json($definition['input']),
                    $definition['expected_decision'], $actual['decision'], $isCorrect ? 1 : 0,
                    self::json($definition['expected_failed_rules']), self::json($actual['failed_rules']),
                    self::json($explanation['evidence']), self::json($explanation),
                    self::json($counterfactual), round($elapsedMs, 4), $definition['case_hash'], $outputHash,
                ]);
                $simulationCaseId = (int)$pdo->lastInsertId();

                $scenario = $definition['scenario_type'];
                $coverageKey = $definition['rule_code'] . '|' . $scenario;
                $coverage[$coverageKey] ??= [
                    'rule_code' => $definition['rule_code'], 'scenario_type' => $scenario,
                    'generated' => 0, 'evaluated' => 0, 'passed' => 0,
                ];
                $coverage[$coverageKey]['generated']++;
                $coverage[$coverageKey]['evaluated']++;
                $coverage[$coverageKey]['passed'] += $isCorrect ? 1 : 0;

                if ($definition['boundary_type'] !== null) {
                    $insertBoundary->execute([
                        $batchId, $simulationCaseId, $definition['rule_code'], $definition['boundary_type'],
                        $definition['tested_field'], self::scalar($definition['input'][$definition['tested_field']] ?? null),
                        self::scalar($definition['boundary_value']), $definition['expected_decision'],
                        $actual['decision'], $isCorrect ? 1 : 0,
                        $isCorrect ? 'ผลตรงตาม boundary oracle' : 'ผลไม่ตรงตาม boundary oracle',
                    ]);
                }

                $fidelity = $actual['failed_rules'] === $definition['expected_failed_rules'] ? 100.0 : 0.0;
                [$precision, $recall] = self::setMetrics($explanation['evidence_rule_codes'], $actual['failed_rules']);
                $completeness = $explanation['decision'] === $actual['decision'] && isset($explanation['reason']) ? 100.0 : 50.0;
                $nonMisleading = $explanation['evidence_rule_codes'] === $actual['failed_rules'] ? 100.0 : 70.0;
                $overallExplanation = round(($fidelity + $precision + $recall + $completeness + $nonMisleading) / 5, 4);
                $explanationPassed = $overallExplanation >= 95;
                $insertExplanation->execute([
                    $batchId, $simulationCaseId, 'explanation-1.0',
                    $fidelity, $precision, $recall, $completeness, $nonMisleading,
                    $overallExplanation, $explanationPassed ? 1 : 0,
                    self::json(['automated' => true, 'expected_rules' => $definition['expected_failed_rules']]),
                    $explanationPassed ? 'ผ่านเกณฑ์อัตโนมัติ' : 'ควรให้ผู้เชี่ยวชาญทบทวน',
                ]);

                $target = $actual['decision'] === 'PASS' ? 'FAIL' : 'PASS';
                $changed = $counterfactual['changes'];
                $resulting = $counterfactual['resulting_decision'];
                $validity = $resulting === $target ? 100.0 : 0.0;
                $minimality = count($changed) <= 2 ? 100.0 : max(0, 100 - (count($changed) - 2) * 20);
                $actionability = $counterfactual['actionable'] ? 100.0 : 50.0;
                $consistency = self::engineEvaluate($counterfactual['modified_input'], $definition['rule'])['decision'] === $resulting ? 100.0 : 0.0;
                $overallCounterfactual = round(($validity + $minimality + $actionability + $consistency) / 4, 4);
                $counterfactualPassed = $overallCounterfactual >= 95;
                $insertCounterfactual->execute([
                    $batchId, $simulationCaseId, 'counterfactual-1.0', $target,
                    self::json($changed), count($changed), $resulting, $validity, $minimality,
                    $actionability, $consistency, $overallCounterfactual, $counterfactualPassed ? 1 : 0,
                    $counterfactualPassed ? null : 'counterfactual ไม่ผ่านเกณฑ์อย่างน้อยหนึ่งด้าน',
                ]);
            }

            $insertCoverage = $pdo->prepare(
                "INSERT INTO rule_coverage
                 (batch_id,rule_code,scenario_type,generated_count,evaluated_count,passed_count,failed_count,coverage_rate,uncovered_reason)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            );
            foreach ($coverage as $row) {
                $rate = $row['evaluated'] ? round($row['passed'] * 100 / $row['evaluated'], 4) : null;
                $insertCoverage->execute([
                    $batchId, $row['rule_code'], $row['scenario_type'], $row['generated'],
                    $row['evaluated'], $row['passed'], $row['evaluated'] - $row['passed'], $rate,
                    $row['generated'] ? null : 'ไม่สามารถสร้างสถานการณ์ได้',
                ]);
            }

            $batchHash = hash('sha256', implode('|', array_column($definitions, 'case_hash')));
            $update = $pdo->prepare(
                "UPDATE simulation_batches SET status='COMPLETED',generated_case_count=?,
                 passed_case_count=?,failed_case_count=?,batch_hash=?,completed_at=NOW(6),error_message=NULL
                 WHERE batch_id=?"
            );
            $update->execute([count($definitions), $passed, count($definitions) - $passed, $batchHash, $batchId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function rules(PDO $pdo, string $ruleVersion): array
    {
        $criteriaYear = preg_match('/(25\d{2})/', $ruleVersion, $m) ? $m[1] : null;
        $sql = "SELECT r.rule_id,r.criteria_year,r.method_id,r.work_type_group,r.min_quantity,
                r.min_first_author_quantity,r.author_role_min_level,r.requires_manual_review,
                COALESCE(q.code,'UNSPECIFIED') quality_code
                FROM academic_promotion_rules r
                LEFT JOIN academic_quality_levels q ON q.quality_level_id=r.min_quality_level_id";
        $params = [];
        if ($criteriaYear) {
            $sql .= ' WHERE r.criteria_year=?';
            $params[] = $criteriaYear;
        }
        $sql .= ' ORDER BY r.rule_id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (!$rows && $criteriaYear) {
            $rows = $pdo->query(
                "SELECT r.rule_id,r.criteria_year,r.method_id,r.work_type_group,r.min_quantity,
                 r.min_first_author_quantity,r.author_role_min_level,r.requires_manual_review,
                 COALESCE(q.code,'UNSPECIFIED') quality_code
                 FROM academic_promotion_rules r
                 LEFT JOIN academic_quality_levels q ON q.quality_level_id=r.min_quality_level_id
                 ORDER BY r.rule_id"
            )->fetchAll();
        }
        return array_map(static function (array $row): array {
            return [
                'rule_code' => 'APR-' . str_pad((string)$row['rule_id'], 3, '0', STR_PAD_LEFT),
                'criteria_year' => (string)$row['criteria_year'],
                'method_id' => (int)$row['method_id'],
                'work_type_group' => $row['work_type_group'] !== null ? (int)$row['work_type_group'] : null,
                'min_quantity' => max(1, (int)$row['min_quantity']),
                'min_first_author_quantity' => (int)$row['min_first_author_quantity'],
                'author_role_min_level' => (int)$row['author_role_min_level'],
                'quality_code' => (string)$row['quality_code'],
                'requires_manual_review' => (int)$row['requires_manual_review'],
            ];
        }, $rows);
    }

    private static function lockedRules(PDO $pdo, array $batch): array
    {
        $metadata = json_decode((string)($batch['distribution_json'] ?? ''), true);
        if (is_array($metadata) && !empty($metadata['rule_snapshot']) && is_array($metadata['rule_snapshot'])) {
            $snapshot = $metadata['rule_snapshot'];
            $expectedHash = (string)($metadata['rule_snapshot_hash'] ?? '');
            if ($expectedHash !== '' && !hash_equals($expectedHash, hash('sha256', self::json($snapshot)))) {
                throw new RuntimeException('rule snapshot ของชุดจำลองไม่ผ่านการตรวจสอบ hash');
            }
            return $snapshot;
        }
        return self::rules($pdo, (string)$batch['rule_version']);
    }

    private static function generateCaseDefinitions(array $rules, int $count, int $seed, string $ruleVersion, string $algorithmVersion): array
    {
        mt_srand($seed);
        $positions = ['ASST_PROF', 'ASSOC_PROF', 'PROF'];
        $boundaryTypes = ['BELOW', 'EXACT', 'ABOVE', 'MISSING', 'CONFLICT', 'IRRELEVANT_CHANGE'];
        $definitions = [];
        for ($i = 1; $i <= $count; $i++) {
            $rule = $rules[($i - 1) % count($rules)];
            $isBoundary = $i <= min($count, count($rules) * count($boundaryTypes));
            $boundaryType = $isBoundary ? $boundaryTypes[(int)(($i - 1) / count($rules)) % count($boundaryTypes)] : null;
            $scenario = $isBoundary
                ? match ($boundaryType) {
                    'MISSING' => 'MISSING',
                    'CONFLICT' => 'CONFLICT',
                    'IRRELEVANT_CHANGE' => 'IRRELEVANT_CHANGE',
                    'BELOW' => 'NEGATIVE',
                    default => 'POSITIVE',
                }
                : self::weightedScenario(mt_rand(1, 100));
            $input = self::makeInput($rule, $scenario, $boundaryType, $i);
            $oracle = self::oracleEvaluate($input, $rule);
            $caseSource = $isBoundary ? 'BOUNDARY' : 'GENERAL';
            $difficulty = $isBoundary ? 'BOUNDARY' : match (mt_rand(1, 100)) {
                1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25 => 'EASY',
                66,67,68,69,70,71,72,73,74,75,76,77,78,79,80,81,82,83,84,85,86,87,88,89,90,91,92,93,94,95,96,97,98,99,100 => 'HARD',
                default => 'MODERATE',
            };
            $casePayload = [
                'seed' => $seed, 'case_no' => $i, 'generator_version' => self::GENERATOR_VERSION,
                'rule_version' => $ruleVersion, 'algorithm_version' => $algorithmVersion,
                'rule' => $rule, 'input' => $input, 'oracle' => $oracle,
            ];
            $definitions[] = [
                'case_no' => $i,
                'case_code' => 'SIM-' . str_pad((string)$i, 6, '0', STR_PAD_LEFT),
                'case_source' => $caseSource,
                'position_code' => $positions[($i - 1) % count($positions)],
                'criteria_year' => $rule['criteria_year'],
                'rule_code' => $rule['rule_code'],
                'difficulty' => $difficulty,
                'scenario_type' => $scenario,
                'boundary_type' => $boundaryType,
                'tested_field' => $boundaryType === 'MISSING' ? 'missing_data' : 'eligible_work_count',
                'boundary_value' => $rule['min_quantity'],
                'input' => $input,
                'rule' => $rule,
                'expected_decision' => $oracle['decision'],
                'expected_failed_rules' => $oracle['failed_rules'],
                'case_hash' => hash('sha256', self::json($casePayload)),
            ];
        }
        return $definitions;
    }

    private static function makeInput(array $rule, string $scenario, ?string $boundaryType, int $caseNo): array
    {
        $min = $rule['min_quantity'];
        $minFirst = $rule['min_first_author_quantity'];
        $eligible = $min + mt_rand(0, 2);
        $first = $minFirst + mt_rand(0, 1);
        $missing = false;
        $conflict = false;
        if ($boundaryType) {
            switch ($boundaryType) {
                case 'BELOW': $eligible = max(0, $min - 1); break;
                case 'EXACT': $eligible = $min; break;
                case 'ABOVE': $eligible = $min + 1; break;
                case 'MISSING': $missing = true; break;
                case 'CONFLICT': $conflict = true; break;
                case 'IRRELEVANT_CHANGE': $eligible = $min; break;
            }
        } elseif ($scenario === 'NEGATIVE') {
            if ($minFirst > 0 && mt_rand(0, 1)) {
                $first = max(0, $minFirst - 1);
            } else {
                $eligible = max(0, $min - 1);
            }
        } elseif ($scenario === 'MISSING') {
            $missing = true;
        } elseif ($scenario === 'CONFLICT') {
            $conflict = true;
        }
        return [
            'eligible_work_count' => $eligible,
            'first_author_count' => $first,
            'quality_code' => $rule['quality_code'],
            'reviewer_votes_pass' => mt_rand(1, 3),
            'reviewer_total' => 3,
            'missing_data' => $missing,
            'conflicting_grade_and_score' => $conflict,
            'irrelevant_nonce' => $caseNo * 17,
        ];
    }

    private static function oracleEvaluate(array $input, array $rule): array
    {
        if (!empty($input['missing_data']) || !empty($input['conflicting_grade_and_score'])) {
            return ['decision' => 'INSUFFICIENT_DATA', 'failed_rules' => [$rule['rule_code'] . ':DATA_QUALITY']];
        }
        $failed = [];
        if ((int)$input['eligible_work_count'] < $rule['min_quantity']) {
            $failed[] = $rule['rule_code'] . ':MIN_QUANTITY';
        }
        if ((int)$input['first_author_count'] < $rule['min_first_author_quantity']) {
            $failed[] = $rule['rule_code'] . ':AUTHOR_ROLE';
        }
        sort($failed);
        return ['decision' => $failed ? 'FAIL' : 'PASS', 'failed_rules' => $failed];
    }

    private static function engineEvaluate(array $input, array $rule): array
    {
        $dataProblem = filter_var($input['missing_data'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || filter_var($input['conflicting_grade_and_score'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($dataProblem) {
            return ['decision' => 'INSUFFICIENT_DATA', 'failed_rules' => [$rule['rule_code'] . ':DATA_QUALITY']];
        }
        $violations = [];
        if (($input['eligible_work_count'] ?? 0) < $rule['min_quantity']) {
            $violations[] = $rule['rule_code'] . ':MIN_QUANTITY';
        }
        if (($input['first_author_count'] ?? 0) < $rule['min_first_author_quantity']) {
            $violations[] = $rule['rule_code'] . ':AUTHOR_ROLE';
        }
        sort($violations);
        return ['decision' => count($violations) ? 'FAIL' : 'PASS', 'failed_rules' => $violations];
    }

    private static function buildExplanation(array $result, array $input, array $rule): array
    {
        $reason = match ($result['decision']) {
            'PASS' => 'จำนวนและบทบาทผลงานครบตามเงื่อนไข',
            'FAIL' => 'ไม่ผ่านเงื่อนไขอย่างน้อยหนึ่งข้อ',
            default => 'ข้อมูลไม่เพียงพอหรือขัดแย้ง ต้องตรวจสอบก่อนตัดสิน',
        };
        return [
            'decision' => $result['decision'],
            'reason' => $reason,
            'rule_code' => $rule['rule_code'],
            'evidence_rule_codes' => $result['failed_rules'],
            'evidence' => [
                'eligible_work_count' => $input['eligible_work_count'],
                'required_work_count' => $rule['min_quantity'],
                'first_author_count' => $input['first_author_count'],
                'required_first_author_count' => $rule['min_first_author_quantity'],
            ],
        ];
    }

    private static function buildCounterfactual(array $result, array $input, array $rule): array
    {
        $modified = $input;
        $changes = [];
        $recommendedActions = [];
        if ($result['decision'] === 'PASS') {
            $newValue = max(0, $rule['min_quantity'] - 1);
            $changes['eligible_work_count'] = ['from' => $input['eligible_work_count'], 'to' => $newValue];
            $modified['eligible_work_count'] = $newValue;
        } else {
            if (!empty($modified['missing_data'])) {
                $changes['missing_data'] = ['from' => true, 'to' => false];
                $modified['missing_data'] = false;
                $recommendedActions[] = 'collect_and_verify_missing_evidence';
            }
            if (!empty($modified['conflicting_grade_and_score'])) {
                $changes['conflicting_grade_and_score'] = ['from' => true, 'to' => false];
                $modified['conflicting_grade_and_score'] = false;
                $recommendedActions[] = 'resolve_conflicting_source_evidence';
            }
            if ($modified['eligible_work_count'] < $rule['min_quantity']) {
                $changes['eligible_work_count'] = ['from' => $modified['eligible_work_count'], 'to' => $rule['min_quantity']];
                $modified['eligible_work_count'] = $rule['min_quantity'];
            }
            if ($modified['first_author_count'] < $rule['min_first_author_quantity']) {
                $changes['first_author_count'] = ['from' => $modified['first_author_count'], 'to' => $rule['min_first_author_quantity']];
                $modified['first_author_count'] = $rule['min_first_author_quantity'];
            }
        }
        $resulting = self::engineEvaluate($modified, $rule)['decision'];
        return [
            'changes' => $changes,
            'recommended_actions' => $recommendedActions,
            'modified_input' => $modified,
            'resulting_decision' => $resulting,
            'actionable' => $changes !== [],
        ];
    }

    private static function weightedScenario(int $roll): string
    {
        return match (true) {
            $roll <= 35 => 'POSITIVE',
            $roll <= 80 => 'NEGATIVE',
            $roll <= 95 => 'MISSING',
            default => 'CONFLICT',
        };
    }

    private static function setMetrics(array $predicted, array $actual): array
    {
        $predicted = array_values(array_unique($predicted));
        $actual = array_values(array_unique($actual));
        $tp = count(array_intersect($predicted, $actual));
        $precision = count($predicted) ? $tp * 100 / count($predicted) : (count($actual) ? 0 : 100);
        $recall = count($actual) ? $tp * 100 / count($actual) : (count($predicted) ? 0 : 100);
        return [round($precision, 4), round($recall, 4)];
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function scalar(mixed $value): ?string
    {
        if ($value === null) return null;
        if (is_bool($value)) return $value ? '1' : '0';
        return is_scalar($value) ? (string)$value : self::json($value);
    }
}
