<?php

if ($argc < 2) {
    echo "Usage: php diagnose-xml.php <xml-file>\n";
    exit(1);
}

$xmlFile = $argv[1];

if (!file_exists($xmlFile)) {
    echo "File not found: $xmlFile\n";
    exit(1);
}

echo "Diagnosing XML structure for: $xmlFile\n";
echo "File size: " . number_format(filesize($xmlFile)) . " bytes\n\n";

try {
    // Load XML with same options as the application
    $options = LIBXML_COMPACT | LIBXML_PARSEHUGE | LIBXML_NOCDATA;
    $xml = simplexml_load_file($xmlFile, SimpleXMLElement::class, $options);
    
    if ($xml === false) {
        echo "❌ Failed to parse XML file\n";
        $errors = libxml_get_errors();
        foreach ($errors as $error) {
            echo "XML Error: " . trim($error->message) . " on line " . $error->line . "\n";
        }
        exit(1);
    }
    
    echo "✅ XML parsed successfully\n";
    echo "Root element: " . $xml->getName() . "\n\n";
    
    // Test different XPath patterns
    $patterns = [
        '//device-group' => 'Direct device-group elements',
        '//devices//device-group' => 'Device-group under devices',
        '//devices/entry/device-group' => 'Device-group under devices/entry',
        '//devices/entry/device-group/entry' => 'Device-group entries under devices/entry',
        '//shared' => 'Shared configuration',
        '//rulebase' => 'Rulebase elements',
        '//security/rules/entry' => 'Security rule entries'
    ];
    
    foreach ($patterns as $xpath => $description) {
        $elements = $xml->xpath($xpath);
        $count = $elements ? count($elements) : 0;
        echo sprintf("%-40s: %d matches\n", $description, $count);
        
        // Show first match details for device-group patterns
        if ($count > 0 && strpos($xpath, 'device-group') !== false) {
            $first = $elements[0];
            if (isset($first['name'])) {
                echo "  First match name: " . (string)$first['name'] . "\n";
            } else {
                echo "  First match has no name attribute\n";
                // Check if it has entry children
                $entries = $first->xpath('entry');
                if ($entries) {
                    echo "  Has " . count($entries) . " entry children\n";
                    if (isset($entries[0]['name'])) {
                        echo "  First entry name: " . (string)$entries[0]['name'] . "\n";
                    }
                }
            }
        }
    }
    
    echo "\n";
    
    // Check root structure
    echo "Root element children:\n";
    foreach ($xml->children() as $child) {
        echo "  - " . $child->getName() . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}