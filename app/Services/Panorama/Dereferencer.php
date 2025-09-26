<?php

namespace App\Services\Panorama;

use App\Services\Panorama\Contracts\DereferencerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Dereferencer implements DereferencerInterface
{
    private LoggerInterface $logger;

    public function __construct(
        public array $catalog,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Expand address references following inheritance hierarchy
     *
     * @param string $dgName Device group name
     * @param array $names Array of address names to expand
     * @return array Expanded addresses
     */
    public function expandAddresses(string $dgName, array $names): array
    {
        $expanded = [];
        $failed = 0;
        
        $this->logger->debug('Expanding addresses', [
            'device_group' => $dgName,
            'address_count' => count($names)
        ]);
        
        foreach ($names as $name) {
            try {
                if (empty($name) || !is_string($name)) {
                    $failed++;
                    $this->logger->warning('Invalid address name provided', [
                        'device_group' => $dgName,
                        'name' => $name
                    ]);
                    continue;
                }
                
                $result = $this->expandAddress($dgName, $name, []);
                $expanded = array_merge($expanded, $result);
                
            } catch (\Exception $e) {
                $failed++;
                $this->logger->warning('Failed to expand address', [
                    'device_group' => $dgName,
                    'address' => $name,
                    'error' => $e->getMessage()
                ]);
                // Add as unknown to maintain audit trail
                $expanded[] = "UNKNOWN:{$name}";
            }
        }
        
        if ($failed > 0) {
            $this->logger->warning('Some addresses failed to expand', [
                'device_group' => $dgName,
                'failed_count' => $failed,
                'total_count' => count($names)
            ]);
        }
        
        return array_unique($expanded);
    }

    /**
     * Expand service references following inheritance hierarchy
     *
     * @param string $dgName Device group name
     * @param array $names Array of service names to expand
     * @return array Expanded services with port combinations
     */
    public function expandServices(string $dgName, array $names): array
    {
        $expanded = [];
        $failed = 0;
        
        $this->logger->debug('Expanding services', [
            'device_group' => $dgName,
            'service_count' => count($names)
        ]);
        
        foreach ($names as $name) {
            try {
                if (empty($name) || !is_string($name)) {
                    $failed++;
                    $this->logger->warning('Invalid service name provided', [
                        'device_group' => $dgName,
                        'name' => $name
                    ]);
                    continue;
                }
                
                $result = $this->expandService($dgName, $name, []);
                $expanded = array_merge($expanded, $result);
                
            } catch (\Exception $e) {
                $failed++;
                $this->logger->warning('Failed to expand service', [
                    'device_group' => $dgName,
                    'service' => $name,
                    'error' => $e->getMessage()
                ]);
                // Add as unknown to maintain audit trail
                $expanded[] = "UNKNOWN:{$name}";
            }
        }
        
        if ($failed > 0) {
            $this->logger->warning('Some services failed to expand', [
                'device_group' => $dgName,
                'failed_count' => $failed,
                'total_count' => count($names)
            ]);
        }
        
        return array_unique($expanded);
    }

    /**
     * Expand application references following inheritance hierarchy
     *
     * @param string $dgName Device group name
     * @param array $names Array of application names to expand
     * @return array Expanded applications
     */
    public function expandApplications(string $dgName, array $names): array
    {
        $expanded = [];
        
        foreach ($names as $name) {
            $result = $this->expandApplication($dgName, $name, []);
            $expanded = array_merge($expanded, $result);
        }
        
        return array_unique($expanded);
    }

    /**
     * Get zones for a device group
     *
     * @param string $dgName Device group name
     * @param array $zoneNames Array of zone names
     * @return array Available zones
     */
    public function zonesFor(string $dgName, array $zoneNames): array
    {
        $zones = [];
        
        foreach ($zoneNames as $zoneName) {
            if (isset($this->catalog['zones'][$zoneName])) {
                $zones[] = $zoneName;
            } else {
                // Mark unknown zones
                $zones[] = "UNKNOWN:{$zoneName}";
            }
        }
        
        return $zones;
    }

    /**
     * Expand a single address following inheritance hierarchy with cycle detection
     *
     * @param string $dgName Device group name
     * @param string $name Address name to expand
     * @param array $visited Visited objects for cycle detection
     * @return array Expanded address values
     */
    private function expandAddress(string $dgName, string $name, array $visited): array
    {
        // Check for cycles
        $visitKey = "address:{$dgName}:{$name}";
        if (in_array($visitKey, $visited)) {
            $this->logger->warning('Cycle detected in address expansion', [
                'device_group' => $dgName,
                'address' => $name,
                'visited_path' => $visited
            ]);
            return ["CYCLE:{$name}"];
        }
        
        $visited[] = $visitKey;
        
        try {
            // Try to resolve the address following inheritance path
            $inheritancePath = $this->getInheritancePath($dgName);
            
            foreach ($inheritancePath as $scope) {
                try {
                    // Check for direct address object
                    if (isset($this->catalog['objects'][$scope]['address'][$name])) {
                        $address = $this->catalog['objects'][$scope]['address'][$name];
                        if (isset($address['value']) && !empty($address['value'])) {
                            return [$address['value']];
                        } else {
                            $this->logger->warning('Address object has empty value', [
                                'device_group' => $dgName,
                                'address' => $name,
                                'scope' => $scope
                            ]);
                        }
                    }
                    
                    // Check for address group
                    if (isset($this->catalog['objects'][$scope]['address-group'][$name])) {
                        $group = $this->catalog['objects'][$scope]['address-group'][$name];
                        
                        if ($group['kind'] === 'static') {
                            // Expand static group members
                            $expanded = [];
                            if (isset($group['members']) && is_array($group['members'])) {
                                foreach ($group['members'] as $member) {
                                    try {
                                        // Skip null or empty members
                                        if (empty($member) || !is_string($member)) {
                                            $this->logger->warning('Skipping invalid group member', [
                                                'device_group' => $dgName,
                                                'group' => $name,
                                                'member' => $member
                                            ]);
                                            continue;
                                        }
                                        
                                        $memberExpanded = $this->expandAddress($dgName, $member, $visited);
                                        $expanded = array_merge($expanded, $memberExpanded);
                                    } catch (\Exception $e) {
                                        $this->logger->warning('Failed to expand address group member', [
                                            'device_group' => $dgName,
                                            'group' => $name,
                                            'member' => $member,
                                            'error' => $e->getMessage()
                                        ]);
                                        if (is_string($member) && !empty($member)) {
                                            $expanded[] = "UNKNOWN:{$member}";
                                        }
                                    }
                                }
                            }
                            return $expanded;
                        } else {
                            // Dynamic group - mark as unresolved
                            return ["DAG:{$name}"];
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('Error checking scope for address', [
                        'device_group' => $dgName,
                        'address' => $name,
                        'scope' => $scope,
                        'error' => $e->getMessage()
                    ]);
                    // Continue checking other scopes
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->warning('Error during address expansion', [
                'device_group' => $dgName,
                'address' => $name,
                'error' => $e->getMessage()
            ]);
        }
        
        // Object not found in any scope
        return ["UNKNOWN:{$name}"];
    }

    /**
     * Expand a single service following inheritance hierarchy with cycle detection
     *
     * @param string $dgName Device group name
     * @param string $name Service name to expand
     * @param array $visited Visited objects for cycle detection
     * @return array Expanded service values with port combinations
     */
    private function expandService(string $dgName, string $name, array $visited): array
    {
        // Check for cycles
        $visitKey = "service:{$dgName}:{$name}";
        if (in_array($visitKey, $visited)) {
            error_log("Warning: Cycle detected in service expansion for '{$name}' in device group '{$dgName}'");
            return ["CYCLE:{$name}"];
        }
        
        $visited[] = $visitKey;
        
        // Try to resolve the service following inheritance path
        $inheritancePath = $this->getInheritancePath($dgName);
        
        foreach ($inheritancePath as $scope) {
            // Check for direct service object
            if (isset($this->catalog['objects'][$scope]['service'][$name])) {
                $service = $this->catalog['objects'][$scope]['service'][$name];
                return $this->formatServicePorts($service);
            }
            
            // Check for service group
            if (isset($this->catalog['objects'][$scope]['service-group'][$name])) {
                $group = $this->catalog['objects'][$scope]['service-group'][$name];
                
                // Expand service group members
                $expanded = [];
                foreach ($group['members'] as $member) {
                    $memberExpanded = $this->expandService($dgName, $member, $visited);
                    $expanded = array_merge($expanded, $memberExpanded);
                }
                return $expanded;
            }
        }
        
        // Service not found in any scope
        return ["UNKNOWN:{$name}"];
    }

    /**
     * Expand a single application following inheritance hierarchy with cycle detection
     *
     * @param string $dgName Device group name
     * @param string $name Application name to expand
     * @param array $visited Visited objects for cycle detection
     * @return array Expanded application values
     */
    private function expandApplication(string $dgName, string $name, array $visited): array
    {
        // Check for cycles
        $visitKey = "application:{$dgName}:{$name}";
        if (in_array($visitKey, $visited)) {
            error_log("Warning: Cycle detected in application expansion for '{$name}' in device group '{$dgName}'");
            return ["CYCLE:{$name}"];
        }
        
        $visited[] = $visitKey;
        
        // Try to resolve the application following inheritance path
        $inheritancePath = $this->getInheritancePath($dgName);
        
        foreach ($inheritancePath as $scope) {
            // Check for direct application object
            if (isset($this->catalog['objects'][$scope]['application'][$name])) {
                return [$name]; // Applications are typically leaf objects
            }
            
            // Check for application group
            if (isset($this->catalog['objects'][$scope]['application-group'][$name])) {
                $group = $this->catalog['objects'][$scope]['application-group'][$name];
                
                // Expand application group members
                $expanded = [];
                foreach ($group['members'] as $member) {
                    $memberExpanded = $this->expandApplication($dgName, $member, $visited);
                    $expanded = array_merge($expanded, $memberExpanded);
                }
                return $expanded;
            }
        }
        
        // Application not found in any scope
        return ["UNKNOWN:{$name}"];
    }

    /**
     * Get inheritance path for a device group (current DG → ancestors → Shared)
     *
     * @param string $dgName Device group name
     * @return array Inheritance path with Shared appended
     */
    private function getInheritancePath(string $dgName): array
    {
        $path = [];
        
        // Get device group path if it exists
        if (isset($this->catalog['deviceGroups'][$dgName])) {
            $path = $this->catalog['deviceGroups'][$dgName]['path'];
        } else {
            // If device group doesn't exist, just use the name
            $path = [$dgName];
        }
        
        // Always append Shared as the final fallback
        if (!in_array('Shared', $path)) {
            $path[] = 'Shared';
        }
        
        return $path;
    }

    /**
     * Format service ports for output
     *
     * @param array $service Service object with protocol and ports
     * @return array Formatted service strings
     */
    private function formatServicePorts(array $service): array
    {
        $proto = $service['proto'] ?? 'unknown';
        $ports = $service['ports'] ?? [];
        
        if (empty($ports) || !is_array($ports)) {
            return [$proto];
        }
        
        $formatted = [];
        foreach ($ports as $port) {
            if (!empty($port)) {
                $formatted[] = "{$proto}/{$port}";
            }
        }
        
        return !empty($formatted) ? $formatted : [$proto];
    }
}