<?php

namespace App\Services\Panorama\Utils;

class DocumentHelper
{
    /**
     * Generate unique rule identifier
     *
     * @param string $deviceGroup Device group name
     * @param string $rulebase Rulebase type (pre-rules, local-rules, post-rules)
     * @param int $position Rule position within rulebase
     * @param string $ruleName Rule name
     * @return string Unique rule UID
     */
    public static function generateRuleUid(string $deviceGroup, string $rulebase, int $position, string $ruleName): string
    {
        return sprintf('%s:%s:%d:%s', $deviceGroup, $rulebase, $position, $ruleName);
    }

    /**
     * Sanitize field value for Elasticsearch
     *
     * @param mixed $value Value to sanitize
     * @return mixed Sanitized value
     */
    public static function sanitizeValue($value)
    {
        if (is_string($value)) {
            // Remove null bytes and control characters except newlines and tabs
            return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        }

        if (is_array($value)) {
            return array_map([self::class, 'sanitizeValue'], $value);
        }

        return $value;
    }

    /**
     * Format document for NDJSON output
     *
     * @param array $document Document data
     * @return string JSON-encoded document
     */
    public static function formatAsNdjson(array $document): string
    {
        $sanitized = self::sanitizeValue($document);
        return json_encode($sanitized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * Create base document structure
     *
     * @param string $tenant Tenant identifier
     * @param string $snapshotDate Snapshot date
     * @param string $deviceGroup Device group name
     * @param array $deviceGroupPath Device group inheritance path
     * @param string $rulebase Rulebase type
     * @param string $ruleName Rule name
     * @param int $position Rule position
     * @return array Base document structure
     */
    public static function createBaseDocument(
        string $tenant,
        string $snapshotDate,
        string $deviceGroup,
        array $deviceGroupPath,
        string $rulebase,
        string $ruleName,
        int $position
    ): array {
        return [
            'panorama_tenant' => $tenant,
            'snapshot_date' => $snapshotDate,
            'device_group' => $deviceGroup,
            'device_group_path' => $deviceGroupPath,
            'rulebase' => $rulebase,
            'rule_name' => $ruleName,
            'rule_uid' => self::generateRuleUid($deviceGroup, $rulebase, $position, $ruleName),
            'position' => $position,
            'action' => 'allow',
            'disabled' => false,
            'targets' => [
                'include' => [],
                'exclude' => [],
            ],
            'orig' => [],
            'expanded' => [],
            'meta' => [
                'has_dynamic_groups' => false,
                'dynamic_groups_unresolved' => [],
                'unresolved_notes' => '',
            ],
        ];
    }
}