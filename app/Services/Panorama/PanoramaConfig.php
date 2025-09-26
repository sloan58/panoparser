<?php

namespace App\Services\Panorama;

class PanoramaConfig
{
    /**
     * XML parsing options for large files
     */
    public const XML_PARSE_OPTIONS = LIBXML_COMPACT | LIBXML_PARSEHUGE | LIBXML_NOCDATA;

    /**
     * Maximum memory limit for XML processing (in bytes)
     */
    public const MAX_MEMORY_LIMIT = 512 * 1024 * 1024; // 512MB

    /**
     * Supported object types in Panorama configuration
     */
    public const OBJECT_TYPES = [
        'address' => 'address',
        'address-group' => 'address-group',
        'service' => 'service',
        'service-group' => 'service-group',
        'application' => 'application',
        'application-group' => 'application-group',
    ];

    /**
     * Rule types and their processing order
     */
    public const RULE_TYPES = [
        'pre-rulebase' => 'pre-rules',
        'rulebase' => 'local-rules',
        'post-rulebase' => 'post-rules',
    ];

    /**
     * Default values for rule processing
     */
    public const DEFAULTS = [
        'action' => 'allow',
        'disabled' => false,
        'from_zones' => ['any'],
        'to_zones' => ['any'],
        'sources' => ['any'],
        'destinations' => ['any'],
        'applications' => ['any'],
        'services' => ['application-default'],
        'users' => ['any'],
    ];

    /**
     * Markers for special object types
     */
    public const MARKERS = [
        'unknown' => 'UNKNOWN:',
        'dynamic_group' => 'DAG:',
        'unresolved' => 'UNRESOLVED:',
    ];

    /**
     * Elasticsearch document field mappings
     */
    public const ES_FIELD_TYPES = [
        'panorama_tenant' => 'keyword',
        'snapshot_date' => 'date',
        'device_group' => 'keyword',
        'device_group_path' => 'keyword',
        'rulebase' => 'keyword',
        'rule_name' => 'keyword',
        'rule_uid' => 'keyword',
        'position' => 'integer',
        'action' => 'keyword',
        'disabled' => 'boolean',
    ];
}