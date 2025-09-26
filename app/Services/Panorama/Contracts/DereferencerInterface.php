<?php

namespace App\Services\Panorama\Contracts;

interface DereferencerInterface
{
    /**
     * Expand address references following inheritance hierarchy
     *
     * @param string $dgName Device group name
     * @param array $names Array of address names to expand
     * @return array Expanded addresses
     */
    public function expandAddresses(string $dgName, array $names): array;

    /**
     * Expand service references following inheritance hierarchy
     *
     * @param string $dgName Device group name
     * @param array $names Array of service names to expand
     * @return array Expanded services
     */
    public function expandServices(string $dgName, array $names): array;

    /**
     * Expand application references following inheritance hierarchy
     *
     * @param string $dgName Device group name
     * @param array $names Array of application names to expand
     * @return array Expanded applications
     */
    public function expandApplications(string $dgName, array $names): array;

    /**
     * Get zones for a device group
     *
     * @param string $dgName Device group name
     * @param array $zoneNames Array of zone names
     * @return array Available zones
     */
    public function zonesFor(string $dgName, array $zoneNames): array;
}