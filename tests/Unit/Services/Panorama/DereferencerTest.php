<?php

namespace Tests\Unit\Services\Panorama;

use App\Services\Panorama\Dereferencer;
use PHPUnit\Framework\TestCase;

class DereferencerTest extends TestCase
{
    private Dereferencer $dereferencer;
    private array $testCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a comprehensive test catalog
        $this->testCatalog = [
            'deviceGroups' => [
                'Root' => [
                    'name' => 'Root',
                    'parent' => null,
                    'children' => ['Child1', 'Child2'],
                    'path' => ['Root']
                ],
                'Child1' => [
                    'name' => 'Child1',
                    'parent' => 'Root',
                    'children' => ['Grandchild1'],
                    'path' => ['Root', 'Child1']
                ],
                'Child2' => [
                    'name' => 'Child2',
                    'parent' => 'Root',
                    'children' => [],
                    'path' => ['Root', 'Child2']
                ],
                'Grandchild1' => [
                    'name' => 'Grandchild1',
                    'parent' => 'Child1',
                    'children' => [],
                    'path' => ['Root', 'Child1', 'Grandchild1']
                ]
            ],
            'objects' => [
                'Shared' => [
                    'address' => [
                        'shared-addr1' => ['kind' => 'ip', 'value' => '10.0.0.1'],
                        'shared-addr2' => ['kind' => 'cidr', 'value' => '192.168.1.0/24']
                    ],
                    'address-group' => [
                        'shared-group1' => ['kind' => 'static', 'members' => ['shared-addr1', 'shared-addr2'], 'match' => null],
                        'shared-dynamic-group' => ['kind' => 'dynamic', 'members' => [], 'match' => 'tag.env eq "prod"']
                    ],
                    'service' => [
                        'shared-svc1' => ['proto' => 'tcp', 'ports' => ['80', '443']],
                        'shared-svc2' => ['proto' => 'udp', 'ports' => ['53']]
                    ],
                    'service-group' => [
                        'shared-svc-group' => ['members' => ['shared-svc1', 'shared-svc2']]
                    ],
                    'application' => [
                        'web-browsing' => ['name' => 'web-browsing'],
                        'dns' => ['name' => 'dns']
                    ],
                    'application-group' => [
                        'web-apps' => ['members' => ['web-browsing', 'ssl']]
                    ]
                ],
                'Root' => [
                    'address' => [
                        'root-addr1' => ['kind' => 'ip', 'value' => '10.1.0.1']
                    ],
                    'address-group' => [
                        'root-group1' => ['kind' => 'static', 'members' => ['root-addr1', 'shared-addr1'], 'match' => null]
                    ],
                    'service' => [
                        'root-svc1' => ['proto' => 'tcp', 'ports' => ['8080']]
                    ],
                    'service-group' => [
                        'root-svc-group' => ['members' => ['root-svc1', 'shared-svc1']]
                    ],
                    'application' => [
                        'custom-app' => ['name' => 'custom-app']
                    ],
                    'application-group' => [
                        'custom-apps' => ['members' => ['custom-app', 'web-browsing']]
                    ]
                ],
                'Child1' => [
                    'address' => [
                        'child1-addr1' => ['kind' => 'cidr', 'value' => '172.16.0.0/16']
                    ],
                    'address-group' => [
                        'child1-group1' => ['kind' => 'static', 'members' => ['child1-addr1', 'root-addr1'], 'match' => null]
                    ],
                    'service' => [
                        'child1-svc1' => ['proto' => 'tcp', 'ports' => ['9090']]
                    ],
                    'service-group' => [],
                    'application' => [
                        'child1-app' => ['name' => 'child1-app']
                    ],
                    'application-group' => []
                ],
                'Grandchild1' => [
                    'address' => [
                        'gc1-addr1' => ['kind' => 'ip', 'value' => '10.2.0.1']
                    ],
                    'address-group' => [
                        'gc1-group1' => ['kind' => 'static', 'members' => ['gc1-addr1', 'child1-addr1'], 'match' => null]
                    ],
                    'service' => [],
                    'service-group' => [],
                    'application' => [],
                    'application-group' => []
                ]
            ],
            'zones' => [
                'trust' => ['scope' => 'Shared', 'deviceGroups' => []],
                'untrust' => ['scope' => 'Shared', 'deviceGroups' => []],
                'dmz' => ['scope' => 'Root', 'deviceGroups' => ['Root']]
            ]
        ];
        
        $this->dereferencer = new Dereferencer($this->testCatalog);
    }

    public function test_expand_addresses_resolves_direct_address()
    {
        $result = $this->dereferencer->expandAddresses('Grandchild1', ['gc1-addr1']);
        
        $this->assertEquals(['10.2.0.1'], $result);
    }

    public function test_expand_addresses_follows_inheritance_hierarchy()
    {
        // Grandchild1 should find child1-addr1 in Child1 scope
        $result = $this->dereferencer->expandAddresses('Grandchild1', ['child1-addr1']);
        
        $this->assertEquals(['172.16.0.0/16'], $result);
    }

    public function test_expand_addresses_falls_back_to_shared()
    {
        // Grandchild1 should find shared-addr1 in Shared scope
        $result = $this->dereferencer->expandAddresses('Grandchild1', ['shared-addr1']);
        
        $this->assertEquals(['10.0.0.1'], $result);
    }

    public function test_expand_addresses_resolves_static_groups()
    {
        $result = $this->dereferencer->expandAddresses('Grandchild1', ['gc1-group1']);
        
        // Should expand to gc1-addr1 and child1-addr1
        $expected = ['10.2.0.1', '172.16.0.0/16'];
        $this->assertEquals($expected, $result);
    }

    public function test_expand_addresses_resolves_nested_static_groups()
    {
        $result = $this->dereferencer->expandAddresses('Root', ['root-group1']);
        
        // Should expand to root-addr1 and shared-addr1
        $expected = ['10.1.0.1', '10.0.0.1'];
        $this->assertEquals($expected, $result);
    }

    public function test_expand_addresses_marks_dynamic_groups_as_unresolved()
    {
        $result = $this->dereferencer->expandAddresses('Root', ['shared-dynamic-group']);
        
        $this->assertEquals(['DAG:shared-dynamic-group'], $result);
    }

    public function test_expand_addresses_marks_unknown_objects()
    {
        $result = $this->dereferencer->expandAddresses('Root', ['nonexistent-addr']);
        
        $this->assertEquals(['UNKNOWN:nonexistent-addr'], $result);
    }

    public function test_expand_addresses_handles_multiple_names()
    {
        $result = $this->dereferencer->expandAddresses('Root', ['root-addr1', 'shared-addr1']);
        
        $expected = ['10.1.0.1', '10.0.0.1'];
        $this->assertEquals($expected, $result);
    }

    public function test_expand_addresses_removes_duplicates()
    {
        $result = $this->dereferencer->expandAddresses('Root', ['shared-addr1', 'shared-addr1']);
        
        $this->assertEquals(['10.0.0.1'], $result);
    }

    public function test_expand_services_resolves_direct_service()
    {
        $result = $this->dereferencer->expandServices('Root', ['root-svc1']);
        
        $this->assertEquals(['tcp/8080'], $result);
    }

    public function test_expand_services_formats_multiple_ports()
    {
        $result = $this->dereferencer->expandServices('Root', ['shared-svc1']);
        
        $expected = ['tcp/80', 'tcp/443'];
        $this->assertEquals($expected, $result);
    }

    public function test_expand_services_resolves_service_groups()
    {
        $result = $this->dereferencer->expandServices('Root', ['root-svc-group']);
        
        // Should expand to root-svc1 and shared-svc1
        $expected = ['tcp/8080', 'tcp/80', 'tcp/443'];
        $this->assertEquals($expected, $result);
    }

    public function test_expand_services_follows_inheritance()
    {
        $result = $this->dereferencer->expandServices('Child1', ['shared-svc2']);
        
        $this->assertEquals(['udp/53'], $result);
    }

    public function test_expand_services_marks_unknown_services()
    {
        $result = $this->dereferencer->expandServices('Root', ['nonexistent-svc']);
        
        $this->assertEquals(['UNKNOWN:nonexistent-svc'], $result);
    }

    public function test_expand_applications_resolves_direct_application()
    {
        $result = $this->dereferencer->expandApplications('Root', ['custom-app']);
        
        $this->assertEquals(['custom-app'], $result);
    }

    public function test_expand_applications_resolves_application_groups()
    {
        $result = $this->dereferencer->expandApplications('Root', ['custom-apps']);
        
        // Should expand to custom-app and web-browsing
        $expected = ['custom-app', 'web-browsing'];
        $this->assertEquals($expected, $result);
    }

    public function test_expand_applications_follows_inheritance()
    {
        $result = $this->dereferencer->expandApplications('Child1', ['web-browsing']);
        
        $this->assertEquals(['web-browsing'], $result);
    }

    public function test_expand_applications_marks_unknown_applications()
    {
        $result = $this->dereferencer->expandApplications('Root', ['nonexistent-app']);
        
        $this->assertEquals(['UNKNOWN:nonexistent-app'], $result);
    }

    public function test_zones_for_returns_existing_zones()
    {
        $result = $this->dereferencer->zonesFor('Root', ['trust', 'dmz']);
        
        $this->assertEquals(['trust', 'dmz'], $result);
    }

    public function test_zones_for_marks_unknown_zones()
    {
        $result = $this->dereferencer->zonesFor('Root', ['nonexistent-zone']);
        
        $this->assertEquals(['UNKNOWN:nonexistent-zone'], $result);
    }

    public function test_cycle_detection_in_address_groups()
    {
        // Create a catalog with circular reference
        $cycleCatalog = [
            'deviceGroups' => [
                'Test' => [
                    'name' => 'Test',
                    'parent' => null,
                    'children' => [],
                    'path' => ['Test']
                ]
            ],
            'objects' => [
                'Test' => [
                    'address' => [],
                    'address-group' => [
                        'group-a' => ['kind' => 'static', 'members' => ['group-b'], 'match' => null],
                        'group-b' => ['kind' => 'static', 'members' => ['group-a'], 'match' => null]
                    ],
                    'service' => [],
                    'service-group' => [],
                    'application' => [],
                    'application-group' => []
                ],
                'Shared' => [
                    'address' => [],
                    'address-group' => [],
                    'service' => [],
                    'service-group' => [],
                    'application' => [],
                    'application-group' => []
                ]
            ],
            'zones' => []
        ];
        
        $cycleDereferencer = new Dereferencer($cycleCatalog);
        $result = $cycleDereferencer->expandAddresses('Test', ['group-a']);
        
        // Should detect cycle and return cycle marker
        $this->assertContains('CYCLE:group-a', $result);
    }

    public function test_cycle_detection_in_service_groups()
    {
        // Create a catalog with circular reference in services
        $cycleCatalog = [
            'deviceGroups' => [
                'Test' => [
                    'name' => 'Test',
                    'parent' => null,
                    'children' => [],
                    'path' => ['Test']
                ]
            ],
            'objects' => [
                'Test' => [
                    'address' => [],
                    'address-group' => [],
                    'service' => [],
                    'service-group' => [
                        'svc-group-a' => ['members' => ['svc-group-b']],
                        'svc-group-b' => ['members' => ['svc-group-a']]
                    ],
                    'application' => [],
                    'application-group' => []
                ],
                'Shared' => [
                    'address' => [],
                    'address-group' => [],
                    'service' => [],
                    'service-group' => [],
                    'application' => [],
                    'application-group' => []
                ]
            ],
            'zones' => []
        ];
        
        $cycleDereferencer = new Dereferencer($cycleCatalog);
        $result = $cycleDereferencer->expandServices('Test', ['svc-group-a']);
        
        // Should detect cycle and return cycle marker
        $this->assertContains('CYCLE:svc-group-a', $result);
    }

    public function test_cycle_detection_in_application_groups()
    {
        // Create a catalog with circular reference in applications
        $cycleCatalog = [
            'deviceGroups' => [
                'Test' => [
                    'name' => 'Test',
                    'parent' => null,
                    'children' => [],
                    'path' => ['Test']
                ]
            ],
            'objects' => [
                'Test' => [
                    'address' => [],
                    'address-group' => [],
                    'service' => [],
                    'service-group' => [],
                    'application' => [],
                    'application-group' => [
                        'app-group-a' => ['members' => ['app-group-b']],
                        'app-group-b' => ['members' => ['app-group-a']]
                    ]
                ],
                'Shared' => [
                    'address' => [],
                    'address-group' => [],
                    'service' => [],
                    'service-group' => [],
                    'application' => [],
                    'application-group' => []
                ]
            ],
            'zones' => []
        ];
        
        $cycleDereferencer = new Dereferencer($cycleCatalog);
        $result = $cycleDereferencer->expandApplications('Test', ['app-group-a']);
        
        // Should detect cycle and return cycle marker
        $this->assertContains('CYCLE:app-group-a', $result);
    }

    public function test_inheritance_path_with_nonexistent_device_group()
    {
        $result = $this->dereferencer->expandAddresses('NonexistentDG', ['shared-addr1']);
        
        // Should still find shared objects
        $this->assertEquals(['10.0.0.1'], $result);
    }

    public function test_service_with_no_ports()
    {
        // Add a service with no ports to test catalog
        $this->testCatalog['objects']['Shared']['service']['no-port-svc'] = ['proto' => 'icmp', 'ports' => []];
        $dereferencer = new Dereferencer($this->testCatalog);
        
        $result = $dereferencer->expandServices('Root', ['no-port-svc']);
        
        $this->assertEquals(['icmp'], $result);
    }

    public function test_complex_nested_group_expansion()
    {
        // Test a complex scenario with nested groups across inheritance hierarchy
        $result = $this->dereferencer->expandAddresses('Grandchild1', ['shared-group1']);
        
        // shared-group1 contains shared-addr1 and shared-addr2
        $expected = ['10.0.0.1', '192.168.1.0/24'];
        $this->assertEquals($expected, $result);
    }

    public function test_mixed_group_expansion_with_dynamic_and_unknown()
    {
        // Test expansion that includes static groups, dynamic groups, and unknown references
        $result = $this->dereferencer->expandAddresses('Root', [
            'root-group1',           // static group
            'shared-dynamic-group',  // dynamic group
            'nonexistent-addr'       // unknown reference
        ]);
        
        // Should expand static group and mark dynamic/unknown appropriately
        $expected = ['10.1.0.1', '10.0.0.1', 'DAG:shared-dynamic-group', 'UNKNOWN:nonexistent-addr'];
        $this->assertEquals($expected, $result);
    }

    public function test_deep_nested_group_expansion()
    {
        // Add a deeply nested group structure to test catalog
        $deepCatalog = $this->testCatalog;
        $deepCatalog['objects']['Shared']['address-group']['level1'] = [
            'kind' => 'static', 
            'members' => ['level2'], 
            'match' => null
        ];
        $deepCatalog['objects']['Shared']['address-group']['level2'] = [
            'kind' => 'static', 
            'members' => ['level3'], 
            'match' => null
        ];
        $deepCatalog['objects']['Shared']['address-group']['level3'] = [
            'kind' => 'static', 
            'members' => ['shared-addr1'], 
            'match' => null
        ];
        
        $deepDereferencer = new Dereferencer($deepCatalog);
        $result = $deepDereferencer->expandAddresses('Root', ['level1']);
        
        // Should resolve through all levels to the final address
        $this->assertEquals(['10.0.0.1'], $result);
    }

    public function test_self_referencing_group_cycle_detection()
    {
        // Test a group that references itself
        $selfRefCatalog = [
            'deviceGroups' => [
                'Test' => [
                    'name' => 'Test',
                    'parent' => null,
                    'children' => [],
                    'path' => ['Test']
                ]
            ],
            'objects' => [
                'Test' => [
                    'address' => [],
                    'address-group' => [
                        'self-ref' => ['kind' => 'static', 'members' => ['self-ref'], 'match' => null]
                    ],
                    'service' => [],
                    'service-group' => [],
                    'application' => [],
                    'application-group' => []
                ],
                'Shared' => [
                    'address' => [],
                    'address-group' => [],
                    'service' => [],
                    'service-group' => [],
                    'application' => [],
                    'application-group' => []
                ]
            ],
            'zones' => []
        ];
        
        $selfRefDereferencer = new Dereferencer($selfRefCatalog);
        $result = $selfRefDereferencer->expandAddresses('Test', ['self-ref']);
        
        // Should detect self-reference cycle
        $this->assertContains('CYCLE:self-ref', $result);
    }

    public function test_handles_invalid_address_names()
    {
        // Test with empty, null, and non-string values
        $result = $this->dereferencer->expandAddresses('Root', ['', null, 123, []]);
        
        // Should handle gracefully and continue processing
        $this->assertIsArray($result);
        // Invalid names should be filtered out or marked as unknown
    }

    public function test_handles_malformed_catalog_structure()
    {
        // Create catalog with missing or malformed structure
        $malformedCatalog = [
            'deviceGroups' => [
                'Test' => [
                    'name' => 'Test',
                    'path' => ['Test']
                    // Missing other required fields
                ]
            ],
            'objects' => [
                'Test' => [
                    'address' => [
                        'malformed-addr' => ['kind' => 'ip'] // Missing 'value' field
                    ],
                    'address-group' => [
                        'malformed-group' => ['kind' => 'static'] // Missing 'members' field
                    ]
                ]
            ],
            'zones' => []
        ];
        
        $malformedDereferencer = new Dereferencer($malformedCatalog);
        
        // Should handle malformed address gracefully
        $result = $malformedDereferencer->expandAddresses('Test', ['malformed-addr']);
        $this->assertIsArray($result);
        
        // Should handle malformed group gracefully
        $result = $malformedDereferencer->expandAddresses('Test', ['malformed-group']);
        $this->assertIsArray($result);
    }

    public function test_continues_processing_after_individual_failures()
    {
        // Test that processing continues even when individual expansions fail
        $result = $this->dereferencer->expandAddresses('Root', [
            'root-addr1',        // valid
            'nonexistent-addr',  // unknown
            'shared-addr1'       // valid
        ]);
        
        // Should process all addresses despite the unknown one
        $expected = ['10.1.0.1', 'UNKNOWN:nonexistent-addr', '10.0.0.1'];
        $this->assertEquals($expected, $result);
    }

    public function test_handles_corrupted_group_members()
    {
        // Create catalog with corrupted group member data
        $corruptedCatalog = [
            'deviceGroups' => [
                'Test' => [
                    'name' => 'Test',
                    'parent' => null,
                    'children' => [],
                    'path' => ['Test']
                ]
            ],
            'objects' => [
                'Test' => [
                    'address' => [
                        'valid-addr' => ['kind' => 'ip', 'value' => '10.1.1.1']
                    ],
                    'address-group' => [
                        'corrupted-group' => [
                            'kind' => 'static', 
                            'members' => ['valid-addr', null, '', 'nonexistent'], // Mixed valid/invalid
                            'match' => null
                        ]
                    ],
                    'service' => [],
                    'service-group' => [],
                    'application' => [],
                    'application-group' => []
                ],
                'Shared' => [
                    'address' => [],
                    'address-group' => [],
                    'service' => [],
                    'service-group' => [],
                    'application' => [],
                    'application-group' => []
                ]
            ],
            'zones' => []
        ];
        
        $corruptedDereferencer = new Dereferencer($corruptedCatalog);
        $result = $corruptedDereferencer->expandAddresses('Test', ['corrupted-group']);
        
        // Should process valid members and handle invalid ones gracefully
        $this->assertContains('10.1.1.1', $result);
        $this->assertContains('UNKNOWN:nonexistent', $result);
    }

    public function test_handles_missing_object_types()
    {
        // Create catalog missing certain object type arrays
        $incompleteCatalog = [
            'deviceGroups' => [
                'Test' => [
                    'name' => 'Test',
                    'parent' => null,
                    'children' => [],
                    'path' => ['Test']
                ]
            ],
            'objects' => [
                'Test' => [
                    'address' => [
                        'test-addr' => ['kind' => 'ip', 'value' => '10.1.1.1']
                    ]
                    // Missing other object types
                ],
                'Shared' => [
                    // Completely empty
                ]
            ],
            'zones' => []
        ];
        
        $incompleteDereferencer = new Dereferencer($incompleteCatalog);
        
        // Should handle missing object types gracefully
        $result = $incompleteDereferencer->expandServices('Test', ['any-service']);
        $this->assertEquals(['UNKNOWN:any-service'], $result);
        
        $result = $incompleteDereferencer->expandApplications('Test', ['any-app']);
        $this->assertEquals(['UNKNOWN:any-app'], $result);
    }

    public function test_handles_xpath_like_errors_gracefully()
    {
        // Test with catalog that might cause internal processing errors
        $result = $this->dereferencer->expandAddresses('Root', ['shared-addr1']);
        
        // Should complete successfully despite any internal processing challenges
        $this->assertEquals(['10.0.0.1'], $result);
    }

    public function test_recovers_from_service_formatting_errors()
    {
        // Create catalog with malformed service data
        $malformedServiceCatalog = [
            'deviceGroups' => [
                'Test' => [
                    'name' => 'Test',
                    'parent' => null,
                    'children' => [],
                    'path' => ['Test']
                ]
            ],
            'objects' => [
                'Test' => [
                    'address' => [],
                    'address-group' => [],
                    'service' => [
                        'malformed-svc' => ['proto' => 'tcp'], // Missing 'ports' field
                        'empty-ports-svc' => ['proto' => 'udp', 'ports' => []], // Empty ports
                        'null-ports-svc' => ['proto' => 'tcp', 'ports' => null] // Null ports
                    ],
                    'service-group' => [],
                    'application' => [],
                    'application-group' => []
                ],
                'Shared' => [
                    'address' => [],
                    'address-group' => [],
                    'service' => [],
                    'service-group' => [],
                    'application' => [],
                    'application-group' => []
                ]
            ],
            'zones' => []
        ];
        
        $malformedDereferencer = new Dereferencer($malformedServiceCatalog);
        
        // Should handle malformed services gracefully
        $result = $malformedDereferencer->expandServices('Test', ['malformed-svc']);
        $this->assertIsArray($result);
        
        $result = $malformedDereferencer->expandServices('Test', ['empty-ports-svc']);
        $this->assertIsArray($result);
        
        $result = $malformedDereferencer->expandServices('Test', ['null-ports-svc']);
        $this->assertIsArray($result);
    }
}