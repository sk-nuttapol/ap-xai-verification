<?php
declare(strict_types=1);

final class ResearchBaselineComparator
{
    public const VERSION = 'baseline-comparator-1.0.0';

    /** A transparent decision-table baseline with no explanation output. */
    public static function decisionTable(array $input, array $rule): array
    {
        $oracle = ResearchIndependentOracle::evaluate($input, $rule);
        return [
            'decision' => $oracle['decision'],
            'failed_rules' => $oracle['failed_rules'],
            'explanation' => null,
            'counterfactual' => null,
            'baseline' => 'DECISION_TABLE',
        ];
    }

    /** An opaque rule baseline: prediction only, with no reasons or recourse. */
    public static function opaqueRuleEngine(array $input, array $rule): array
    {
        if (self::bool($input['missing_data'] ?? false)
            || self::bool($input['conflicting_grade_and_score'] ?? false)) {
            $decision = 'INSUFFICIENT_DATA';
        } elseif ((int)($input['eligible_work_count'] ?? 0) < (int)$rule['min_quantity']
            || (int)($input['first_author_count'] ?? 0) < (int)$rule['min_first_author_quantity']) {
            $decision = 'FAIL';
        } else {
            $decision = 'PASS';
        }
        return [
            'decision' => $decision,
            'failed_rules' => [],
            'explanation' => null,
            'counterfactual' => null,
            'baseline' => 'OPAQUE_RULE_ENGINE',
        ];
    }

    private static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
