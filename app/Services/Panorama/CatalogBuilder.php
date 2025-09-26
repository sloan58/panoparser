<?php

namespace App\Services\Panorama;

use App\Services\Panorama\Contracts\CatalogBuilderInterface;
use App\Services\Panorama\Exceptions\PanoramaException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SimpleXMLElement;

class CatalogBuilder implements CatalogBuilderInterface
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }
    /**
     * Build comprehensive lookup catalogs from XML configuration
     *
     * @param SimpleXMLElement $root Root XML element
     * @return array Catalog structure with device groups, objects, and zones
     */
    public function build(SimpleXMLElement $root): array
    {
        $this->logger->info('Starting catalog building process');
        
        $catalog = [
            'deviceGroups' => [],
            'objects' => [],
            'zones' => []
        ];

        try {
            // Build device groups hierarchy first as it's needed for other catalogs
            $this->logger->debug('Building device groups hierarchy');
            $catalog['deviceGroups'] = $this->buildDeviceGroups($root);
            $this->logger->info('Device groups built', ['count' => count($catalog['deviceGroups'])]);
            
            // Build objects catalog with device group context
            $this->logger->debug('Building objects catalog');
            $catalog['objects'] = $this->buildObjects($root, $catalog['deviceGroups']);
            $objectCount = $this->countTotalObjects($catalog['objects']);
            $this->logger->info('Objects catalog built', ['total_objects' => $objectCount]);
            
            // Build zones catalog with device group associations
            $this->logger->debug('Building zones catalog');
            $catalog['zones'] = $this->buildZones($root, $catalog['deviceGroups']);
            $this->logger->info('Zones catalog built', ['count' => count($catalog['zones'])]);
            
            $this->logger->info('Catalog building completed successfully');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to build catalog', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new PanoramaException(
                'Failed to build catalog: ' . $e->getMessage(),
                0,
                $e,
                ['catalog_state' => $catalog]
            );
        }

        return $catalog;
    }

    /**
     * Build device group hierarchy with parent-child relationships and inheritance paths
     *
     * @param SimpleXMLElement $root Root XML element
     * @return array Device groups catalog with hierarchy information
     */
    private function buildDeviceGroups(SimpleXMLElement $root): array
    {
        $deviceGroups = [];
        $malformedCount = 0;
        
        try {
            // Find device groups in the XML structure - try both formats
            $dgElements = $root->xpath('//device-group');
            
            // If no direct device-group elements, try nested format
            if (empty($dgElements)) {
                $dgElements = $root->xpath('//devices/entry/device-group/entry');
                $this->logger->debug('Using nested device group format');
            } else {
                // Check if these are container elements (no name attribute)
                $hasNamedElements = false;
                foreach ($dgElements as $dg) {
                    if (isset($dg['name'])) {
                        $hasNamedElements = true;
                        break;
                    }
                }
                
                // If no named elements, use the nested format
                if (!$hasNamedElements) {
                    $dgElements = $root->xpath('//devices/entry/device-group/entry');
                    $this->logger->debug('Using nested device group format (container elements found)');
                }
            }
            
            if (empty($dgElements)) {
                $this->logger->warning('No device groups found in XML configuration');
                return $deviceGroups;
            }

            $this->logger->debug('Found device group elements', ['count' => count($dgElements)]);

            // First pass: collect all device groups with their basic information
            foreach ($dgElements as $index => $dgElement) {
                try {
                    $name = (string) $dgElement['name'];
                    
                    if (empty($name)) {
                        $malformedCount++;
                        $this->logger->warning('Device group element missing name attribute', ['index' => $index]);
                        continue;
                    }

                    $deviceGroups[$name] = [
                        'name' => $name,
                        'parent' => null,
                        'children' => [],
                        'path' => []
                    ];

                    // Check for parent reference with error handling
                    try {
                        $parentElements = $dgElement->xpath('parent');
                        if (!empty($parentElements)) {
                            $parentName = (string) $parentElements[0];
                            if (!empty($parentName)) {
                                $deviceGroups[$name]['parent'] = $parentName;
                                $this->logger->debug('Device group parent relationship found', [
                                    'child' => $name,
                                    'parent' => $parentName
                                ]);
                            }
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed to parse parent for device group', [
                            'device_group' => $name,
                            'error' => $e->getMessage()
                        ]);
                        // Continue processing without parent relationship
                    }
                    
                } catch (\Exception $e) {
                    $malformedCount++;
                    $this->logger->warning('Failed to process device group element', [
                        'index' => $index,
                        'error' => $e->getMessage()
                    ]);
                    // Continue processing other device groups
                }
            }

            if ($malformedCount > 0) {
                $this->logger->warning('Encountered malformed device group elements', [
                    'malformed_count' => $malformedCount,
                    'processed_count' => count($deviceGroups)
                ]);
            }

            // Second pass: build parent-child relationships and detect issues
            $this->buildParentChildRelationships($deviceGroups);

            // Third pass: compute inheritance paths
            $this->computeInheritancePaths($deviceGroups);

        } catch (\Exception $e) {
            $this->logger->error('Critical error building device groups', [
                'error' => $e->getMessage(),
                'processed_count' => count($deviceGroups)
            ]);
            // Don't throw - return what we have processed so far
        }

        return $deviceGroups;
    }

    /**
     * Build parent-child relationships and handle missing parents
     *
     * @param array &$deviceGroups Device groups array to modify
     * @return void
     */
    private function buildParentChildRelationships(array &$deviceGroups): void
    {
        $missingParents = [];
        $relationshipsBuilt = 0;
        
        foreach ($deviceGroups as $name => &$dg) {
            $parentName = $dg['parent'];
            
            if ($parentName === null) {
                continue;
            }

            // Check if parent exists
            if (!isset($deviceGroups[$parentName])) {
                // Handle missing parent - log warning but continue
                $missingParents[] = ['child' => $name, 'parent' => $parentName];
                $this->logger->warning('Device group references missing parent', [
                    'child' => $name,
                    'parent' => $parentName
                ]);
                $dg['parent'] = null; // Remove invalid parent reference
                continue;
            }

            // Add this device group as a child of its parent
            if (!in_array($name, $deviceGroups[$parentName]['children'])) {
                $deviceGroups[$parentName]['children'][] = $name;
                $relationshipsBuilt++;
            }
        }
        
        if (!empty($missingParents)) {
            $this->logger->warning('Found device groups with missing parents', [
                'missing_parents' => $missingParents,
                'count' => count($missingParents)
            ]);
        }
        
        $this->logger->debug('Parent-child relationships built', [
            'relationships_count' => $relationshipsBuilt,
            'missing_parents_count' => count($missingParents)
        ]);
    }

    /**
     * Compute inheritance paths for all device groups with cycle detection
     *
     * @param array &$deviceGroups Device groups array to modify
     * @return void
     */
    private function computeInheritancePaths(array &$deviceGroups): void
    {
        foreach ($deviceGroups as $name => &$dg) {
            if (empty($dg['path'])) {
                $dg['path'] = $this->computeInheritancePath($name, $deviceGroups, []);
            }
        }
    }

    /**
     * Compute inheritance path for a specific device group with cycle detection
     *
     * @param string $dgName Device group name
     * @param array $deviceGroups All device groups
     * @param array $visited Currently visited nodes for cycle detection
     * @return array Inheritance path from current DG to root
     */
    private function computeInheritancePath(string $dgName, array $deviceGroups, array $visited): array
    {
        // Check for circular reference
        if (in_array($dgName, $visited)) {
            $this->logger->warning('Circular reference detected in device group hierarchy', [
                'device_group' => $dgName,
                'visited_path' => $visited
            ]);
            return [$dgName]; // Break the cycle by returning just this node
        }

        if (!isset($deviceGroups[$dgName])) {
            $this->logger->warning('Device group not found during path computation', [
                'device_group' => $dgName
            ]);
            return [];
        }

        $dg = $deviceGroups[$dgName];
        $visited[] = $dgName;

        // If no parent, this is the end of the path
        if ($dg['parent'] === null) {
            return [$dgName];
        }

        // Recursively build path from parent
        $parentPath = $this->computeInheritancePath($dg['parent'], $deviceGroups, $visited);
        
        // Add current device group to the end of parent's path
        return array_merge($parentPath, [$dgName]);
    }

    /**
     * Build objects catalog for addresses, services, and applications
     *
     * @param SimpleXMLElement $root Root XML element
     * @param array $deviceGroups Device groups for scoping
     * @return array Objects catalog organized by scope and type
     */
    private function buildObjects(SimpleXMLElement $root, array $deviceGroups): array
    {
        $objects = [];
        $scopesProcessed = 0;
        $scopesFailed = 0;

        try {
            // Build shared objects first
            $sharedConfig = $root->xpath('//shared')[0] ?? null;
            if ($sharedConfig) {
                try {
                    $this->logger->debug('Building shared objects');
                    $objects['Shared'] = $this->buildObjectsForScope($sharedConfig, 'Shared');
                    $scopesProcessed++;
                    $this->logger->debug('Shared objects built successfully', [
                        'object_count' => $this->countObjectsInScope($objects['Shared'])
                    ]);
                } catch (\Exception $e) {
                    $scopesFailed++;
                    $this->logger->error('Failed to build shared objects', [
                        'error' => $e->getMessage()
                    ]);
                    // Continue processing device groups even if shared fails
                    $objects['Shared'] = $this->getEmptyObjectsStructure();
                }
            } else {
                $this->logger->info('No shared configuration found');
                $objects['Shared'] = $this->getEmptyObjectsStructure();
            }

            // Build objects for each device group
            foreach ($deviceGroups as $dgName => $dgInfo) {
                try {
                    // Try direct format first
                    $dgElements = $root->xpath("//device-group[@name='{$dgName}']");
                    
                    // If not found, try nested format
                    if (empty($dgElements)) {
                        $dgElements = $root->xpath("//devices/entry/device-group/entry[@name='{$dgName}']");
                    }
                    
                    if (!empty($dgElements)) {
                        $this->logger->debug('Building objects for device group', ['device_group' => $dgName]);
                        $objects[$dgName] = $this->buildObjectsForScope($dgElements[0], $dgName);
                        $scopesProcessed++;
                        $this->logger->debug('Device group objects built successfully', [
                            'device_group' => $dgName,
                            'object_count' => $this->countObjectsInScope($objects[$dgName])
                        ]);
                    } else {
                        $this->logger->warning('Device group element not found in XML', [
                            'device_group' => $dgName
                        ]);
                        $objects[$dgName] = $this->getEmptyObjectsStructure();
                    }
                } catch (\Exception $e) {
                    $scopesFailed++;
                    $this->logger->error('Failed to build objects for device group', [
                        'device_group' => $dgName,
                        'error' => $e->getMessage()
                    ]);
                    // Continue processing other device groups
                    $objects[$dgName] = $this->getEmptyObjectsStructure();
                }
            }

            $this->logger->info('Objects catalog building completed', [
                'scopes_processed' => $scopesProcessed,
                'scopes_failed' => $scopesFailed,
                'total_scopes' => count($deviceGroups) + 1 // +1 for Shared
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Critical error building objects catalog', [
                'error' => $e->getMessage(),
                'scopes_processed' => $scopesProcessed
            ]);
            // Don't throw - return what we have processed so far
        }

        return $objects;
    }

    /**
     * Build objects for a specific scope (Shared or device group)
     *
     * @param SimpleXMLElement $scope Scope XML element
     * @param string $scopeName Name of the scope for logging
     * @return array Objects organized by type
     */
    private function buildObjectsForScope(SimpleXMLElement $scope, string $scopeName): array
    {
        $objects = $this->getEmptyObjectsStructure();
        $objectTypes = [
            'address' => './/address/entry',
            'address-group' => './/address-group/entry',
            'service' => './/service/entry',
            'service-group' => './/service-group/entry',
            'application' => './/application/entry',
            'application-group' => './/application-group/entry'
        ];

        foreach ($objectTypes as $type => $xpath) {
            $processed = 0;
            $failed = 0;
            
            try {
                $elements = $scope->xpath($xpath);
                
                foreach ($elements as $index => $element) {
                    try {
                        $name = (string) $element['name'];
                        if (empty($name)) {
                            $failed++;
                            $this->logger->warning('Object element missing name attribute', [
                                'scope' => $scopeName,
                                'type' => $type,
                                'index' => $index
                            ]);
                            continue;
                        }

                        $parsedObject = $this->parseObjectByType($type, $element, $name, $scopeName);
                        if ($parsedObject !== null) {
                            $objects[$type][$name] = $parsedObject;
                            $processed++;
                        } else {
                            $failed++;
                        }
                        
                    } catch (\Exception $e) {
                        $failed++;
                        $this->logger->warning('Failed to parse object element', [
                            'scope' => $scopeName,
                            'type' => $type,
                            'index' => $index,
                            'name' => $name ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                        // Continue processing other objects
                    }
                }
                
                if ($failed > 0) {
                    $this->logger->warning('Some objects failed to parse', [
                        'scope' => $scopeName,
                        'type' => $type,
                        'processed' => $processed,
                        'failed' => $failed
                    ]);
                }
                
            } catch (\Exception $e) {
                $this->logger->error('Failed to process object type', [
                    'scope' => $scopeName,
                    'type' => $type,
                    'error' => $e->getMessage()
                ]);
                // Continue processing other object types
            }
        }

        return $objects;
    }

    /**
     * Parse address object from XML
     *
     * @param SimpleXMLElement $addr Address XML element
     * @return array Address object structure
     */
    private function parseAddress(SimpleXMLElement $addr): array
    {
        try {
            // Check for IP address
            $ipElements = $addr->xpath('.//ip-netmask');
            if (!empty($ipElements)) {
                $value = trim((string) $ipElements[0]);
                if (!empty($value)) {
                    $kind = strpos($value, '/') !== false ? 'cidr' : 'ip';
                    return ['kind' => $kind, 'value' => $value];
                }
            }

            // Check for IP range
            $rangeElements = $addr->xpath('.//ip-range');
            if (!empty($rangeElements)) {
                $value = trim((string) $rangeElements[0]);
                if (!empty($value)) {
                    return ['kind' => 'range', 'value' => $value];
                }
            }

            // Check for FQDN
            $fqdnElements = $addr->xpath('.//fqdn');
            if (!empty($fqdnElements)) {
                $value = trim((string) $fqdnElements[0]);
                if (!empty($value)) {
                    return ['kind' => 'fqdn', 'value' => $value];
                }
            }

            // Default fallback for malformed address
            $this->logger->warning('Address object has no recognizable type', [
                'name' => (string) $addr['name']
            ]);
            return ['kind' => 'unknown', 'value' => ''];
            
        } catch (\Exception $e) {
            $this->logger->warning('Error parsing address object', [
                'name' => (string) $addr['name'],
                'error' => $e->getMessage()
            ]);
            return ['kind' => 'unknown', 'value' => ''];
        }
    }

    /**
     * Parse address group from XML
     *
     * @param SimpleXMLElement $addrGroup Address group XML element
     * @return array Address group structure
     */
    private function parseAddressGroup(SimpleXMLElement $addrGroup): array
    {
        try {
            // Check for static group (member list)
            $staticElements = $addrGroup->xpath('.//static');
            if (!empty($staticElements)) {
                $members = [];
                try {
                    $memberElements = $staticElements[0]->xpath('.//member');
                    foreach ($memberElements as $member) {
                        $memberName = trim((string) $member);
                        if (!empty($memberName)) {
                            $members[] = $memberName;
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('Error parsing static address group members', [
                        'name' => (string) $addrGroup['name'],
                        'error' => $e->getMessage()
                    ]);
                }
                return ['kind' => 'static', 'members' => $members, 'match' => null];
            }

            // Check for dynamic group (filter expression)
            $dynamicElements = $addrGroup->xpath('.//dynamic');
            if (!empty($dynamicElements)) {
                $match = '';
                try {
                    $filterElements = $dynamicElements[0]->xpath('.//filter');
                    $match = !empty($filterElements) ? trim((string) $filterElements[0]) : '';
                } catch (\Exception $e) {
                    $this->logger->warning('Error parsing dynamic address group filter', [
                        'name' => (string) $addrGroup['name'],
                        'error' => $e->getMessage()
                    ]);
                }
                return ['kind' => 'dynamic', 'members' => [], 'match' => $match];
            }

            // Default fallback for malformed address group
            $this->logger->warning('Address group has no recognizable type', [
                'name' => (string) $addrGroup['name']
            ]);
            return ['kind' => 'static', 'members' => [], 'match' => null];
            
        } catch (\Exception $e) {
            $this->logger->warning('Error parsing address group', [
                'name' => (string) $addrGroup['name'],
                'error' => $e->getMessage()
            ]);
            return ['kind' => 'static', 'members' => [], 'match' => null];
        }
    }

    /**
     * Parse service object from XML
     *
     * @param SimpleXMLElement $service Service XML element
     * @return array Service object structure
     */
    private function parseService(SimpleXMLElement $service): array
    {
        $ports = [];

        // Check for TCP protocol
        $tcpElements = $service->xpath('.//protocol/tcp');
        if (!empty($tcpElements)) {
            $portElements = $tcpElements[0]->xpath('.//port');
            foreach ($portElements as $port) {
                $portValue = (string) $port;
                if (!empty($portValue)) {
                    $ports[] = $portValue;
                }
            }
            return ['proto' => 'tcp', 'ports' => $ports];
        }

        // Check for UDP protocol
        $udpElements = $service->xpath('.//protocol/udp');
        if (!empty($udpElements)) {
            $portElements = $udpElements[0]->xpath('.//port');
            foreach ($portElements as $port) {
                $portValue = (string) $port;
                if (!empty($portValue)) {
                    $ports[] = $portValue;
                }
            }
            return ['proto' => 'udp', 'ports' => $ports];
        }

        // Check for other protocols
        $protocolElements = $service->xpath('.//protocol');
        if (!empty($protocolElements)) {
            $protocolChildren = $protocolElements[0]->children();
            if (!empty($protocolChildren)) {
                $protocolName = $protocolChildren[0]->getName();
                if (!in_array($protocolName, ['tcp', 'udp'])) {
                    return ['proto' => $protocolName, 'ports' => []];
                }
            }
        }

        // Default fallback
        return ['proto' => 'other', 'ports' => []];
    }

    /**
     * Parse service group from XML
     *
     * @param SimpleXMLElement $serviceGroup Service group XML element
     * @return array Service group structure
     */
    private function parseServiceGroup(SimpleXMLElement $serviceGroup): array
    {
        $members = [];
        $memberElements = $serviceGroup->xpath('.//members/member');
        foreach ($memberElements as $member) {
            $memberName = (string) $member;
            if (!empty($memberName)) {
                $members[] = $memberName;
            }
        }

        return ['members' => $members];
    }

    /**
     * Parse application object from XML
     *
     * @param SimpleXMLElement $app Application XML element
     * @return array Application object structure
     */
    private function parseApplication(SimpleXMLElement $app): array
    {
        // For now, just store the name as applications are typically leaf objects
        // In a full implementation, we might parse ports, protocols, etc.
        return ['name' => (string) $app['name']];
    }

    /**
     * Parse application group from XML
     *
     * @param SimpleXMLElement $appGroup Application group XML element
     * @return array Application group structure
     */
    private function parseApplicationGroup(SimpleXMLElement $appGroup): array
    {
        $members = [];
        $memberElements = $appGroup->xpath('.//members/member');
        foreach ($memberElements as $member) {
            $memberName = (string) $member;
            if (!empty($memberName)) {
                $members[] = $memberName;
            }
        }

        return ['members' => $members];
    }

    /**
     * Build zones catalog with device group associations
     *
     * @param SimpleXMLElement $root Root XML element
     * @param array $deviceGroups Device groups for associations
     * @return array Zones catalog with device group mappings
     */
    private function buildZones(SimpleXMLElement $root, array $deviceGroups): array
    {
        $zones = [];
        $zonesProcessed = 0;
        $zonesFailed = 0;

        try {
            // Collect zones from shared configuration
            $sharedConfig = $root->xpath('//shared')[0] ?? null;
            if ($sharedConfig) {
                $sharedZones = $sharedConfig->xpath('.//zone/entry');
                foreach ($sharedZones as $zone) {
                    try {
                        $zoneName = (string) $zone['name'];
                        if (!empty($zoneName)) {
                            $zones[$zoneName] = ['scope' => 'Shared', 'deviceGroups' => []];
                            $zonesProcessed++;
                        }
                    } catch (\Exception $e) {
                        $zonesFailed++;
                        $this->logger->warning('Failed to process shared zone', [
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            // Collect zones from device groups (try multiple patterns)
            foreach ($deviceGroups as $dgName => $dgInfo) {
                try {
                    // Try direct format first
                    $dgElements = $root->xpath("//device-group[@name='{$dgName}']");
                    
                    // If not found, try nested format
                    if (empty($dgElements)) {
                        $dgElements = $root->xpath("//devices/entry/device-group/entry[@name='{$dgName}']");
                    }
                    
                    if (!empty($dgElements)) {
                        $dgZones = $dgElements[0]->xpath('.//zone/entry');
                        foreach ($dgZones as $zone) {
                            try {
                                $zoneName = (string) $zone['name'];
                                if (!empty($zoneName)) {
                                    if (!isset($zones[$zoneName])) {
                                        $zones[$zoneName] = ['scope' => $dgName, 'deviceGroups' => [$dgName]];
                                    } else {
                                        // Zone exists in multiple scopes, add this device group
                                        if (!in_array($dgName, $zones[$zoneName]['deviceGroups'])) {
                                            $zones[$zoneName]['deviceGroups'][] = $dgName;
                                        }
                                    }
                                    $zonesProcessed++;
                                }
                            } catch (\Exception $e) {
                                $zonesFailed++;
                                $this->logger->warning('Failed to process device group zone', [
                                    'device_group' => $dgName,
                                    'error' => $e->getMessage()
                                ]);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to process zones for device group', [
                        'device_group' => $dgName,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // If we didn't find many zones, try the global zone pattern (based on diagnostic)
            if (count($zones) < 100) { // Arbitrary threshold - you have 6522 zones
                $this->logger->debug('Trying global zone pattern due to low zone count', [
                    'current_count' => count($zones)
                ]);
                
                try {
                    // Try the pattern that worked in diagnostic: //devices//zone/entry
                    $globalZones = $root->xpath('//devices//zone/entry');
                    foreach ($globalZones as $zone) {
                        try {
                            $zoneName = (string) $zone['name'];
                            if (!empty($zoneName) && !isset($zones[$zoneName])) {
                                $zones[$zoneName] = ['scope' => 'Global', 'deviceGroups' => []];
                                $zonesProcessed++;
                            }
                        } catch (\Exception $e) {
                            $zonesFailed++;
                            $this->logger->warning('Failed to process global zone', [
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to process global zones', [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->logger->info('Zones catalog building completed', [
                'zones_processed' => $zonesProcessed,
                'zones_failed' => $zonesFailed,
                'total_zones' => count($zones)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Critical error building zones catalog', [
                'error' => $e->getMessage(),
                'zones_processed' => $zonesProcessed
            ]);
        }

        return $zones;
    }

    /**
     * Get empty objects structure
     *
     * @return array Empty objects structure
     */
    private function getEmptyObjectsStructure(): array
    {
        return [
            'address' => [],
            'address-group' => [],
            'service' => [],
            'service-group' => [],
            'application' => [],
            'application-group' => []
        ];
    }

    /**
     * Count total objects across all scopes
     *
     * @param array $objects Objects catalog
     * @return int Total object count
     */
    private function countTotalObjects(array $objects): int
    {
        $total = 0;
        
        foreach ($objects as $scope => $types) {
            $total += $this->countObjectsInScope($types);
        }
        
        return $total;
    }

    /**
     * Count objects in a specific scope
     *
     * @param array $scopeObjects Objects for a specific scope
     * @return int Object count for the scope
     */
    private function countObjectsInScope(array $scopeObjects): int
    {
        $total = 0;
        
        foreach ($scopeObjects as $type => $items) {
            $total += count($items);
        }
        
        return $total;
    }

    /**
     * Parse object by type with error handling
     *
     * @param string $type Object type
     * @param SimpleXMLElement $element XML element
     * @param string $name Object name
     * @param string $scopeName Scope name for logging
     * @return array|null Parsed object or null on failure
     */
    private function parseObjectByType(string $type, SimpleXMLElement $element, string $name, string $scopeName): ?array
    {
        try {
            return match ($type) {
                'address' => $this->parseAddress($element),
                'address-group' => $this->parseAddressGroup($element),
                'service' => $this->parseService($element),
                'service-group' => $this->parseServiceGroup($element),
                'application' => $this->parseApplication($element),
                'application-group' => $this->parseApplicationGroup($element),
                default => null
            };
        } catch (\Exception $e) {
            $this->logger->warning('Failed to parse object', [
                'scope' => $scopeName,
                'type' => $type,
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}