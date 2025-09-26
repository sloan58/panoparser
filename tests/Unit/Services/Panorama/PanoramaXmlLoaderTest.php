<?php

namespace Tests\Unit\Services\Panorama;

use App\Services\Panorama\PanoramaXmlLoader;
use App\Services\Panorama\Exceptions\XmlParsingException;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

class PanoramaXmlLoaderTest extends TestCase
{
    private PanoramaXmlLoader $loader;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = new PanoramaXmlLoader();
        $this->tempDir = sys_get_temp_dir() . '/panorama_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temporary files
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_loads_valid_xml_file_successfully(): void
    {
        $xmlContent = '<?xml version="1.0" encoding="UTF-8"?>
<config>
    <devices>
        <entry name="localhost.localdomain">
            <device-group>
                <entry name="test-dg">
                    <devices>
                        <entry name="device1"/>
                    </devices>
                </entry>
            </device-group>
        </entry>
    </devices>
</config>';

        $filePath = $this->createTempFile('valid.xml', $xmlContent);
        
        $result = $this->loader->load($filePath);
        
        $this->assertInstanceOf(SimpleXMLElement::class, $result);
        $this->assertEquals('config', $result->getName());
        $this->assertNotNull($result->devices);
    }

    public function test_throws_exception_for_nonexistent_file(): void
    {
        $this->expectException(XmlParsingException::class);
        $this->expectExceptionMessage('XML file does not exist');
        
        $this->loader->load('/nonexistent/file.xml');
    }

    public function test_throws_exception_for_unreadable_file(): void
    {
        $filePath = $this->createTempFile('unreadable.xml', '<config></config>');
        chmod($filePath, 0000); // Make file unreadable
        
        $this->expectException(XmlParsingException::class);
        $this->expectExceptionMessage('XML file is not readable');
        
        try {
            $this->loader->load($filePath);
        } finally {
            chmod($filePath, 0644); // Restore permissions for cleanup
        }
    }

    public function test_throws_exception_for_directory_path(): void
    {
        $this->expectException(XmlParsingException::class);
        $this->expectExceptionMessage('Path is not a file');
        
        $this->loader->load($this->tempDir);
    }

    public function test_throws_exception_for_empty_file(): void
    {
        $filePath = $this->createTempFile('empty.xml', '');
        
        $this->expectException(XmlParsingException::class);
        $this->expectExceptionMessage('XML file is empty');
        
        $this->loader->load($filePath);
    }

    public function test_throws_exception_for_malformed_xml(): void
    {
        $malformedXml = '<?xml version="1.0" encoding="UTF-8"?>
<config>
    <unclosed-tag>
    <another-tag>content</another-tag>
</config>';

        $filePath = $this->createTempFile('malformed.xml', $malformedXml);
        
        $this->expectException(XmlParsingException::class);
        $this->expectExceptionMessage('XML parsing failed for file');
        
        $this->loader->load($filePath);
    }

    public function test_throws_exception_for_invalid_xml_structure(): void
    {
        $invalidXml = 'This is not XML content at all';
        
        $filePath = $this->createTempFile('invalid.xml', $invalidXml);
        
        $this->expectException(XmlParsingException::class);
        
        $this->loader->load($filePath);
    }

    public function test_handles_large_xml_files(): void
    {
        // Create a larger XML structure to test memory handling
        $largeXmlContent = '<?xml version="1.0" encoding="UTF-8"?><config><devices>';
        
        // Add many device entries to simulate a large file
        for ($i = 0; $i < 1000; $i++) {
            $largeXmlContent .= "<entry name=\"device{$i}\"><serial>SN{$i}</serial></entry>";
        }
        
        $largeXmlContent .= '</devices></config>';
        
        $filePath = $this->createTempFile('large.xml', $largeXmlContent);
        
        $result = $this->loader->load($filePath);
        
        $this->assertInstanceOf(SimpleXMLElement::class, $result);
        $this->assertEquals('config', $result->getName());
        $this->assertCount(1000, $result->devices->entry);
    }

    public function test_handles_xml_with_special_characters(): void
    {
        $xmlWithSpecialChars = '<?xml version="1.0" encoding="UTF-8"?>
<config>
    <description>Test with special chars: &lt;&gt;&amp;"\'</description>
    <unicode>测试中文字符</unicode>
</config>';

        $filePath = $this->createTempFile('special_chars.xml', $xmlWithSpecialChars);
        
        $result = $this->loader->load($filePath);
        
        $this->assertInstanceOf(SimpleXMLElement::class, $result);
        $this->assertStringContainsString('special chars', (string)$result->description);
        $this->assertEquals('测试中文字符', (string)$result->unicode);
    }

    public function test_handles_xml_with_cdata_sections(): void
    {
        $xmlWithCdata = '<?xml version="1.0" encoding="UTF-8"?>
<config>
    <script><![CDATA[
        function test() {
            return "Hello World";
        }
    ]]></script>
</config>';

        $filePath = $this->createTempFile('cdata.xml', $xmlWithCdata);
        
        $result = $this->loader->load($filePath);
        
        $this->assertInstanceOf(SimpleXMLElement::class, $result);
        $this->assertStringContainsString('function test()', (string)$result->script);
    }

    public function test_preserves_xml_structure_and_attributes(): void
    {
        $xmlWithAttributes = '<?xml version="1.0" encoding="UTF-8"?>
<config version="1.0" timestamp="2023-01-01">
    <device-group>
        <entry name="test-group" id="123">
            <description>Test device group</description>
        </entry>
    </device-group>
</config>';

        $filePath = $this->createTempFile('attributes.xml', $xmlWithAttributes);
        
        $result = $this->loader->load($filePath);
        
        $this->assertInstanceOf(SimpleXMLElement::class, $result);
        $this->assertEquals('1.0', (string)$result['version']);
        $this->assertEquals('2023-01-01', (string)$result['timestamp']);
        $this->assertEquals('test-group', (string)$result->{'device-group'}->entry['name']);
        $this->assertEquals('123', (string)$result->{'device-group'}->entry['id']);
    }

    /**
     * Create a temporary file with given content
     */
    private function createTempFile(string $filename, string $content): string
    {
        $filePath = $this->tempDir . '/' . $filename;
        file_put_contents($filePath, $content);
        return $filePath;
    }

    /**
     * Recursively remove a directory and its contents
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}