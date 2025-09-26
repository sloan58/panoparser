<?php

if ($argc < 2) {
    echo "Usage: php diagnose-applications.php <xml-file> [application-name]\n";
    exit(1);
}

$xmlFile = $argv[1];
$searchApp = $argv[2] ?? 'ntp';

if (!file_exists($xmlFile)) {
    echo "File not found: $xmlFile\n";
    exit(1);
}

echo "Diagnosing applications in XML: $xmlFile\n";
echo "Searching for application: $searchApp\n\n";

try {
    $options = LIBXML_COMPACT | LIBXML_PARSEHUGE | LIBXML_NOCDATA;
    $xml = simplexml_load_file($xmlFile, SimpleXMLElement::class, $options);
    
    if ($xml === false) {
        echo "❌ Failed to parse XML file\n";
        exit(1);
    }
    
    echo "✅ XML parsed successfully\n\n";
    
    // Test different application XPath patterns
    $appPatterns = [
        '//application' => 'All application elements',
        '//application/entry' => 'Application entries',
        '//shared//application' => 'Applications under shared',
        '//shared//application/entry' => 'Application entries under shared',
        '//device-group//application' => 'Applications under device-group',
        '//device-group//application/entry' => 'Application entries under device-group',
        '//devices//application' => 'Applications under devices',
        '//devices//application/entry' => 'Application entries under devices',
        '//devices/entry/device-group/entry//application' => 'Applications in nested device groups',
        '//devices/entry/device-group/entry//application/entry' => 'Application entries in nested device groups'
    ];
    
    foreach ($appPatterns as $xpath => $description) {
        $elements = $xml->xpath($xpath);
        $count = $elements ? count($elements) : 0;
        echo sprintf("%-55s: %d matches\n", $description, $count);
        
        // Look for the specific application
        if ($count > 0) {
            $found = false;
            foreach ($elements as $app) {
                if (isset($app['name']) && (string)$app['name'] === $searchApp) {
                    echo "  ✅ Found '$searchApp' in this pattern!\n";
                    $found = true;
                    break;
                }
            }
            
            // Show first few examples if not found
            if (!$found && $count <= 10) {
                echo "  Examples: ";
                $examples = [];
                foreach ($elements as $i => $app) {
                    if (isset($app['name'])) {
                        $examples[] = (string)$app['name'];
                    }
                    if ($i >= 4) break;
                }
                echo implode(', ', $examples) . "\n";
            } elseif (!$found && $count > 10) {
                echo "  First 3 examples: ";
                $examples = [];
                for ($i = 0; $i < 3 && $i < count($elements); $i++) {
                    if (isset($elements[$i]['name'])) {
                        $examples[] = (string)$elements[$i]['name'];
                    }
                }
                echo implode(', ', $examples) . " ... and " . ($count - 3) . " more\n";
            }
        }
        echo "\n";
    }
    
    // Direct search for the application name
    echo "=== Direct Search for '$searchApp' ===\n";
    $directSearch = $xml->xpath("//*[@name='$searchApp']");
    if ($directSearch) {
        echo "Found " . count($directSearch) . " elements with name='$searchApp':\n";
        foreach ($directSearch as $element) {
            $path = '';
            $current = $element;
            $pathParts = [];
            
            // Build XPath-like path
            while ($current) {
                $name = $current->getName();
                if (isset($current['name'])) {
                    $name .= "[@name='" . (string)$current['name'] . "']";
                }
                array_unshift($pathParts, $name);
                $current = $current->xpath('..')[0] ?? null;
                if ($current && $current->getName() === 'config') break;
            }
            
            echo "  - " . implode('/', $pathParts) . "\n";
        }
    } else {
        echo "❌ No elements found with name='$searchApp'\n";
    }
    
    // Check what the application parsing logic would find
    echo "\n=== Testing Application Parsing Logic ===\n";
    
    // Test shared applications
    $sharedConfig = $xml->xpath('//shared')[0] ?? null;
    if ($sharedConfig) {
        $sharedApps = $sharedConfig->xpath('.//application/entry');
        echo "Shared applications found: " . count($sharedApps) . "\n";
        
        foreach ($sharedApps as $app) {
            if (isset($app['name']) && (string)$app['name'] === $searchApp) {
                echo "  ✅ Found '$searchApp' in shared applications!\n";
            }
        }
    }
    
    // Test device group applications (using the nested format we know works)
    $dgElements = $xml->xpath('//devices/entry/device-group/entry');
    echo "Device group elements found: " . count($dgElements) . "\n";
    
    foreach ($dgElements as $dg) {
        $dgName = (string)$dg['name'];
        $dgApps = $dg->xpath('.//application/entry');
        
        if (!empty($dgApps)) {
            echo "Device group '$dgName' has " . count($dgApps) . " applications\n";
            
            foreach ($dgApps as $app) {
                if (isset($app['name']) && (string)$app['name'] === $searchApp) {
                    echo "  ✅ Found '$searchApp' in device group '$dgName'!\n";
                }
            }
        }
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}