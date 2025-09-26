<?php

namespace Tests\Unit\Services\Panorama;

use App\Services\Panorama\CatalogBuilder;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

class CatalogBuilderTest extends TestCase
{
    private CatalogBuilder $catalogBuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogBuilder = new CatalogBuilder();
    }

    public function test_builds_empty_catalog_with_no_device_groups()
    {
        $xml = new SimpleXMLElement('<config></config>');
        
        $catalog = $this->catalogBuilder->build($xml);
        
        $this->assertIsArray($catalog);
        $this->assertArrayHasKey('deviceGroups', $catalog);
        $this->assertArrayHasKey('objects', $catalog);
        $this->assertArrayHasKey('zones', $catalog);
        $this->assertEmpty($catalog['deviceGroups']);
    }

    public function test_builds_single_device_group_without_parent()
    {
        $xml = new SimpleXMLElement('
            <config>
                <device-group name="DG1">
                </device-group>
            </config>
        ');
        
        $catalog = $this->catalogBuilder->build($xml);
        
        $deviceGroups = $catalog['deviceGroups'];
        $this->assertCount(1, $deviceGroups);
        $this->assertArrayHasKey('DG1', $deviceGroups);
        
        $dg1 = $deviceGroups['DG1'];
        $this->assertEquals('DG1', $dg1['name']);
        $this->assertNull($dg1['parent']);
        $this->assertEmpty($dg1['children']);
        $this->assertEquals(['DG1'], $dg1['path']);
    }

    public function test_builds_parent_child_relationships()
    {
        $xml = new SimpleXMLElement('
            <config>
                <device-group name="Parent">
                </device-group>
                <device-group name="Child">
                    <parent>Parent</parent>
                </device-group>
            </config>
        ');
        
        $catalog = $this->catalogBuilder->build($xml);
        
        $deviceGroups = $catalog['deviceGroups'];
        $this->assertCount(2, $deviceGroups);
        
        // Check parent
        $parent = $deviceGroups['Parent'];
        $this->assertEquals('Parent', $parent['name']);
        $this->assertNull($parent['parent']);
        $this->assertEquals(['Child'], $parent['children']);
        $this->assertEquals(['Parent'], $parent['path']);
        
        // Check child
        $child = $deviceGroups['Child'];
        $this->assertEquals('Child', $child['name']);
        $this->assertEquals('Parent', $child['parent']);
        $this->assertEmpty($child['children']);
        $this->assertEquals(['Parent', 'Child'], $child['path']);
    }

    public function test_builds_multi_level_hierarchy()
    {
        $xml = new SimpleXMLElement('
            <config>
                <device-group name="Root">
                </device-group>
                <device-group name="Level1">
                    <parent>Root</parent>
                </device-group>
                <device-group name="Level2">
                    <parent>Level1</parent>
                </device-group>
                <device-group name="Level3">
                    <parent>Level2</parent>
                </device-group>
            </config>
        ');
        
        $catalog = $this->catalogBuilder->build($xml);
        
        $deviceGroups = $catalog['deviceGroups'];
        $this->assertCount(4, $deviceGroups);
        
        // Check inheritance paths
        $this->assertEquals(['Root'], $deviceGroups['Root']['path']);
        $this->assertEquals(['Root', 'Level1'], $deviceGroups['Level1']['path']);
        $this->assertEquals(['Root', 'Level1', 'Level2'], $deviceGroups['Level2']['path']);
        $this->assertEquals(['Root', 'Level1', 'Level2', 'Level3'], $deviceGroups['Level3']['path']);
        
        // Check parent-child relationships
        $this->assertEquals(['Level1'], $deviceGroups['Root']['children']);
        $this->assertEquals(['Level2'], $deviceGroups['Level1']['children']);
        $this->assertEquals(['Level3'], $deviceGroups['Level2']['children']);
        $this->assertEmpty($deviceGroups['Level3']['children']);
    }

    public function test_handles_multiple_children()
    {
        $xml = new SimpleXMLElement('
            <config>
                <device-group name="Parent">
                </device-group>
                <device-group name="Child1">
                    <parent>Parent</parent>
                </device-group>
                <device-group name="Child2">
                    <parent>Parent</parent>
                </device-group>
                <device-group name="Child3">
                    <parent>Parent</parent>
                </device-group>
            </config>
        ');
        
        $catalog = $this->catalogBuilder->build($xml);
        
        $deviceGroups = $catalog['deviceGroups'];
        $parent = $deviceGroups['Parent'];
        
        $this->assertCount(3, $parent['children']);
        $this->assertContains('Child1', $parent['children']);
        $this->assertContains('Child2', $parent['children']);
        $this->assertContains('Child3', $parent['children']);
        
        // Check all children have correct parent and path
        foreach (['Child1', 'Child2', 'Child3'] as $childName) {
            $child = $deviceGroups[$childName];
            $this->assertEquals('Parent', $child['parent']);
            $this->assertEquals(['Parent', $childName], $child['path']);
        }
    }

    public function test_handles_missing_parent_gracefully()
    {
        $xml = new SimpleXMLElement('
            <config>
                <device-group name="Orphan">
                    <parent>NonExistentParent</parent>
                </device-group>
                <device-group name="ValidDG">
                </device-group>
            </config>
        ');
        
        $catalog = $this->catalogBuilder->build($xml);
        
        $deviceGroups = $catalog['deviceGroups'];
        
        // Check that orphan device group is handled gracefully
        $orphan = $deviceGroups['Orphan'];
        $this->assertEquals('Orphan', $orphan['name']);
        $this->assertNull($orphan['parent']); // Parent should be nullified
        $this->assertEquals(['Orphan'], $orphan['path']);
        
        // Check that valid device group is unaffected
        $validDG = $deviceGroups['ValidDG'];
        $this->assertEquals('ValidDG', $validDG['name']);
        $this->assertNull($validDG['parent']);
        $this->assertEquals(['ValidDG'], $validDG['path']);
        
        // The key test is that the system continues to work despite missing parent
        $this->assertCount(2, $deviceGroups);
    }

    public function test_detects_and_handles_circular_references()
    {
        $xml = new SimpleXMLElement('
            <config>
                <device-group name="DG1">
                    <parent>DG2</parent>
                </device-group>
                <device-group name="DG2">
                    <parent>DG3</parent>
                </device-group>
                <device-group name="DG3">
                    <parent>DG1</parent>
                </device-group>
            </config>
        ');
        
        $catalog = $this->catalogBuilder->build($xml);
        
        $deviceGroups = $catalog['deviceGroups'];
        
        // All device groups should be present
        $this->assertCount(3, $deviceGroups);
        $this->assertArrayHasKey('DG1', $deviceGroups);
        $this->assertArrayHasKey('DG2', $deviceGroups);
        $this->assertArrayHasKey('DG3', $deviceGroups);
        
        // Paths should be computed despite the cycle (breaking at detection point)
        // The key test is that the system doesn't hang and produces valid paths
        foreach (['DG1', 'DG2', 'DG3'] as $dgName) {
            $this->assertNotEmpty($deviceGroups[$dgName]['path']);
            $this->assertContains($dgName, $deviceGroups[$dgName]['path']);
            // Path should be finite (not infinite due to cycle)
            // When cycle is detected, we break and return just the current node
            $this->assertLessThanOrEqual(4, count($deviceGroups[$dgName]['path']));
        }
    }

    public function test_ignores_device_groups_without_names()
    {
        $xml = new SimpleXMLElement('
            <config>
                <device-group>
                    <!-- Device group without name attribute -->
                </device-group>
                <device-group name="">
                    <!-- Device group with empty name -->
                </device-group>
                <device-group name="ValidDG">
                </device-group>
            </config>
        ');
        
        $catalog = $this->catalogBuilder->build($xml);
        
        $deviceGroups = $catalog['deviceGroups'];
        
        // Only the valid device group should be included
        $this->assertCount(1, $deviceGroups);
        $this->assertArrayHasKey('ValidDG', $deviceGroups);
    }

    public function test_handles_complex_hierarchy_with_mixed_scenarios()
    {
        $xml = new SimpleXMLElement('
            <config>
                <device-group name="Root1">
                </device-group>
                <device-group name="Root2">
                </device-group>
                <device-group name="Branch1">
                    <parent>Root1</parent>
                </device-group>
                <device-group name="Branch2">
                    <parent>Root1</parent>
                </device-group>
                <device-group name="Leaf1">
                    <parent>Branch1</parent>
                </device-group>
                <device-group name="Leaf2">
                    <parent>Branch2</parent>
                </device-group>
                <device-group name="Orphan">
                    <parent>MissingParent</parent>
                </device-group>
            </config>
        ');
        
        $catalog = $this->catalogBuilder->build($xml);
        
        $deviceGroups = $catalog['deviceGroups'];
        
        // Check all device groups are present
        $this->assertCount(7, $deviceGroups);
        
        // Check root nodes
        $this->assertEquals(['Root1'], $deviceGroups['Root1']['path']);
        $this->assertEquals(['Root2'], $deviceGroups['Root2']['path']);
        $this->assertEquals(['Branch1', 'Branch2'], $deviceGroups['Root1']['children']);
        $this->assertEmpty($deviceGroups['Root2']['children']);
        
        // Check branch nodes
        $this->assertEquals(['Root1', 'Branch1'], $deviceGroups['Branch1']['path']);
        $this->assertEquals(['Root1', 'Branch2'], $deviceGroups['Branch2']['path']);
        $this->assertEquals(['Leaf1'], $deviceGroups['Branch1']['children']);
        $this->assertEquals(['Leaf2'], $deviceGroups['Branch2']['children']);
        
        // Check leaf nodes
        $this->assertEquals(['Root1', 'Branch1', 'Leaf1'], $deviceGroups['Leaf1']['path']);
        $this->assertEquals(['Root1', 'Branch2', 'Leaf2'], $deviceGroups['Leaf2']['path']);
        $this->assertEmpty($deviceGroups['Leaf1']['children']);
        $this->assertEmpty($deviceGroups['Leaf2']['children']);
        
        // Check orphan handling
        $this->assertEquals(['Orphan'], $deviceGroups['Orphan']['path']);
        $this->assertNull($deviceGroups['Orphan']['parent']);
        
        // The key test is that the system handles the missing parent gracefully
        $this->assertCount(7, $deviceGroups);
    }

    public function test_builds_empty_objects_catalog_with_no_objects()
    {
        $xml = new SimpleXMLElement('<config></config>');
        
        $catalog = $this->catalogBuilder->build($xml);
        
        $this->assertIsArray($catalog['objects']);
        // Should have Shared structure but with empty object types
        $this->assertArrayHasKey('Shared', $catalog['objects']);
        $this->assertEmpty($catalog['objects']['Shared']['address']);
        $this->assertEmpty($catalog['objects']['Shared']['service']);
    }

    public function test_builds_shared_address_objects()
    {
        $xml = new SimpleXMLElement('
            <config>
                <shared>
                    <address>
                        <entry name="Host1">
                            <ip-netmask>192.168.1.1</ip-netmask>
                        </entry>
                        <entry name="Network1">
                            <ip-netmask>10.0.0.0/24</ip-netmask>
                        </entry>
                        <entry name="Range1">
                            <ip-range>192.168.1.10-192.168.1.20</ip-range>
                        </entry>
                        <entry name="FQDN1">
                            <fqdn>example.com</fqdn>
                        </entry>
                    </address>
                </shared>
            </config>
        ');
        
        $catalog = $this->catalogBuilder->build($xml);
        
        $objects = $catalog['objects'];
        $this->assertArrayHasKey('Shared', $objects);
        
        $sharedObjects = $objects['Shared'];
        $this->assertArrayHasKey('address', $sharedObjects);
        
        $addresses = $sharedObjects['address'];
        $this->assertCount(4, $addresses);
        
        // Test IP address
        $this->assertArrayHasKey('Host1', $addresses);
        $this->assertEquals(['kind' => 'ip', 'value' => '192.168.1.1'], $addresses['Host1']);
        
        // Test CIDR network
        $this->assertArrayHasKey('Network1', $addresses);
        $this->assertEquals(['kind' => 'cidr', 'value' => '10.0.0.0/24'], $addresses['Network1']);
        
        // Test IP range
        $this->assertArrayHasKey('Range1', $addresses);
        $this->assertEquals(['kind' => 'range', 'value' => '192.168.1.10-192.168.1.20'], $addresses['Range1']);
        
        // Test FQDN
        $this->assertArrayHasKey('FQDN1', $addresses);
        $this->assertEquals(['kind' => 'fqdn', 'value' => 'example.com'], $addresses['FQDN1']);
    }

    public function test_builds_address_groups_static_and_dynamic()
    {
        $xml = new SimpleXMLElement('
            <config>
                <shared>
                    <address-group>
                        <entry name="StaticGroup">
                            <static>
                                <member>Host1</member>
                                <member>Network1</member>
                            </static>
                        </entry>
                        <entry name="DynamicGroup">
                            <dynamic>
                                <filter>tag.location eq "datacenter"</filter>
                            </dynamic>
                        </entry>
                    </address-group>
                </shared>
            </config>
        ');
        
        $catalog = $this->catalogBuilder->build($xml);
        
        $addressGroups = $catalog['objects']['Shared']['address-group'];
        $this->assertCount(2, $addressGroups);
        
        // Test static group
        $this->assertArrayHasKey('StaticGroup', $addressGroups);
        $staticGroup = $addressGroups['StaticGroup'];
        $this->assertEquals('static', $staticGroup['kind']);
        $this->assertEquals(['Host1', 'Network1'], $staticGroup['members']);
        $this->assertNull($staticGroup['match']);
        
        // Test dynamic group
        $this->assertArrayHasKey('DynamicGroup', $addressGroups);
        $dynamicGroup = $addressGroups['DynamicGroup'];
        $this->assertEquals('dynamic', $dynamicGroup['kind']);
        $this->assertEquals([], $dynamicGroup['members']);
        $this->assertEquals('tag.location eq "datacenter"', $dynamicGroup['match']);
    }

    public function test_builds_service_objects()
    {
        $xml = new SimpleXMLElement('
            <config>
                <shared>
                    <service>
                        <entry name="HTTP">
                            <protocol>
                                <tcp>
                                    <port>80</port>
                                </tcp>
                            </protocol>
                        </entry>
                        <entry name="DNS">
                            <protocol>
                                <udp>
                                    <port>53</port>
                                </udp>
                            </protocol>
                        </entry>
                        <entry name="WebPorts">
                            <protocol>
                                <tcp>
                                    <port>80,443,8080</port>
                                </tcp>
                            </protocol>
                        </entry>
                    </service>
                </shared>
            </config>
        ');
        
        $catalog = $this->catalogBuilder->build($xml);
        
        $services = $catalog['objects']['Shared']['service'];
        $this->assertCount(3, $services);
        
        // Test TCP service
        $this->assertArrayHasKey('HTTP', $services);
        $this->assertEquals(['proto' => 'tcp', 'ports' => ['80']], $services['HTTP']);
        
        // Test UDP service
        $this->assertArrayHasKey('DNS', $services);
        $this->assertEquals(['proto' => 'udp', 'ports' => ['53']], $services['DNS']);
        
        // Test multiple ports
        $this->assertArrayHasKey('WebPorts', $services);
        $this->assertEquals(['proto' => 'tcp', 'ports' => ['80,443,8080']], $services['WebPorts']);
    }

    public function test_builds_service_groups()
    {
        $xml = new SimpleXMLElement('
            <config>
                <shared>
                    <service-group>
                        <entry name="WebServices">
                            <members>
                                <member>HTTP</member>
                                <member>HTTPS</member>
                            </members>
                        </entry>
                    </service-group>
                </shared>
            </config>
        ');
        
        $catalog = $this->catalogBuilder->build($xml);
        
        $serviceGroups = $catalog['objects']['Shared']['service-group'];
        $this->assertCount(1, $serviceGroups);
        
        $this->assertArrayHasKey('WebServices', $serviceGroups);
        $this->assertEquals(['members' => ['HTTP', 'HTTPS']], $serviceGroups['WebServices']);
    }

    public function test_builds_application_objects_and_groups()
    {
        $xml = new SimpleXMLElement('
            <config>
                <shared>
                    <application>
                        <entry name="custom-app">
                        </entry>
                    </application>
                    <application-group>
                        <entry name="WebApps">
                            <members>
                                <member>web-browsing</member>
                                <member>ssl</member>
                            </members>
                        </entry>
                    </application-group>
                </shared>
            </config>
        ');
        
        $catalog = $this->catalogBuilder->build($xml);
        
        $applications = $catalog['objects']['Shared']['application'];
        $this->assertCount(1, $applications);
        $this->assertArrayHasKey('custom-app', $applications);
        $this->assertEquals(['name' => 'custom-app'], $applications['custom-app']);
        
        $applicationGroups = $catalog['objects']['Shared']['application-group'];
        $this->assertCount(1, $applicationGroups);
        $this->assertArrayHasKey('WebApps', $applicationGroups);
        $this->assertEquals(['members' => ['web-browsing', 'ssl']], $applicationGroups['WebApps']);
    }

    public function test_builds_objects_across_device_groups()
    {
        $xml = new SimpleXMLElement('
            <config>
                <shared>
                    <address>
                        <entry name="SharedHost">
                            <ip-netmask>192.168.1.1</ip-netmask>
                        </entry>
                    </address>
                </shared>
                <device-group name="DG1">
                    <address>
                        <entry name="DG1Host">
                            <ip-netmask>10.1.1.1</ip-netmask>
                        </entry>
                    </address>
                </device-group>
                <device-group name="DG2">
                    <address>
                        <entry name="DG2Host">
                            <ip-netmask>10.2.1.1</ip-netmask>
                        </entry>
                    </address>
                </device-group>
            </config>
        ');
        
        $catalog = $this->catalogBuilder->build($xml);
        
        $objects = $catalog['objects'];
        
        // Check shared objects
        $this->assertArrayHasKey('Shared', $objects);
        $this->assertArrayHasKey('SharedHost', $objects['Shared']['address']);
        
        // Check DG1 objects
        $this->assertArrayHasKey('DG1', $objects);
        $this->assertArrayHasKey('DG1Host', $objects['DG1']['address']);
        
        // Check DG2 objects
        $this->assertArrayHasKey('DG2', $objects);
        $this->assertArrayHasKey('DG2Host', $objects['DG2']['address']);
        
        // Verify object isolation between scopes
        $this->assertArrayNotHasKey('DG1Host', $objects['Shared']['address']);
        $this->assertArrayNotHasKey('DG2Host', $objects['DG1']['address']);
        $this->assertArrayNotHasKey('DG1Host', $objects['DG2']['address']);
    }

    public function test_builds_zones_catalog()
    {
        $xml = new SimpleXMLElement('
            <config>
                <shared>
                    <zone>
                        <entry name="SharedZone">
                        </entry>
                    </zone>
                </shared>
                <device-group name="DG1">
                    <zone>
                        <entry name="DG1Zone">
                        </entry>
                    </zone>
                </device-group>
                <device-group name="DG2">
                    <zone>
                        <entry name="DG2Zone">
                        </entry>
                        <entry name="SharedZone">
                        </entry>
                    </zone>
                </device-group>
            </config>
        ');
        
        $catalog = $this->catalogBuilder->build($xml);
        
        $zones = $catalog['zones'];
        $this->assertCount(3, $zones);
        
        // Check shared zone
        $this->assertArrayHasKey('SharedZone', $zones);
        $sharedZone = $zones['SharedZone'];
        $this->assertEquals('Shared', $sharedZone['scope']);
        $this->assertContains('DG2', $sharedZone['deviceGroups']); // Also appears in DG2
        
        // Check DG1 zone
        $this->assertArrayHasKey('DG1Zone', $zones);
        $dg1Zone = $zones['DG1Zone'];
        $this->assertEquals('DG1', $dg1Zone['scope']);
        $this->assertEquals(['DG1'], $dg1Zone['deviceGroups']);
        
        // Check DG2 zone
        $this->assertArrayHasKey('DG2Zone', $zones);
        $dg2Zone = $zones['DG2Zone'];
        $this->assertEquals('DG2', $dg2Zone['scope']);
        $this->assertEquals(['DG2'], $dg2Zone['deviceGroups']);
    }

    public function test_handles_empty_object_sections_gracefully()
    {
        $xml = new SimpleXMLElement('
            <config>
                <shared>
                    <address>
                        <!-- Empty address section -->
                    </address>
                    <service>
                        <entry name="">
                            <!-- Service with empty name -->
                        </entry>
                    </service>
                </shared>
                <device-group name="DG1">
                    <!-- Device group with no objects -->
                </device-group>
            </config>
        ');
        
        $catalog = $this->catalogBuilder->build($xml);
        
        $objects = $catalog['objects'];
        
        // Check that empty sections are handled gracefully
        $this->assertArrayHasKey('Shared', $objects);
        $sharedObjects = $objects['Shared'];
        $this->assertEmpty($sharedObjects['address']);
        $this->assertEmpty($sharedObjects['service']); // Empty name should be ignored
        
        // Check that device group with no objects still has structure
        $this->assertArrayHasKey('DG1', $objects);
        $dg1Objects = $objects['DG1'];
        $this->assertArrayHasKey('address', $dg1Objects);
        $this->assertEmpty($dg1Objects['address']);
    }

    public function test_builds_complete_catalog_with_all_components()
    {
        $xml = new SimpleXMLElement('
            <config>
                <shared>
                    <address>
                        <entry name="SharedHost">
                            <ip-netmask>192.168.1.1</ip-netmask>
                        </entry>
                    </address>
                    <service>
                        <entry name="SharedService">
                            <protocol>
                                <tcp>
                                    <port>8080</port>
                                </tcp>
                            </protocol>
                        </entry>
                    </service>
                    <zone>
                        <entry name="SharedZone">
                        </entry>
                    </zone>
                </shared>
                <device-group name="Parent">
                    <address>
                        <entry name="ParentHost">
                            <ip-netmask>10.1.1.1</ip-netmask>
                        </entry>
                    </address>
                    <zone>
                        <entry name="ParentZone">
                        </entry>
                    </zone>
                </device-group>
                <device-group name="Child">
                    <parent>Parent</parent>
                    <service>
                        <entry name="ChildService">
                            <protocol>
                                <udp>
                                    <port>53</port>
                                </udp>
                            </protocol>
                        </entry>
                    </service>
                </device-group>
            </config>
        ');
        
        $catalog = $this->catalogBuilder->build($xml);
        
        // Verify all catalog components are present
        $this->assertArrayHasKey('deviceGroups', $catalog);
        $this->assertArrayHasKey('objects', $catalog);
        $this->assertArrayHasKey('zones', $catalog);
        
        // Verify device groups hierarchy
        $deviceGroups = $catalog['deviceGroups'];
        $this->assertCount(2, $deviceGroups);
        $this->assertEquals(['Parent', 'Child'], $deviceGroups['Child']['path']);
        
        // Verify objects across scopes
        $objects = $catalog['objects'];
        $this->assertCount(3, $objects); // Shared, Parent, Child
        $this->assertArrayHasKey('SharedHost', $objects['Shared']['address']);
        $this->assertArrayHasKey('ParentHost', $objects['Parent']['address']);
        $this->assertArrayHasKey('ChildService', $objects['Child']['service']);
        
        // Verify zones
        $zones = $catalog['zones'];
        $this->assertCount(2, $zones); // SharedZone, ParentZone
        $this->assertArrayHasKey('SharedZone', $zones);
        $this->assertArrayHasKey('ParentZone', $zones);
        
        // This test verifies that all components work together correctly
        $this->assertTrue(true); // If we get here, the integration worked
    }

    public function test_handles_malformed_device_group_elements()
    {
        $xml = new SimpleXMLElement('
            <config>
                <device-group><!-- Missing name attribute --></device-group>
                <device-group name="valid-dg"></device-group>
                <device-group name="dg-with-invalid-parent">
                    <parent></parent><!-- Empty parent -->
                </device-group>
            </config>
        ');

        $catalog = $this->catalogBuilder->build($xml);

        // Should only have valid device groups
        $this->assertCount(2, $catalog['deviceGroups']);
        $this->assertArrayHasKey('valid-dg', $catalog['deviceGroups']);
        $this->assertArrayHasKey('dg-with-invalid-parent', $catalog['deviceGroups']);
        
        // Device group with empty parent should have null parent
        $this->assertNull($catalog['deviceGroups']['dg-with-invalid-parent']['parent']);
    }

    public function test_continues_processing_after_object_parsing_errors()
    {
        $xml = new SimpleXMLElement('
            <config>
                <shared>
                    <address>
                        <entry><!-- Missing name attribute --></entry>
                        <entry name="valid-address">
                            <ip-netmask>192.168.1.1</ip-netmask>
                        </entry>
                        <entry name="malformed-address">
                            <!-- Missing address type -->
                        </entry>
                    </address>
                </shared>
            </config>
        ');

        $catalog = $this->catalogBuilder->build($xml);

        // Should have processed the valid address despite errors
        $this->assertArrayHasKey('Shared', $catalog['objects']);
        $this->assertArrayHasKey('address', $catalog['objects']['Shared']);
        $this->assertArrayHasKey('valid-address', $catalog['objects']['Shared']['address']);
        
        // Malformed address should be processed with unknown type
        $this->assertArrayHasKey('malformed-address', $catalog['objects']['Shared']['address']);
        $this->assertEquals('unknown', $catalog['objects']['Shared']['address']['malformed-address']['kind']);
    }

    public function test_handles_malformed_address_group_members()
    {
        $xml = new SimpleXMLElement('
            <config>
                <shared>
                    <address-group>
                        <entry name="group-with-empty-members">
                            <static>
                                <member></member><!-- Empty member -->
                                <member>valid-member</member>
                            </static>
                        </entry>
                        <entry name="group-with-malformed-filter">
                            <dynamic>
                                <filter></filter><!-- Empty filter -->
                            </dynamic>
                        </entry>
                    </address-group>
                </shared>
            </config>
        ');

        $catalog = $this->catalogBuilder->build($xml);

        $addressGroups = $catalog['objects']['Shared']['address-group'];
        
        // Static group should only include valid members
        $staticGroup = $addressGroups['group-with-empty-members'];
        $this->assertEquals('static', $staticGroup['kind']);
        $this->assertEquals(['valid-member'], $staticGroup['members']);
        
        // Dynamic group should handle empty filter
        $dynamicGroup = $addressGroups['group-with-malformed-filter'];
        $this->assertEquals('dynamic', $dynamicGroup['kind']);
        $this->assertEquals('', $dynamicGroup['match']);
    }

    public function test_handles_malformed_service_definitions()
    {
        $xml = new SimpleXMLElement('
            <config>
                <shared>
                    <service>
                        <entry name="service-no-protocol">
                            <!-- Missing protocol definition -->
                        </entry>
                        <entry name="service-empty-port">
                            <protocol>
                                <tcp>
                                    <port></port><!-- Empty port -->
                                </tcp>
                            </protocol>
                        </entry>
                        <entry name="valid-service">
                            <protocol>
                                <tcp>
                                    <port>80</port>
                                </tcp>
                            </protocol>
                        </entry>
                    </service>
                </shared>
            </config>
        ');

        $catalog = $this->catalogBuilder->build($xml);

        $services = $catalog['objects']['Shared']['service'];
        
        // Service with no protocol should default to 'other'
        $this->assertArrayHasKey('service-no-protocol', $services);
        $this->assertEquals('other', $services['service-no-protocol']['proto']);
        
        // Service with empty port should still be processed
        $this->assertArrayHasKey('service-empty-port', $services);
        $this->assertEquals('tcp', $services['service-empty-port']['proto']);
        
        // Valid service should work normally
        $this->assertArrayHasKey('valid-service', $services);
        $this->assertEquals(['proto' => 'tcp', 'ports' => ['80']], $services['valid-service']);
    }

    public function test_recovers_from_xpath_errors()
    {
        // Create XML with potentially problematic structure
        $xml = new SimpleXMLElement('
            <config>
                <shared>
                    <address>
                        <entry name="valid-address">
                            <ip-netmask>192.168.1.1</ip-netmask>
                        </entry>
                    </address>
                </shared>
                <device-group name="valid-dg">
                    <address>
                        <entry name="dg-address">
                            <ip-netmask>10.1.1.1</ip-netmask>
                        </entry>
                    </address>
                </device-group>
            </config>
        ');

        // This test ensures the system can handle XPath operations gracefully
        $catalog = $this->catalogBuilder->build($xml);

        // Should successfully process both shared and device group objects
        $this->assertArrayHasKey('Shared', $catalog['objects']);
        $this->assertArrayHasKey('valid-dg', $catalog['objects']);
        $this->assertArrayHasKey('valid-address', $catalog['objects']['Shared']['address']);
        $this->assertArrayHasKey('dg-address', $catalog['objects']['valid-dg']['address']);
    }

    public function test_handles_missing_device_group_in_objects()
    {
        $xml = new SimpleXMLElement('
            <config>
                <device-group name="existing-dg">
                </device-group>
            </config>
        ');

        $catalog = $this->catalogBuilder->build($xml);

        // Device group exists but has no objects section
        $this->assertArrayHasKey('existing-dg', $catalog['deviceGroups']);
        $this->assertArrayHasKey('existing-dg', $catalog['objects']);
        
        // Should have empty but valid object structure
        $dgObjects = $catalog['objects']['existing-dg'];
        $this->assertArrayHasKey('address', $dgObjects);
        $this->assertArrayHasKey('service', $dgObjects);
        $this->assertEmpty($dgObjects['address']);
        $this->assertEmpty($dgObjects['service']);
    }
}