<?php

namespace App\Services\Panorama;

use App\Services\Panorama\Contracts\RuleEmitterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SimpleXMLElement;

class RuleEmitter implements RuleEmitterInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private string $tenant,
        private string $snapshotDate,
        private Dereferencer $dereferencer,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Process security rules and emit structured NDJSON documents
     */
    public function emitSecurityRulesAsNdjson(SimpleXMLElement $root, $stream): int
    {
        $rulesProcessed = 0;
        $deviceGroupsProcessed = 0;
        $deviceGroupsFailed = 0;
        
        $this->logger->info('Starting rule emission process', [
            'tenant' => $this->tenant,
            'snapshot_date' => $this->snapshotDate
        ]);
        
        try {
            // Process device groups and their security rules - try both formats
            $deviceGroups = $root->xpath('//device-group');
            
            // If no direct device-group elements, try nested format
            if (empty($deviceGroups)) {
                $deviceGroups = $root->xpath('//devices/entry/device-group/entry');
                $this->logger->debug('Using nested device group format for rule processing');
            } else {
                // Check if these are container elements (no name attribute)
                $hasNamedElements = false;
                foreach ($deviceGroups as $dg) {
                    if (isset($dg['name'])) {
                        $hasNamedElements = true;
                        break;
                    }
                }
                
                // If no named elements, use the nested format
                if (!$hasNamedElements) {
                    $deviceGroups = $root->xpath('//devices/entry/device-group/entry');
                    $this->logger->debug('Using nested device group format for rule processing (container elements found)');
                }
            }
            
            if (empty($deviceGroups)) {
                $this->logger->warning('No device groups found for rule processing');
                return 0;
            }
            
            $this->logger->debug('Found device groups for rule processing', [
                'count' => count($deviceGroups)
            ]);
            
            foreach ($deviceGroups as $deviceGroup) {
                try {
                    $deviceGroupName = (string) $deviceGroup['name'];
                    
                    if (empty($deviceGroupName)) {
                        $deviceGroupsFailed++;
                        $this->logger->warning('Device group element missing name attribute');
                        continue;
                    }
                    
                    $this->logger->debug('Processing rules for device group', [
                        'device_group' => $deviceGroupName
                    ]);
                    
                    $dgRulesProcessed = 0;
                    
                    // Process pre-rules
                    $preRules = $deviceGroup->xpath('.//pre-rulebase/security/rules/entry');
                    $dgRulesProcessed += $this->processRules($preRules, $deviceGroupName, 'pre-rules', $stream);
                    
                    // Process local rules
                    $localRules = $deviceGroup->xpath('.//rulebase/security/rules/entry');
                    $dgRulesProcessed += $this->processRules($localRules, $deviceGroupName, 'rules', $stream);
                    
                    // Process post-rules
                    $postRules = $deviceGroup->xpath('.//post-rulebase/security/rules/entry');
                    $dgRulesProcessed += $this->processRules($postRules, $deviceGroupName, 'post-rules', $stream);
                    
                    $rulesProcessed += $dgRulesProcessed;
                    $deviceGroupsProcessed++;
                    
                    $this->logger->debug('Device group rules processed', [
                        'device_group' => $deviceGroupName,
                        'rules_count' => $dgRulesProcessed
                    ]);
                    
                } catch (\Exception $e) {
                    $deviceGroupsFailed++;
                    $this->logger->error('Failed to process device group rules', [
                        'device_group' => $deviceGroupName ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                    // Continue processing other device groups
                }
            }
            
            $this->logger->info('Rule emission completed', [
                'rules_processed' => $rulesProcessed,
                'device_groups_processed' => $deviceGroupsProcessed,
                'device_groups_failed' => $deviceGroupsFailed
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Critical error during rule emission', [
                'error' => $e->getMessage(),
                'rules_processed' => $rulesProcessed
            ]);
            // Don't throw - return what we have processed so far
        }
        
        return $rulesProcessed;
    }

    /**
     * Process a collection of rules and emit documents
     */
    private function processRules(array $rules, string $deviceGroupName, string $rulebase, $stream): int
    {
        $position = 1;
        $processed = 0;
        $failed = 0;
        
        if (empty($rules)) {
            $this->logger->debug('No rules found for rulebase', [
                'device_group' => $deviceGroupName,
                'rulebase' => $rulebase
            ]);
            return 0;
        }
        
        $this->logger->debug('Processing rules for rulebase', [
            'device_group' => $deviceGroupName,
            'rulebase' => $rulebase,
            'rule_count' => count($rules)
        ]);
        
        foreach ($rules as $rule) {
            try {
                $document = $this->createRuleDocument($rule, $deviceGroupName, $rulebase, $position);
                
                if ($document !== null) {
                    // Write NDJSON line
                    $jsonLine = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if ($jsonLine === false) {
                        $failed++;
                        $this->logger->error('Failed to encode rule document as JSON', [
                            'device_group' => $deviceGroupName,
                            'rulebase' => $rulebase,
                            'position' => $position,
                            'rule_name' => $document['rule_name'] ?? 'unknown'
                        ]);
                    } else {
                        fwrite($stream, $jsonLine . "\n");
                        $processed++;
                    }
                } else {
                    $failed++;
                }
                
            } catch (\Exception $e) {
                $failed++;
                $this->logger->error('Failed to process rule', [
                    'device_group' => $deviceGroupName,
                    'rulebase' => $rulebase,
                    'position' => $position,
                    'error' => $e->getMessage()
                ]);
                // Continue processing other rules
            }
            
            $position++;
        }
        
        if ($failed > 0) {
            $this->logger->warning('Some rules failed to process', [
                'device_group' => $deviceGroupName,
                'rulebase' => $rulebase,
                'processed' => $processed,
                'failed' => $failed
            ]);
        }
        
        return $processed;
    }

    /**
     * Create a complete Elasticsearch document for a security rule
     */
    private function createRuleDocument(SimpleXMLElement $rule, string $deviceGroupName, string $rulebase, int $position): ?array
    {
        try {
            $ruleName = (string) $rule['name'];
            
            if (empty($ruleName)) {
                $this->logger->warning('Rule element missing name attribute', [
                    'device_group' => $deviceGroupName,
                    'rulebase' => $rulebase,
                    'position' => $position
                ]);
                return null;
            }
            
            $ruleUid = $this->generateRuleUid($deviceGroupName, $rulebase, $position, $ruleName);
            
            // Get device group path from dereferencer catalog
            $deviceGroupPath = $this->dereferencer->catalog['deviceGroups'][$deviceGroupName]['path'] ?? [$deviceGroupName];
            
            // Extract rule metadata with error handling
            $action = $this->extractRuleAction($rule);
            $disabled = $this->extractRuleDisabled($rule);
            
            // Extract rule targets with error handling
            $targets = $this->extractTargets($rule);
            
            // Extract original rule elements with error handling
            $orig = $this->extractOriginalElements($rule);
            
            // Create expanded elements using dereferencer with error handling
            $expanded = $this->createExpandedElements($orig, $deviceGroupName);
            
            // Create metadata about dynamic groups and unresolved references
            $meta = $this->createMetadata($expanded);
            
            return [
                'panorama_tenant' => $this->tenant,
                'snapshot_date' => $this->snapshotDate,
                'device_group' => $deviceGroupName,
                'device_group_path' => $deviceGroupPath,
                'rulebase' => $rulebase,
                'rule_name' => $ruleName,
                'rule_uid' => $ruleUid,
                'position' => $position,
                'action' => $action,
                'disabled' => $disabled,
                'targets' => $targets,
                'orig' => $orig,
                'expanded' => $expanded,
                'meta' => $meta
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to create rule document', [
                'device_group' => $deviceGroupName,
                'rulebase' => $rulebase,
                'position' => $position,
                'rule_name' => $ruleName ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Generate unique rule UID combining device group, rulebase, position, and name
     */
    public function generateRuleUid(string $deviceGroup, string $rulebase, int $position, string $ruleName): string
    {
        // Sanitize components to ensure valid UID format
        $sanitizedDeviceGroup = $this->sanitizeUidComponent($deviceGroup);
        $sanitizedRulebase = $this->sanitizeUidComponent($rulebase);
        $sanitizedRuleName = $this->sanitizeUidComponent($ruleName);
        
        return "{$sanitizedDeviceGroup}:{$sanitizedRulebase}:{$position}:{$sanitizedRuleName}";
    }

    /**
     * Sanitize UID component to remove problematic characters
     */
    private function sanitizeUidComponent(string $component): string
    {
        // Replace spaces and special characters with underscores, preserve alphanumeric and basic punctuation
        return preg_replace('/[^a-zA-Z0-9\-_.]/', '_', $component);
    }

    /**
     * Extract rule targets (included/excluded devices)
     */
    private function extractTargets(SimpleXMLElement $rule): array
    {
        $targets = [
            'include' => [],
            'exclude' => []
        ];
        
        try {
            $target = $rule->target;
            if ($target) {
                // Extract included devices
                $devices = $target->devices;
                if ($devices) {
                    foreach ($devices->entry as $device) {
                        $deviceName = (string) $device['name'];
                        if (!empty($deviceName)) {
                            $targets['include'][] = $deviceName;
                        }
                    }
                }
                
                // Extract excluded devices (if supported in XML structure)
                $excludedDevices = $target->{'excluded-devices'};
                if ($excludedDevices) {
                    foreach ($excludedDevices->entry as $device) {
                        $deviceName = (string) $device['name'];
                        if (!empty($deviceName)) {
                            $targets['exclude'][] = $deviceName;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to extract rule targets', [
                'rule_name' => (string) $rule['name'],
                'error' => $e->getMessage()
            ]);
        }
        
        return $targets;
    }

    /**
     * Extract original rule elements as they appear in XML
     */
    private function extractOriginalElements(SimpleXMLElement $rule): array
    {
        return [
            'from_zones' => $this->extractMembers($rule->from),
            'to_zones' => $this->extractMembers($rule->to),
            'sources' => $this->extractMembers($rule->source),
            'destinations' => $this->extractMembers($rule->destination),
            'applications' => $this->extractMembers($rule->application),
            'services' => $this->extractMembers($rule->service),
            'users' => $this->extractMembers($rule->{'source-user'}),
            'tags' => $this->extractMembers($rule->tag),
            'profiles' => $this->extractProfiles($rule),
            'comments' => (string) ($rule->description ?? '')
        ];
    }

    /**
     * Extract member list from XML element
     */
    private function extractMembers(?SimpleXMLElement $element): array
    {
        if (!$element) {
            return [];
        }
        
        $members = [];
        foreach ($element->member as $member) {
            $members[] = (string) $member;
        }
        
        return $members;
    }

    /**
     * Extract security profiles information
     */
    private function extractProfiles(SimpleXMLElement $rule): array
    {
        $profiles = [
            'group' => null,
            'names' => []
        ];
        
        $profileSetting = $rule->{'profile-setting'};
        if ($profileSetting) {
            if ($profileSetting->group) {
                $profiles['group'] = (string) $profileSetting->group->member;
            }
            
            if ($profileSetting->profiles) {
                foreach ($profileSetting->profiles->children() as $profileType => $profileConfig) {
                    if ($profileConfig->member) {
                        foreach ($profileConfig->member as $member) {
                            $profiles['names'][] = (string) $member;
                        }
                    }
                }
            }
        }
        
        return $profiles;
    }

    /**
     * Create expanded elements using the dereferencer
     */
    private function createExpandedElements(array $orig, string $deviceGroupName): array
    {
        // Expand addresses
        $srcAddresses = $this->dereferencer->expandAddresses($deviceGroupName, $orig['sources']);
        $dstAddresses = $this->dereferencer->expandAddresses($deviceGroupName, $orig['destinations']);
        
        // Expand services
        $services = $this->dereferencer->expandServices($deviceGroupName, $orig['services']);
        
        // Extract ports from services
        $ports = $this->extractPortsFromServices($services);
        
        // Expand applications
        $applications = $this->dereferencer->expandApplications($deviceGroupName, $orig['applications']);
        
        // Expand zones
        $fromZones = $this->dereferencer->zonesFor($deviceGroupName, $orig['from_zones']);
        $toZones = $this->dereferencer->zonesFor($deviceGroupName, $orig['to_zones']);
        
        return [
            'from_zones' => $fromZones,
            'to_zones' => $toZones,
            'src_addresses' => $srcAddresses,
            'dst_addresses' => $dstAddresses,
            'applications' => $applications,
            'services' => $services,
            'ports' => $ports,
            'users' => $orig['users'], // Users typically don't need expansion
            'tags' => $orig['tags'] // Tags typically don't need expansion
        ];
    }

    /**
     * Extract port information from expanded services
     */
    private function extractPortsFromServices(array $services): array
    {
        $ports = [];
        
        foreach ($services as $service) {
            if (is_array($service) && isset($service['ports'])) {
                $ports = array_merge($ports, $service['ports']);
            } elseif (is_string($service)) {
                // Handle formatted service strings like "tcp/80" or "tcp/443"
                if (preg_match('/^(tcp|udp)\/(.+)$/', $service, $matches)) {
                    $ports[] = $matches[2];
                }
            }
        }
        
        return array_unique($ports);
    }

    /**
     * Create metadata about dynamic groups and unresolved references
     */
    private function createMetadata(array $expanded): array
    {
        $dynamicGroups = [];
        $unresolvedReferences = [];
        
        // Check all expanded fields for dynamic groups and unresolved references
        $fieldsToCheck = [
            'from_zones', 'to_zones', 'src_addresses', 'dst_addresses', 
            'applications', 'services', 'users', 'tags'
        ];
        
        foreach ($fieldsToCheck as $field) {
            if (!isset($expanded[$field])) {
                continue;
            }
            
            foreach ($expanded[$field] as $item) {
                if (!is_string($item)) {
                    continue;
                }
                
                // Track dynamic address groups (marked with DAG: prefix)
                if (str_starts_with($item, 'DAG:')) {
                    $dynamicGroups[] = $item;
                }
                
                // Track unresolved references (marked with UNKNOWN: prefix)
                if (str_starts_with($item, 'UNKNOWN:')) {
                    $unresolvedReferences[] = $item;
                }
                
                // Track cycle references (marked with CYCLE: prefix)
                if (str_starts_with($item, 'CYCLE:')) {
                    $unresolvedReferences[] = $item;
                }
            }
        }
        
        // Create notes for unresolved references
        $notes = [];
        foreach (array_unique($unresolvedReferences) as $unresolved) {
            $notes[] = "Unresolved reference: {$unresolved}";
        }
        
        return [
            'has_dynamic_groups' => !empty($dynamicGroups),
            'dynamic_groups_unresolved' => array_unique($dynamicGroups),
            'unresolved_notes' => implode('; ', $notes)
        ];
    }

    /**
     * Parse boolean values from XML
     */
    private function parseBoolean(?string $value): bool
    {
        return in_array(strtolower($value ?? ''), ['yes', 'true', '1']);
    }

    /**
     * Extract rule action with error handling
     */
    private function extractRuleAction(SimpleXMLElement $rule): string
    {
        try {
            $action = (string) ($rule->action ?? 'allow');
            return !empty($action) ? $action : 'allow';
        } catch (\Exception $e) {
            $this->logger->warning('Failed to extract rule action, using default', [
                'rule_name' => (string) $rule['name'],
                'error' => $e->getMessage()
            ]);
            return 'allow';
        }
    }

    /**
     * Extract rule disabled status with error handling
     */
    private function extractRuleDisabled(SimpleXMLElement $rule): bool
    {
        try {
            return $this->parseBoolean($rule->disabled ?? 'no');
        } catch (\Exception $e) {
            $this->logger->warning('Failed to extract rule disabled status, using default', [
                'rule_name' => (string) $rule['name'],
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}