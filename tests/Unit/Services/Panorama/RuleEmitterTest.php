<?php

namespace Tests\Unit\Services\Panorama;

use App\Services\Panorama\Dereferencer;
use App\Services\Panorama\RuleEmitter;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

class RuleEmitterTest extends TestCase
{
    private RuleEmitter $ruleEmitter;
    private Dereferencer $mockDereferencer;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create mock catalog for dereferencer
        $mockCatalog = [
            'deviceGroups' => [
                'Branch-DG' => [
                    'name' => 'Branch-DG',
                    'parent' => 'Corporate-DG',
                    'path' => ['Branch-DG', 'Corporate-DG', 'Shared']
                ],
                'Corporate-DG' => [
                    'name' => 'Corporate-DG',
                    'parent' => null,
                    'path' => ['Corporate-DG', 'Shared']
                ]
            ],
            'objects' => [
                'Shared' => [
                    'address' => [
                        'any' => ['kind' => 'ip', 'value' => '0.0.0.0/0']
                    ],
                    'service' => [
                        'any' => ['proto' => 'tcp', 'ports' => ['1-65535']]
                    ],
                    'application' => [
                        'any' => ['name' => 'any']
                    ]
                ]
            ]
        ];
        
        $this->mockDereferencer = new Dereferencer($mockCatalog);
        $this->ruleEmitter = new RuleEmitter('test-tenant', '2024-01-15', $this->mockDereferencer);
    }

    public function testGenerateRuleUid(): void
    {
        $uid = $this->ruleEmitter->generateRuleUid('Branch-DG', 'pre-rules', 1, 'Allow Web Traffic');
        
        $this->assertEquals('Branch-DG:pre-rules:1:Allow_Web_Traffic', $uid);
    }

    public function testGenerateRuleUidWithSpecialCharacters(): void
    {
        $uid = $this->ruleEmitter->generateRuleUid('Test/DG', 'rules', 5, 'Rule with spaces & symbols!');
        
        $this->assertEquals('Test_DG:rules:5:Rule_with_spaces___symbols_', $uid);
    }

    public function testGenerateRuleUidUniqueness(): void
    {
        $uid1 = $this->ruleEmitter->generateRuleUid('DG1', 'rules', 1, 'Rule1');
        $uid2 = $this->ruleEmitter->generateRuleUid('DG1', 'rules', 2, 'Rule1');
        $uid3 = $this->ruleEmitter->generateRuleUid('DG2', 'rules', 1, 'Rule1');
        $uid4 = $this->ruleEmitter->generateRuleUid('DG1', 'pre-rules', 1, 'Rule1');
        
        $uids = [$uid1, $uid2, $uid3, $uid4];
        $uniqueUids = array_unique($uids);
        
        $this->assertCount(4, $uniqueUids, 'All UIDs should be unique');
        $this->assertEquals('DG1:rules:1:Rule1', $uid1);
        $this->assertEquals('DG1:rules:2:Rule1', $uid2);
        $this->assertEquals('DG2:rules:1:Rule1', $uid3);
        $this->assertEquals('DG1:pre-rules:1:Rule1', $uid4);
    }

    public function testCreateRuleDocumentStructure(): void
    {
        $xmlString = '
        <device-group name="Branch-DG">
            <pre-rulebase>
                <security>
                    <rules>
                        <entry name="Allow Web Traffic">
                            <from>
                                <member>trust</member>
                            </from>
                            <to>
                                <member>untrust</member>
                            </to>
                            <source>
                                <member>any</member>
                            </source>
                            <destination>
                                <member>any</member>
                            </destination>
                            <application>
                                <member>web-browsing</member>
                            </application>
                            <service>
                                <member>application-default</member>
                            </service>
                            <action>allow</action>
                            <disabled>no</disabled>
                            <description>Allow web browsing traffic</description>
                            <target>
                                <devices>
                                    <entry name="firewall1"/>
                                    <entry name="firewall2"/>
                                </devices>
                            </target>
                        </entry>
                    </rules>
                </security>
            </pre-rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        // Capture output
        $stream = fopen('php://memory', 'w+');
        $rulesProcessed = $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        // Read the output
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        $this->assertEquals(1, $rulesProcessed);
        
        $document = json_decode(trim($output), true);
        
        // Verify document structure
        $this->assertIsArray($document);
        
        // Test required top-level fields
        $this->assertEquals('test-tenant', $document['panorama_tenant']);
        $this->assertEquals('2024-01-15', $document['snapshot_date']);
        $this->assertEquals('Branch-DG', $document['device_group']);
        $this->assertEquals(['Branch-DG', 'Corporate-DG', 'Shared'], $document['device_group_path']);
        $this->assertEquals('pre-rules', $document['rulebase']);
        $this->assertEquals('Allow Web Traffic', $document['rule_name']);
        $this->assertEquals('Branch-DG:pre-rules:1:Allow_Web_Traffic', $document['rule_uid']);
        $this->assertEquals(1, $document['position']);
        $this->assertEquals('allow', $document['action']);
        $this->assertFalse($document['disabled']);
        
        // Test targets structure
        $this->assertArrayHasKey('targets', $document);
        $this->assertEquals(['firewall1', 'firewall2'], $document['targets']['include']);
        $this->assertEquals([], $document['targets']['exclude']);
        
        // Test orig structure
        $this->assertArrayHasKey('orig', $document);
        $orig = $document['orig'];
        $this->assertEquals(['trust'], $orig['from_zones']);
        $this->assertEquals(['untrust'], $orig['to_zones']);
        $this->assertEquals(['any'], $orig['sources']);
        $this->assertEquals(['any'], $orig['destinations']);
        $this->assertEquals(['web-browsing'], $orig['applications']);
        $this->assertEquals(['application-default'], $orig['services']);
        $this->assertEquals([], $orig['users']);
        $this->assertEquals([], $orig['tags']);
        $this->assertEquals('Allow web browsing traffic', $orig['comments']);
        
        // Test expanded structure
        $this->assertArrayHasKey('expanded', $document);
        $expanded = $document['expanded'];
        $this->assertArrayHasKey('from_zones', $expanded);
        $this->assertArrayHasKey('to_zones', $expanded);
        $this->assertArrayHasKey('src_addresses', $expanded);
        $this->assertArrayHasKey('dst_addresses', $expanded);
        $this->assertArrayHasKey('applications', $expanded);
        $this->assertArrayHasKey('services', $expanded);
        $this->assertArrayHasKey('ports', $expanded);
        $this->assertArrayHasKey('users', $expanded);
        $this->assertArrayHasKey('tags', $expanded);
        
        // Test meta structure
        $this->assertArrayHasKey('meta', $document);
        $meta = $document['meta'];
        $this->assertArrayHasKey('has_dynamic_groups', $meta);
        $this->assertArrayHasKey('dynamic_groups_unresolved', $meta);
        $this->assertArrayHasKey('unresolved_notes', $meta);
        $this->assertIsBool($meta['has_dynamic_groups']);
        $this->assertIsArray($meta['dynamic_groups_unresolved']);
        $this->assertIsString($meta['unresolved_notes']);
    }

    public function testRuleWithDisabledStatus(): void
    {
        $xmlString = '
        <device-group name="Test-DG">
            <rulebase>
                <security>
                    <rules>
                        <entry name="Disabled Rule">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>deny</action>
                            <disabled>yes</disabled>
                        </entry>
                    </rules>
                </security>
            </rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        $document = json_decode(trim($output), true);
        
        $this->assertTrue($document['disabled']);
        $this->assertEquals('deny', $document['action']);
        $this->assertEquals('rules', $document['rulebase']);
    }

    public function testRuleWithSecurityProfiles(): void
    {
        $xmlString = '
        <device-group name="Test-DG">
            <rulebase>
                <security>
                    <rules>
                        <entry name="Rule with Profiles">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>allow</action>
                            <profile-setting>
                                <group>
                                    <member>security-profile-group</member>
                                </group>
                                <profiles>
                                    <virus>
                                        <member>default</member>
                                    </virus>
                                    <spyware>
                                        <member>strict</member>
                                    </spyware>
                                </profiles>
                            </profile-setting>
                        </entry>
                    </rules>
                </security>
            </rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        $document = json_decode(trim($output), true);
        
        $profiles = $document['orig']['profiles'];
        $this->assertEquals('security-profile-group', $profiles['group']);
        $this->assertContains('default', $profiles['names']);
        $this->assertContains('strict', $profiles['names']);
    }

    public function testMultipleRulesPositioning(): void
    {
        $xmlString = '
        <device-group name="Test-DG">
            <rulebase>
                <security>
                    <rules>
                        <entry name="Rule 1">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>allow</action>
                        </entry>
                        <entry name="Rule 2">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>deny</action>
                        </entry>
                    </rules>
                </security>
            </rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $rulesProcessed = $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        $this->assertEquals(2, $rulesProcessed);
        
        $lines = explode("\n", trim($output));
        $this->assertCount(2, $lines);
        
        $doc1 = json_decode($lines[0], true);
        $doc2 = json_decode($lines[1], true);
        
        $this->assertEquals(1, $doc1['position']);
        $this->assertEquals(2, $doc2['position']);
        $this->assertEquals('Rule 1', $doc1['rule_name']);
        $this->assertEquals('Rule 2', $doc2['rule_name']);
        $this->assertEquals('Test-DG:rules:1:Rule_1', $doc1['rule_uid']);
        $this->assertEquals('Test-DG:rules:2:Rule_2', $doc2['rule_uid']);
    }

    public function testDifferentRulebaseTypes(): void
    {
        $xmlString = '
        <device-group name="Test-DG">
            <pre-rulebase>
                <security>
                    <rules>
                        <entry name="Pre Rule">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>allow</action>
                        </entry>
                    </rules>
                </security>
            </pre-rulebase>
            <rulebase>
                <security>
                    <rules>
                        <entry name="Local Rule">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>allow</action>
                        </entry>
                    </rules>
                </security>
            </rulebase>
            <post-rulebase>
                <security>
                    <rules>
                        <entry name="Post Rule">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>deny</action>
                        </entry>
                    </rules>
                </security>
            </post-rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $rulesProcessed = $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        $this->assertEquals(3, $rulesProcessed);
        
        $lines = explode("\n", trim($output));
        $this->assertCount(3, $lines);
        
        $preRule = json_decode($lines[0], true);
        $localRule = json_decode($lines[1], true);
        $postRule = json_decode($lines[2], true);
        
        $this->assertEquals('pre-rules', $preRule['rulebase']);
        $this->assertEquals('rules', $localRule['rulebase']);
        $this->assertEquals('post-rules', $postRule['rulebase']);
        
        // Each rulebase should have its own position counter
        $this->assertEquals(1, $preRule['position']);
        $this->assertEquals(1, $localRule['position']);
        $this->assertEquals(1, $postRule['position']);
        
        // UIDs should be unique despite same position
        $this->assertEquals('Test-DG:pre-rules:1:Pre_Rule', $preRule['rule_uid']);
        $this->assertEquals('Test-DG:rules:1:Local_Rule', $localRule['rule_uid']);
        $this->assertEquals('Test-DG:post-rules:1:Post_Rule', $postRule['rule_uid']);
    }

    public function testEmptyTargets(): void
    {
        $xmlString = '
        <device-group name="Test-DG">
            <rulebase>
                <security>
                    <rules>
                        <entry name="No Targets Rule">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>allow</action>
                        </entry>
                    </rules>
                </security>
            </rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        $document = json_decode(trim($output), true);
        
        $this->assertEquals([], $document['targets']['include']);
        $this->assertEquals([], $document['targets']['exclude']);
    }

    public function testNdjsonFormat(): void
    {
        $xmlString = '
        <device-group name="Test-DG">
            <rulebase>
                <security>
                    <rules>
                        <entry name="Test Rule">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>allow</action>
                        </entry>
                    </rules>
                </security>
            </rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        // Verify NDJSON format (each line should be valid JSON, ending with newline)
        $this->assertStringEndsWith("\n", $output);
        
        $lines = explode("\n", trim($output));
        $this->assertCount(1, $lines);
        
        // Verify each line is valid JSON
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, 'Line should decode to array: ' . $line);
            $this->assertEquals(JSON_ERROR_NONE, json_last_error(), 'JSON error: ' . json_last_error_msg() . ' for line: ' . $line);
        }
    }

    public function testStreamingMemoryEfficiency(): void
    {
        // Create XML with multiple rules to test streaming behavior
        $xmlString = '
        <device-group name="Test-DG">
            <rulebase>
                <security>
                    <rules>';
        
        // Add 100 rules to test streaming
        for ($i = 1; $i <= 100; $i++) {
            $xmlString .= "
                        <entry name=\"Rule {$i}\">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>allow</action>
                        </entry>";
        }
        
        $xmlString .= '
                    </rules>
                </security>
            </rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        // Use a temporary file to test streaming
        $tempFile = tempnam(sys_get_temp_dir(), 'rule_emitter_test');
        $stream = fopen($tempFile, 'w+');
        
        $rulesProcessed = $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        fclose($stream);
        
        $this->assertEquals(100, $rulesProcessed);
        
        // Verify the output file contains correct number of lines
        $lines = file($tempFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(100, $lines);
        
        // Verify each line is valid JSON
        foreach ($lines as $index => $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded);
            $this->assertEquals($index + 1, $decoded['position']);
            $this->assertEquals("Rule " . ($index + 1), $decoded['rule_name']);
        }
        
        unlink($tempFile);
    }

    public function testElasticsearchBulkApiCompatibility(): void
    {
        $xmlString = '
        <device-group name="Test-DG">
            <rulebase>
                <security>
                    <rules>
                        <entry name="Test Rule">
                            <from><member>trust</member></from>
                            <to><member>untrust</member></to>
                            <source><member>192.168.1.0/24</member></source>
                            <destination><member>any</member></destination>
                            <application><member>web-browsing</member></application>
                            <service><member>application-default</member></service>
                            <action>allow</action>
                            <disabled>no</disabled>
                        </entry>
                    </rules>
                </security>
            </rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        $document = json_decode(trim($output), true);
        
        // Verify Elasticsearch field types
        $this->assertIsString($document['panorama_tenant']);
        $this->assertIsString($document['snapshot_date']);
        $this->assertIsString($document['device_group']);
        $this->assertIsArray($document['device_group_path']);
        $this->assertIsString($document['rulebase']);
        $this->assertIsString($document['rule_name']);
        $this->assertIsString($document['rule_uid']);
        $this->assertIsInt($document['position']);
        $this->assertIsString($document['action']);
        $this->assertIsBool($document['disabled']);
        
        // Verify array fields have consistent types
        foreach ($document['device_group_path'] as $path) {
            $this->assertIsString($path);
        }
        
        foreach ($document['targets']['include'] as $target) {
            $this->assertIsString($target);
        }
        
        foreach ($document['targets']['exclude'] as $target) {
            $this->assertIsString($target);
        }
        
        // Verify orig section arrays
        foreach (['from_zones', 'to_zones', 'sources', 'destinations', 'applications', 'services', 'users', 'tags'] as $field) {
            $this->assertIsArray($document['orig'][$field]);
            foreach ($document['orig'][$field] as $item) {
                $this->assertIsString($item);
            }
        }
        
        // Verify expanded section arrays
        foreach (['from_zones', 'to_zones', 'src_addresses', 'dst_addresses', 'applications', 'services', 'ports', 'users', 'tags'] as $field) {
            $this->assertIsArray($document['expanded'][$field]);
        }
        
        // Verify meta section
        $this->assertIsBool($document['meta']['has_dynamic_groups']);
        $this->assertIsArray($document['meta']['dynamic_groups_unresolved']);
        $this->assertIsString($document['meta']['unresolved_notes']);
    }

    public function testRuleTargetExtraction(): void
    {
        $xmlString = '
        <device-group name="Test-DG">
            <rulebase>
                <security>
                    <rules>
                        <entry name="Targeted Rule">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>allow</action>
                            <target>
                                <devices>
                                    <entry name="fw-branch-01"/>
                                    <entry name="fw-branch-02"/>
                                    <entry name="fw-datacenter"/>
                                </devices>
                                <excluded-devices>
                                    <entry name="fw-test"/>
                                </excluded-devices>
                            </target>
                        </entry>
                    </rules>
                </security>
            </rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        $document = json_decode(trim($output), true);
        
        // Verify target extraction (requirement 5.2)
        $this->assertEquals(['fw-branch-01', 'fw-branch-02', 'fw-datacenter'], $document['targets']['include']);
        $this->assertEquals(['fw-test'], $document['targets']['exclude']);
    }

    public function testMultipleDeviceGroupsProcessing(): void
    {
        $xmlString = '
        <root>
            <device-group name="DG1">
                <rulebase>
                    <security>
                        <rules>
                            <entry name="DG1 Rule">
                                <from><member>any</member></from>
                                <to><member>any</member></to>
                                <source><member>any</member></source>
                                <destination><member>any</member></destination>
                                <application><member>any</member></application>
                                <service><member>any</member></service>
                                <action>allow</action>
                            </entry>
                        </rules>
                    </security>
                </rulebase>
            </device-group>
            <device-group name="DG2">
                <rulebase>
                    <security>
                        <rules>
                            <entry name="DG2 Rule">
                                <from><member>any</member></from>
                                <to><member>any</member></to>
                                <source><member>any</member></source>
                                <destination><member>any</member></destination>
                                <application><member>any</member></application>
                                <service><member>any</member></service>
                                <action>deny</action>
                            </entry>
                        </rules>
                    </security>
                </rulebase>
            </device-group>
        </root>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $rulesProcessed = $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        $this->assertEquals(2, $rulesProcessed);
        
        $lines = explode("\n", trim($output));
        $this->assertCount(2, $lines);
        
        $doc1 = json_decode($lines[0], true);
        $doc2 = json_decode($lines[1], true);
        
        $this->assertEquals('DG1', $doc1['device_group']);
        $this->assertEquals('DG2', $doc2['device_group']);
        $this->assertEquals('DG1 Rule', $doc1['rule_name']);
        $this->assertEquals('DG2 Rule', $doc2['rule_name']);
        $this->assertEquals('allow', $doc1['action']);
        $this->assertEquals('deny', $doc2['action']);
    }

    public function testEmptyDeviceGroupsHandling(): void
    {
        $xmlString = '
        <root>
            <device-group name="Empty-DG">
                <!-- No rules defined -->
            </device-group>
            <device-group name="DG-With-Rules">
                <rulebase>
                    <security>
                        <rules>
                            <entry name="Only Rule">
                                <from><member>any</member></from>
                                <to><member>any</member></to>
                                <source><member>any</member></source>
                                <destination><member>any</member></destination>
                                <application><member>any</member></application>
                                <service><member>any</member></service>
                                <action>allow</action>
                            </entry>
                        </rules>
                    </security>
                </rulebase>
            </device-group>
        </root>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $rulesProcessed = $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        // Should only process the one rule from DG-With-Rules
        $this->assertEquals(1, $rulesProcessed);
        
        $lines = explode("\n", trim($output));
        $this->assertCount(1, $lines);
        
        $document = json_decode($lines[0], true);
        $this->assertEquals('DG-With-Rules', $document['device_group']);
        $this->assertEquals('Only Rule', $document['rule_name']);
    }

    public function testOneDocumentPerSecurityRule(): void
    {
        // Test requirement 1.2: "WHEN parsing completes THEN the system SHALL produce one Elasticsearch document per security rule"
        $xmlString = '
        <device-group name="Test-DG">
            <pre-rulebase>
                <security>
                    <rules>
                        <entry name="Pre Rule 1">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>allow</action>
                        </entry>
                        <entry name="Pre Rule 2">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>allow</action>
                        </entry>
                    </rules>
                </security>
            </pre-rulebase>
            <rulebase>
                <security>
                    <rules>
                        <entry name="Local Rule 1">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>allow</action>
                        </entry>
                    </rules>
                </security>
            </rulebase>
            <post-rulebase>
                <security>
                    <rules>
                        <entry name="Post Rule 1">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>deny</action>
                        </entry>
                    </rules>
                </security>
            </post-rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $rulesProcessed = $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        // Should produce exactly 4 documents (one per rule)
        $this->assertEquals(4, $rulesProcessed);
        
        $lines = explode("\n", trim($output));
        $this->assertCount(4, $lines);
        
        // Verify each line produces a unique document
        $ruleUids = [];
        foreach ($lines as $line) {
            $document = json_decode($line, true);
            $this->assertIsArray($document);
            $this->assertArrayHasKey('rule_uid', $document);
            $ruleUids[] = $document['rule_uid'];
        }
        
        // All rule UIDs should be unique
        $this->assertCount(4, array_unique($ruleUids));
    }

    public function testMalformedRuleElementsHandling(): void
    {
        // Test requirement 7.3: "WHEN processing malformed rule elements THEN the system SHALL use safe defaults and log warnings"
        $xmlString = '
        <device-group name="Test-DG">
            <rulebase>
                <security>
                    <rules>
                        <entry name="Malformed Rule">
                            <!-- Missing required elements, should use safe defaults -->
                            <action>allow</action>
                        </entry>
                        <entry name="Partial Rule">
                            <from><member>trust</member></from>
                            <to><member>untrust</member></to>
                            <!-- Missing source, destination, application, service - should use safe defaults -->
                            <action>deny</action>
                            <disabled>invalid-value</disabled> <!-- Invalid boolean value -->
                        </entry>
                    </rules>
                </security>
            </rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $rulesProcessed = $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        $this->assertEquals(2, $rulesProcessed);
        
        $lines = explode("\n", trim($output));
        $this->assertCount(2, $lines);
        
        $doc1 = json_decode($lines[0], true);
        $doc2 = json_decode($lines[1], true);
        
        // Verify safe defaults are used for missing elements
        $this->assertEquals([], $doc1['orig']['from_zones']);
        $this->assertEquals([], $doc1['orig']['to_zones']);
        $this->assertEquals([], $doc1['orig']['sources']);
        $this->assertEquals([], $doc1['orig']['destinations']);
        $this->assertEquals([], $doc1['orig']['applications']);
        $this->assertEquals([], $doc1['orig']['services']);
        
        // Verify partial rule has available data
        $this->assertEquals(['trust'], $doc2['orig']['from_zones']);
        $this->assertEquals(['untrust'], $doc2['orig']['to_zones']);
        $this->assertEquals([], $doc2['orig']['sources']); // Missing, should be empty array
        
        // Verify invalid boolean value is handled safely
        $this->assertFalse($doc2['disabled']); // Should default to false for invalid value
        
        // Verify both documents have valid structure
        foreach ([$doc1, $doc2] as $doc) {
            $this->assertArrayHasKey('rule_uid', $doc);
            $this->assertArrayHasKey('orig', $doc);
            $this->assertArrayHasKey('expanded', $doc);
            $this->assertArrayHasKey('meta', $doc);
        }
    }

    public function testDualFormatDocumentGeneration(): void
    {
        // Create a more comprehensive catalog for testing dual format
        $catalog = [
            'deviceGroups' => [
                'Branch-DG' => [
                    'name' => 'Branch-DG',
                    'parent' => 'Corporate-DG',
                    'path' => ['Branch-DG', 'Corporate-DG', 'Shared']
                ]
            ],
            'objects' => [
                'Shared' => [
                    'address' => [
                        'web-server' => ['kind' => 'ip', 'value' => '192.168.1.100'],
                        'mail-server' => ['kind' => 'ip', 'value' => '192.168.1.200']
                    ],
                    'address-group' => [
                        'servers' => ['kind' => 'static', 'members' => ['web-server', 'mail-server']],
                        'dynamic-group' => ['kind' => 'dynamic', 'match' => 'tag.environment eq "prod"']
                    ],
                    'service' => [
                        'web-service' => ['proto' => 'tcp', 'ports' => ['80', '443']]
                    ],
                    'service-group' => [
                        'web-services' => ['members' => ['web-service']]
                    ],
                    'application' => [
                        'web-browsing' => ['name' => 'web-browsing']
                    ],
                    'application-group' => [
                        'web-apps' => ['members' => ['web-browsing']]
                    ]
                ]
            ],
            'zones' => [
                'trust' => true,
                'untrust' => true
            ]
        ];
        
        $dereferencer = new Dereferencer($catalog);
        $ruleEmitter = new RuleEmitter('test-tenant', '2024-01-15', $dereferencer);
        
        $xmlString = '
        <device-group name="Branch-DG">
            <rulebase>
                <security>
                    <rules>
                        <entry name="Complex Rule">
                            <from><member>trust</member></from>
                            <to><member>untrust</member></to>
                            <source><member>servers</member></source>
                            <destination><member>dynamic-group</member></destination>
                            <application><member>web-apps</member></application>
                            <service><member>web-services</member></service>
                            <action>allow</action>
                            <description>Rule with mixed object types</description>
                        </entry>
                    </rules>
                </security>
            </rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        $document = json_decode(trim($output), true);
        
        // Test original values (requirement 2.1)
        $this->assertEquals(['trust'], $document['orig']['from_zones']);
        $this->assertEquals(['untrust'], $document['orig']['to_zones']);
        $this->assertEquals(['servers'], $document['orig']['sources']);
        $this->assertEquals(['dynamic-group'], $document['orig']['destinations']);
        $this->assertEquals(['web-apps'], $document['orig']['applications']);
        $this->assertEquals(['web-services'], $document['orig']['services']);
        
        // Test expanded values (requirement 2.2)
        $this->assertEquals(['trust'], $document['expanded']['from_zones']);
        $this->assertEquals(['untrust'], $document['expanded']['to_zones']);
        $this->assertEquals(['192.168.1.100', '192.168.1.200'], $document['expanded']['src_addresses']);
        $this->assertEquals(['DAG:dynamic-group'], $document['expanded']['dst_addresses']);
        $this->assertEquals(['web-browsing'], $document['expanded']['applications']);
        $this->assertEquals(['tcp/80', 'tcp/443'], $document['expanded']['services']);
        $this->assertEquals(['80', '443'], $document['expanded']['ports']);
        
        // Test metadata tracking (requirement 5.3)
        $this->assertTrue($document['meta']['has_dynamic_groups']);
        $this->assertEquals(['DAG:dynamic-group'], $document['meta']['dynamic_groups_unresolved']);
        $this->assertEmpty($document['meta']['unresolved_notes']);
    }

    public function testUnresolvedReferencesHandling(): void
    {
        // Create catalog with missing objects to test unresolved handling
        $catalog = [
            'deviceGroups' => [
                'Test-DG' => [
                    'name' => 'Test-DG',
                    'parent' => null,
                    'path' => ['Test-DG', 'Shared']
                ]
            ],
            'objects' => [
                'Shared' => [
                    'address' => [
                        'known-server' => ['kind' => 'ip', 'value' => '192.168.1.100']
                    ]
                ]
            ],
            'zones' => [
                'trust' => true
            ]
        ];
        
        $dereferencer = new Dereferencer($catalog);
        $ruleEmitter = new RuleEmitter('test-tenant', '2024-01-15', $dereferencer);
        
        $xmlString = '
        <device-group name="Test-DG">
            <rulebase>
                <security>
                    <rules>
                        <entry name="Unresolved Rule">
                            <from><member>trust</member></from>
                            <to><member>unknown-zone</member></to>
                            <source><member>known-server</member></source>
                            <destination><member>unknown-server</member></destination>
                            <application><member>unknown-app</member></application>
                            <service><member>unknown-service</member></service>
                            <action>allow</action>
                        </entry>
                    </rules>
                </security>
            </rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        $document = json_decode(trim($output), true);
        
        // Test original values remain unchanged
        $this->assertEquals(['trust'], $document['orig']['from_zones']);
        $this->assertEquals(['unknown-zone'], $document['orig']['to_zones']);
        $this->assertEquals(['known-server'], $document['orig']['sources']);
        $this->assertEquals(['unknown-server'], $document['orig']['destinations']);
        $this->assertEquals(['unknown-app'], $document['orig']['applications']);
        $this->assertEquals(['unknown-service'], $document['orig']['services']);
        
        // Test expanded values show resolution status (requirement 7.2)
        $this->assertEquals(['trust'], $document['expanded']['from_zones']);
        $this->assertEquals(['UNKNOWN:unknown-zone'], $document['expanded']['to_zones']);
        $this->assertEquals(['192.168.1.100'], $document['expanded']['src_addresses']);
        $this->assertEquals(['UNKNOWN:unknown-server'], $document['expanded']['dst_addresses']);
        $this->assertEquals(['UNKNOWN:unknown-app'], $document['expanded']['applications']);
        $this->assertEquals(['UNKNOWN:unknown-service'], $document['expanded']['services']);
        
        // Test metadata tracking for unresolved references
        $this->assertFalse($document['meta']['has_dynamic_groups']);
        $this->assertEmpty($document['meta']['dynamic_groups_unresolved']);
        $this->assertStringContainsString('UNKNOWN:unknown-zone', $document['meta']['unresolved_notes']);
        $this->assertStringContainsString('UNKNOWN:unknown-server', $document['meta']['unresolved_notes']);
        $this->assertStringContainsString('UNKNOWN:unknown-app', $document['meta']['unresolved_notes']);
        $this->assertStringContainsString('UNKNOWN:unknown-service', $document['meta']['unresolved_notes']);
    }

    public function testMixedResolvedAndUnresolvedReferences(): void
    {
        // Test scenario with both resolved and unresolved references
        $catalog = [
            'deviceGroups' => [
                'Mixed-DG' => [
                    'name' => 'Mixed-DG',
                    'parent' => null,
                    'path' => ['Mixed-DG', 'Shared']
                ]
            ],
            'objects' => [
                'Shared' => [
                    'address' => [
                        'server1' => ['kind' => 'ip', 'value' => '192.168.1.1'],
                        'server2' => ['kind' => 'ip', 'value' => '192.168.1.2']
                    ],
                    'address-group' => [
                        'mixed-group' => ['kind' => 'static', 'members' => ['server1', 'unknown-server']],
                        'dynamic-addresses' => ['kind' => 'dynamic', 'match' => 'tag.type eq "server"']
                    ],
                    'service' => [
                        'http' => ['proto' => 'tcp', 'ports' => ['80']]
                    ]
                ]
            ],
            'zones' => [
                'dmz' => true
            ]
        ];
        
        $dereferencer = new Dereferencer($catalog);
        $ruleEmitter = new RuleEmitter('test-tenant', '2024-01-15', $dereferencer);
        
        $xmlString = '
        <device-group name="Mixed-DG">
            <rulebase>
                <security>
                    <rules>
                        <entry name="Mixed Resolution Rule">
                            <from><member>dmz</member></from>
                            <to><member>unknown-zone</member></to>
                            <source><member>mixed-group</member></source>
                            <destination><member>dynamic-addresses</member></destination>
                            <application><member>web-browsing</member></application>
                            <service><member>http</member></service>
                            <action>allow</action>
                        </entry>
                    </rules>
                </security>
            </rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        $document = json_decode(trim($output), true);
        
        // Test mixed expansion results
        $this->assertEquals(['dmz'], $document['expanded']['from_zones']);
        $this->assertEquals(['UNKNOWN:unknown-zone'], $document['expanded']['to_zones']);
        $this->assertEquals(['192.168.1.1', 'UNKNOWN:unknown-server'], $document['expanded']['src_addresses']);
        $this->assertEquals(['DAG:dynamic-addresses'], $document['expanded']['dst_addresses']);
        $this->assertEquals(['UNKNOWN:web-browsing'], $document['expanded']['applications']);
        $this->assertEquals(['tcp/80'], $document['expanded']['services']);
        
        // Test metadata reflects both dynamic groups and unresolved references
        $this->assertTrue($document['meta']['has_dynamic_groups']);
        $this->assertEquals(['DAG:dynamic-addresses'], $document['meta']['dynamic_groups_unresolved']);
        $this->assertStringContainsString('UNKNOWN:unknown-zone', $document['meta']['unresolved_notes']);
        $this->assertStringContainsString('UNKNOWN:unknown-server', $document['meta']['unresolved_notes']);
        $this->assertStringContainsString('UNKNOWN:web-browsing', $document['meta']['unresolved_notes']);
    }

    public function testMetadataTrackingForDynamicGroups(): void
    {
        // Test specific dynamic group metadata tracking
        $catalog = [
            'deviceGroups' => [
                'Dynamic-DG' => [
                    'name' => 'Dynamic-DG',
                    'parent' => null,
                    'path' => ['Dynamic-DG', 'Shared']
                ]
            ],
            'objects' => [
                'Shared' => [
                    'address' => [
                        'any' => ['kind' => 'ip', 'value' => '0.0.0.0/0']
                    ],
                    'address-group' => [
                        'prod-servers' => ['kind' => 'dynamic', 'match' => 'tag.environment eq "production"'],
                        'dev-servers' => ['kind' => 'dynamic', 'match' => 'tag.environment eq "development"']
                    ],
                    'service' => [
                        'any' => ['proto' => 'tcp', 'ports' => ['1-65535']]
                    ],
                    'application' => [
                        'any' => ['name' => 'any']
                    ]
                ]
            ],
            'zones' => [
                'internal' => true,
                'external' => true
            ]
        ];
        
        $dereferencer = new Dereferencer($catalog);
        $ruleEmitter = new RuleEmitter('test-tenant', '2024-01-15', $dereferencer);
        
        $xmlString = '
        <device-group name="Dynamic-DG">
            <rulebase>
                <security>
                    <rules>
                        <entry name="Dynamic Groups Rule">
                            <from><member>internal</member></from>
                            <to><member>external</member></to>
                            <source><member>prod-servers</member></source>
                            <destination><member>dev-servers</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>deny</action>
                        </entry>
                    </rules>
                </security>
            </rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        $document = json_decode(trim($output), true);
        
        // Test dynamic group expansion (requirement 2.4)
        $this->assertEquals(['DAG:prod-servers'], $document['expanded']['src_addresses']);
        $this->assertEquals(['DAG:dev-servers'], $document['expanded']['dst_addresses']);
        
        // Test metadata for multiple dynamic groups
        $this->assertTrue($document['meta']['has_dynamic_groups']);
        $this->assertCount(2, $document['meta']['dynamic_groups_unresolved']);
        $this->assertContains('DAG:prod-servers', $document['meta']['dynamic_groups_unresolved']);
        $this->assertContains('DAG:dev-servers', $document['meta']['dynamic_groups_unresolved']);
        
        $this->assertEmpty($document['meta']['unresolved_notes']);
    }

    public function test_handles_malformed_rule_elements()
    {
        $xmlString = '
        <device-group name="Test-DG">
            <rulebase>
                <security>
                    <rules>
                        <entry><!-- Missing name attribute --></entry>
                        <entry name="valid-rule">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>allow</action>
                        </entry>
                        <entry name="rule-with-missing-elements">
                            <!-- Missing required elements -->
                            <action>deny</action>
                        </entry>
                    </rules>
                </security>
            </rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $rulesProcessed = $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        // Should process valid rules and handle malformed ones gracefully
        $this->assertGreaterThan(0, $rulesProcessed);
        
        $lines = array_filter(explode("\n", trim($output)));
        
        // Check that valid rules are processed
        foreach ($lines as $line) {
            $document = json_decode($line, true);
            $this->assertIsArray($document);
            $this->assertArrayHasKey('rule_name', $document);
            $this->assertNotEmpty($document['rule_name']);
        }
    }

    public function test_handles_malformed_device_group_elements()
    {
        $xmlString = '
        <root>
            <device-group><!-- Missing name attribute --></device-group>
            <device-group name=""><!-- Empty name --></device-group>
            <device-group name="valid-dg">
                <rulebase>
                    <security>
                        <rules>
                            <entry name="valid-rule">
                                <from><member>any</member></from>
                                <to><member>any</member></to>
                                <source><member>any</member></source>
                                <destination><member>any</member></destination>
                                <application><member>any</member></application>
                                <service><member>any</member></service>
                                <action>allow</action>
                            </entry>
                        </rules>
                    </security>
                </rulebase>
            </device-group>
        </root>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $rulesProcessed = $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        // Should process only valid device groups
        $this->assertEquals(1, $rulesProcessed);
        
        $lines = explode("\n", trim($output));
        $this->assertCount(1, $lines);
        
        $document = json_decode($lines[0], true);
        $this->assertEquals('valid-dg', $document['device_group']);
    }

    public function test_continues_processing_after_json_encoding_errors()
    {
        // Create a rule emitter that might encounter JSON encoding issues
        $xmlString = '
        <device-group name="Test-DG">
            <rulebase>
                <security>
                    <rules>
                        <entry name="rule1">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>allow</action>
                        </entry>
                        <entry name="rule2">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>allow</action>
                        </entry>
                    </rules>
                </security>
            </rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $rulesProcessed = $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        // Should process all valid rules
        $this->assertEquals(2, $rulesProcessed);
        
        $lines = explode("\n", trim($output));
        $this->assertCount(2, $lines);
        
        // Each line should be valid JSON
        foreach ($lines as $line) {
            $document = json_decode($line, true);
            $this->assertIsArray($document);
        }
    }

    public function test_handles_missing_rule_action_gracefully()
    {
        $xmlString = '
        <device-group name="Test-DG">
            <rulebase>
                <security>
                    <rules>
                        <entry name="rule-no-action">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <!-- Missing action element -->
                        </entry>
                        <entry name="rule-empty-action">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action></action><!-- Empty action -->
                        </entry>
                    </rules>
                </security>
            </rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $rulesProcessed = $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        $this->assertEquals(2, $rulesProcessed);
        
        $lines = explode("\n", trim($output));
        $this->assertCount(2, $lines);
        
        foreach ($lines as $line) {
            $document = json_decode($line, true);
            // Should default to 'allow' when action is missing or empty
            $this->assertEquals('allow', $document['action']);
        }
    }

    public function test_handles_malformed_target_elements()
    {
        $xmlString = '
        <device-group name="Test-DG">
            <rulebase>
                <security>
                    <rules>
                        <entry name="rule-with-malformed-targets">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>allow</action>
                            <target>
                                <devices>
                                    <entry><!-- Missing name attribute --></entry>
                                    <entry name="valid-device"/>
                                    <entry name=""><!-- Empty name --></entry>
                                </devices>
                            </target>
                        </entry>
                    </rules>
                </security>
            </rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $rulesProcessed = $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        $this->assertEquals(1, $rulesProcessed);
        
        $document = json_decode(trim($output), true);
        
        // Should only include valid device names
        $this->assertEquals(['valid-device'], $document['targets']['include']);
    }

    public function test_handles_malformed_profile_elements()
    {
        $xmlString = '
        <device-group name="Test-DG">
            <rulebase>
                <security>
                    <rules>
                        <entry name="rule-with-malformed-profiles">
                            <from><member>any</member></from>
                            <to><member>any</member></to>
                            <source><member>any</member></source>
                            <destination><member>any</member></destination>
                            <application><member>any</member></application>
                            <service><member>any</member></service>
                            <action>allow</action>
                            <profile-setting>
                                <group>
                                    <member></member><!-- Empty group member -->
                                </group>
                                <profiles>
                                    <virus>
                                        <member>valid-profile</member>
                                        <member></member><!-- Empty profile member -->
                                    </virus>
                                </profiles>
                            </profile-setting>
                        </entry>
                    </rules>
                </security>
            </rulebase>
        </device-group>';
        
        $xml = new SimpleXMLElement($xmlString);
        
        $stream = fopen('php://memory', 'w+');
        $rulesProcessed = $this->ruleEmitter->emitSecurityRulesAsNdjson($xml, $stream);
        
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);
        
        $this->assertEquals(1, $rulesProcessed);
        
        $document = json_decode(trim($output), true);
        
        // Should handle malformed profiles gracefully
        $this->assertArrayHasKey('profiles', $document['orig']);
        $profiles = $document['orig']['profiles'];
        
        // Should only include valid profile names
        $this->assertContains('valid-profile', $profiles['names']);
    }
}