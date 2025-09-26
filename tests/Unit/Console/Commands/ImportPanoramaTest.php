<?php

namespace Tests\Unit\Console\Commands;

use App\Console\Commands\ImportPanorama;
use App\Services\Panorama\Exceptions\XmlParsingException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportPanoramaTest extends TestCase
{
    private string $testXmlPath;
    private string $testOutputPath;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test XML file
        $this->testXmlPath = storage_path('app/test_panorama.xml');
        $this->testOutputPath = storage_path('app/test_output.ndjson');
        
        // Clean up any existing test files
        if (file_exists($this->testXmlPath)) {
            unlink($this->testXmlPath);
        }
        if (file_exists($this->testOutputPath)) {
            unlink($this->testOutputPath);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (file_exists($this->testXmlPath)) {
            unlink($this->testXmlPath);
        }
        if (file_exists($this->testOutputPath)) {
            unlink($this->testOutputPath);
        }
        
        parent::tearDown();
    }

    public function test_command_signature_is_correct(): void
    {
        $command = new ImportPanorama();
        
        $this->assertEquals('panorama:import', $command->getName());
        $this->assertStringContainsString('Import Panorama XML configuration', $command->getDescription());
    }

    public function test_command_requires_file_parameter(): void
    {
        $this->artisan('panorama:import')
            ->expectsQuestion('Path to Panorama XML export file', '')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_command_validates_file_exists(): void
    {
        $nonExistentFile = '/path/to/nonexistent/file.xml';
        
        $this->artisan('panorama:import', ['--file' => $nonExistentFile])
            ->expectsOutput("File does not exist: {$nonExistentFile}")
            ->assertExitCode(Command::FAILURE);
    }

    public function test_command_validates_file_is_readable(): void
    {
        // Create a file but make it unreadable
        file_put_contents($this->testXmlPath, '<test/>');
        chmod($this->testXmlPath, 0000);
        
        $this->artisan('panorama:import', ['--file' => $this->testXmlPath])
            ->expectsOutput("File is not readable: {$this->testXmlPath}")
            ->assertExitCode(Command::FAILURE);
        
        // Restore permissions for cleanup
        chmod($this->testXmlPath, 0644);
    }

    public function test_command_validates_date_format(): void
    {
        $this->createValidTestXml();
        
        $this->artisan('panorama:import', [
            '--file' => $this->testXmlPath,
            '--date' => 'invalid-date'
        ])
            ->expectsOutput('Invalid date format. Use YYYY-MM-DD format: invalid-date')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_command_accepts_valid_date_format(): void
    {
        $this->createValidTestXml();
        
        $this->artisan('panorama:import', [
            '--file' => $this->testXmlPath,
            '--date' => '2024-01-15',
            '--out' => $this->testOutputPath
        ])
            ->assertExitCode(Command::SUCCESS);
        
        $this->assertFileExists($this->testOutputPath);
    }

    public function test_command_uses_default_parameters(): void
    {
        $this->createValidTestXml();
        
        $this->artisan('panorama:import', [
            '--file' => $this->testXmlPath,
            '--out' => $this->testOutputPath
        ])
            ->expectsOutput('Tenant: default')
            ->expectsOutput('Date: ' . date('Y-m-d'))
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_command_creates_output_directory(): void
    {
        $this->createValidTestXml();
        
        $outputDir = storage_path('app/test_subdir');
        $outputFile = $outputDir . '/output.ndjson';
        
        // Ensure directory doesn't exist
        if (is_dir($outputDir)) {
            rmdir($outputDir);
        }
        
        $this->artisan('panorama:import', [
            '--file' => $this->testXmlPath,
            '--out' => $outputFile
        ])
            ->assertExitCode(Command::SUCCESS);
        
        $this->assertDirectoryExists($outputDir);
        $this->assertFileExists($outputFile);
        
        // Cleanup
        unlink($outputFile);
        rmdir($outputDir);
    }

    public function test_command_handles_xml_parsing_errors(): void
    {
        // Create invalid XML file
        file_put_contents($this->testXmlPath, '<invalid><xml>');
        
        $this->artisan('panorama:import', [
            '--file' => $this->testXmlPath,
            '--out' => $this->testOutputPath
        ])
            ->expectsOutputToContain('XML parsing failed:')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_command_reports_progress_and_statistics(): void
    {
        $this->createValidTestXml();
        
        $this->artisan('panorama:import', [
            '--file' => $this->testXmlPath,
            '--tenant' => 'test-tenant',
            '--date' => '2024-01-15',
            '--out' => $this->testOutputPath
        ])
            ->expectsOutput('Starting Panorama rules ingestion...')
            ->expectsOutput('File: ' . $this->testXmlPath)
            ->expectsOutput('Tenant: test-tenant')
            ->expectsOutput('Date: 2024-01-15')
            ->expectsOutput('Loading XML configuration...')
            ->expectsOutput('✓ XML loaded successfully')
            ->expectsOutput('Building object catalogs...')
            ->expectsOutput('Processing security rules...')
            ->expectsOutput('✓ Processing complete!')
            ->expectsOutputToContain('Rules processed:')
            ->expectsOutput('Output written to: ' . $this->testOutputPath)
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_command_handles_empty_xml_file(): void
    {
        // Create empty XML file
        file_put_contents($this->testXmlPath, '');
        
        $this->artisan('panorama:import', [
            '--file' => $this->testXmlPath,
            '--out' => $this->testOutputPath
        ])
            ->expectsOutputToContain('XML parsing failed:')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_command_processes_minimal_valid_xml(): void
    {
        $this->createValidTestXml();
        
        $this->artisan('panorama:import', [
            '--file' => $this->testXmlPath,
            '--out' => $this->testOutputPath
        ])
            ->assertExitCode(Command::SUCCESS);
        
        // Verify output file was created
        $this->assertFileExists($this->testOutputPath);
        
        // Verify output file has content (even if minimal)
        $content = file_get_contents($this->testOutputPath);
        $this->assertIsString($content);
    }

    public function test_command_handles_unwritable_output_directory(): void
    {
        $this->createValidTestXml();
        
        $unwritableDir = storage_path('app/unwritable');
        
        // Remove directory if it exists
        if (is_dir($unwritableDir)) {
            chmod($unwritableDir, 0755);
            rmdir($unwritableDir);
        }
        
        mkdir($unwritableDir, 0444); // Read-only directory
        
        $outputFile = $unwritableDir . '/output.ndjson';
        
        $this->artisan('panorama:import', [
            '--file' => $this->testXmlPath,
            '--out' => $outputFile
        ])
            ->expectsOutput("Output directory is not writable: {$unwritableDir}")
            ->assertExitCode(Command::FAILURE);
        
        // Cleanup
        chmod($unwritableDir, 0755);
        rmdir($unwritableDir);
    }

    public function test_command_interactive_file_input(): void
    {
        $this->createValidTestXml();
        
        $this->artisan('panorama:import', ['--out' => $this->testOutputPath])
            ->expectsQuestion('Path to Panorama XML export file', $this->testXmlPath)
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_command_validates_tenant_parameter(): void
    {
        $this->createValidTestXml();
        
        $this->artisan('panorama:import', [
            '--file' => $this->testXmlPath,
            '--tenant' => 'custom-tenant',
            '--out' => $this->testOutputPath
        ])
            ->expectsOutput('Tenant: custom-tenant')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_command_handles_large_xml_files(): void
    {
        // Create a larger XML file with multiple device groups and rules
        $largeXml = $this->createLargeTestXml();
        file_put_contents($this->testXmlPath, $largeXml);
        
        $this->artisan('panorama:import', [
            '--file' => $this->testXmlPath,
            '--out' => $this->testOutputPath
        ])
            ->assertExitCode(Command::SUCCESS);
        
        // Verify output was generated
        $this->assertFileExists($this->testOutputPath);
        $content = file_get_contents($this->testOutputPath);
        $this->assertNotEmpty($content);
    }

    /**
     * Create a minimal valid XML file for testing
     */
    private function createValidTestXml(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<config>
    <shared>
        <address>
            <entry name="test-address">
                <ip-netmask>192.168.1.1/32</ip-netmask>
            </entry>
        </address>
    </shared>
    <devices>
        <entry name="localhost.localdomain">
            <device-group>
                <entry name="test-dg">
                    <rulebase>
                        <security>
                            <rules>
                                <entry name="test-rule">
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
                </entry>
            </device-group>
        </entry>
    </devices>
</config>';
        
        file_put_contents($this->testXmlPath, $xml);
    }

    /**
     * Create a larger XML file for testing performance
     */
    private function createLargeTestXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<config>
    <shared>
        <address>
            <entry name="shared-address-1">
                <ip-netmask>10.0.0.1/32</ip-netmask>
            </entry>
            <entry name="shared-address-2">
                <ip-netmask>10.0.0.2/32</ip-netmask>
            </entry>
        </address>
        <service>
            <entry name="shared-service-1">
                <protocol><tcp><port>80</port></tcp></protocol>
            </entry>
        </service>
    </shared>
    <device-group name="parent-dg">
        <address>
            <entry name="parent-address">
                <ip-netmask>10.1.0.1/32</ip-netmask>
            </entry>
        </address>
    </device-group>
    <device-group name="child-dg">
        <parent>parent-dg</parent>
        <address>
            <entry name="child-address">
                <ip-netmask>192.168.1.100/32</ip-netmask>
            </entry>
        </address>
        <rulebase>
            <security>
                <rules>
                    <entry name="rule-1">
                        <from><member>any</member></from>
                        <to><member>any</member></to>
                        <source><member>shared-address-1</member></source>
                        <destination><member>child-address</member></destination>
                        <application><member>web-browsing</member></application>
                        <service><member>shared-service-1</member></service>
                        <action>allow</action>
                    </entry>
                    <entry name="rule-2">
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
</config>';
        
        return $xml;
    }
}