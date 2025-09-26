<?php

if ($argc < 2) {
    echo "Usage: php diagnose-zones.php <xml-file>\n";
    exit(1);
}

$xmlFile = $argv[1];

if (!file_exists($xmlFile)) {
    echo "File not found: $xmlFile\n";
    exit(1);
}

echo "Diagnosing zones in XML: $xmlFile\n\n";

try {
    $options = LIBXML_COMPACT | LIBXML_PARSEHUGE | LIBXML_NOCDATA;
    $xml = simplexml_load_file($xmlFile, SimpleXMLElement::class, $options);
    
    if ($xml === false) {
        echo "❌ Failed to parse XML file\n";
        exit(1);
    }
    
    echo "✅ XML parsed successfully\n\n";
    
    // Test different zone XPath patterns
    $zonePatterns = [
        '//zone' => 'All zone elements',
        '//zone/entry' => 'Zone entries',
        '//shared//zone' => 'Zones under shared',
        '//shared//zone/entry' => 'Zone entries under shared',
        '//device-group//zone' => 'Zones under device-group',
        '//device-group//zone/entry' => 'Zone entries under device-group',
        '//devices//zone' => 'Zones under devices',
        '//devices//zone/entry' => 'Zone entries under devices',
        '//devices/entry/device-group/entry//zone' => 'Zones in nested device groups',
        '//devices/entry/device-group/entry//zone/entry' => 'Zone entries in nested device groups'
    ];
    
    foreach ($zonePatterns as $xpath => $description) {
        $elements = $xml->xpath($xpath);
        $count = $elements ? count($elements) : 0;
        echo sprintf("%-50s: %d matches\n", $description, $count);
        
        // Show first few zone names if found
        if ($count > 0 && $count <= 10) {
            foreach ($elements as $i => $zone) {
                if (isset($zone['name'])) {
                    echo "  - " . (string)$zone['name'] . "\n";
                }
                if ($i >= 4) break; // Show max 5 examples
            }
        } elseif ($count > 10) {
            // Show first 3 examples for large counts
            for ($i = 0; $i < 3 && $i < count($elements); $i++) {
                if (isset($elements[$i]['name'])) {
                    echo "  - " . (string)$elements[$i]['name'] . "\n";
                }
            }
            echo "  ... and " . ($count - 3) . " more\n";
        }
        echo "\n";
    }
    
    // Check what's in shared
    $shared = $xml->xpath('//shared')[0] ?? null;
    if ($shared) {
        echo "Shared section children:\n";
        foreach ($shared->children() as $child) {
            echo "  - " . $child->getName() . "\n";
        }
        echo "\n";
    }
    
    // Check what's in first device group
    $firstDG = $xml->xpath('//devices/entry/device-group/entry')[0] ?? null;
    if ($firstDG) {
        echo "First device group children:\n";
        foreach ($firstDG->children() as $child) {
            echo "  - " . $child->getName() . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}