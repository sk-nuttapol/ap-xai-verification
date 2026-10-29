<?php
declare(strict_types=1);

/**
 * Independent test oracle for research validation.
 *
 * This implementation deliberately does not call ResearchSimulation's engine,
 * explanation, or counterfactual methods. It converts inputs into boolean facts
 * and resolves the outcome through an explicit, versioned decision table.
 */
final class ResearchIndependentOracle
{
    public const VERSION = 'independent-decision-table-oracle-1.0.0';

    private const DECISION_TABLE = [
        'DATA_PROBLEM' => 'INSUFFICIENT_DATA',
        'QUANTITY_FAILED' => 'FAIL',
        'AUTHOR_ROLE_FAILED' => 'FAIL',
        'ALL_REQUIREMENTS_MET' => 'PASS',
    ];

    public static function evaluate(array $input, array $rule): array
    {
        self::validateRule($rule);
        $facts = [
            'missing_data' => self::boolean($input['missing_data'] ?? false),
            'conflicting_data' => self::boolean($input['conflicting_grade_and_score'] ?? false),
            'quantity_met' => (int)($input['eligible_work_count'] ?? 0) >= (int)$rule['min_quantity'],
            'author_role_met' => (int)($input['first_author_count'] ?? 0) >= (int)$rule['min_first_author_quantity'],
        ];

        if ($facts['missing_data'] || $facts['conflicting_data']) {
            $row = 'DATA_PROBLEM';
            $failed = [(string)$rule['rule_code'] . ':DATA_QUALITY'];
        } else {
            $failed = [];
            if (!$facts['quantity_met']) {
                $failed[] = (string)$rule['rule_code'] . ':MIN_QUANTITY';
            }
            if (!$facts['author_role_met']) {
                $failed[] = (string)$rule['rule_code'] . ':AUTHOR_ROLE';
            }
            sort($failed);
            $row = !$facts['quantity_met']
                ? 'QUANTITY_FAILED'
                : (!$facts['author_role_met'] ? 'AUTHOR_ROLE_FAILED' : 'ALL_REQUIREMENTS_MET');
        }

        return [
            'decision' => self::DECISION_TABLE[$row],
            'failed_rules' => $failed,
            'decision_table_row' => $row,
            'facts' => $facts,
            'oracle_version' => self::VERSION,
        ];
    }

    public static function schema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://example.invalid/research/independent-oracle-input-1.0.0.schema.json',
            'title' => 'Independent oracle input',
            'type' => 'object',
            'required' => [
                'eligible_work_count', 'first_author_count', 'missing_data',
                'conflicting_grade_and_score',
            ],
            'properties' => [
                'eligible_work_count' => ['type' => 'integer', 'minimum' => 0],
                'first_author_count' => ['type' => 'integer', 'minimum' => 0],
                'quality_code' => ['type' => 'string'],
                'reviewer_votes_pass' => ['type' => 'integer', 'minimum' => 0],
                'reviewer_total' => ['type' => 'integer', 'minimum' => 0],
                'missing_data' => ['type' => 'boolean'],
                'conflicting_grade_and_score' => ['type' => 'boolean'],
                'irrelevant_nonce' => ['type' => 'integer'],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function decisionTable(): array
    {
        return self::DECISION_TABLE;
    }

    private static function validateRule(array $rule): void
    {
        foreach (['rule_code', 'min_quantity', 'min_first_author_quantity'] as $field) {
            if (!array_key_exists($field, $rule)) {
                throw new InvalidArgumentException('oracle rule is missing ' . $field);
            }
        }
    }

    private static function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
