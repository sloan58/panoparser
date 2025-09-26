<?php

namespace App\Console\Commands;

use App\Services\Panorama\CatalogBuilder;
use App\Services\Panorama\Dereferencer;
use App\Services\Panorama\PanoramaXmlLoader;
use App\Services\Panorama\RuleEmitter;
use App\Services\Panorama\Exceptions\PanoramaException;
use App\Services\Panorama\Exceptions\XmlParsingException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImportPanorama extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'panorama:import 
        {--file= : Path to Panorama XML export file}
        {--tenant= : Logical tenant name (default: default)}
        {--date= : Snapshot date in YYYY-MM-DD format (default: today)}
        {--out= : NDJSON output file path (default: storage/app/panorama_rules.ndjson)}';

    /**
     * The console command description.
     */
    protected $description = 'Import Panorama XML configuration and generate NDJSON for Elasticsearch';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startTime = microtime(true);
        
        try {
            // Get and validate parameters
            $parameters = $this->getValidatedParameters();
            
            Log::info('Starting Panorama rules ingestion', [
                'file' => $parameters['file'],
                'tenant' => $parameters['tenant'],
                'date' => $parameters['date'],
                'output' => $parameters['output']
            ]);
            
            $this->info("Starting Panorama rules ingestion...");
            $this->info("File: {$parameters['file']}");
            $this->info("Tenant: {$parameters['tenant']}");
            $this->info("Date: {$parameters['date']}");
            $this->info("Output: {$parameters['output']}");
            
            // Load and parse XML
            $this->info("Loading XML configuration...");
            $xmlLoader = new PanoramaXmlLoader();
            $xml = $xmlLoader->load($parameters['file']);
            $this->info("✓ XML loaded successfully");
            
            // Build catalogs
            $this->info("Building object catalogs...");
            $catalogBuilder = new CatalogBuilder(Log::getLogger());
            $catalog = $catalogBuilder->build($xml);
            
            $deviceGroupCount = count($catalog['deviceGroups']);
            $objectCount = $this->countObjects($catalog['objects']);
            $zoneCount = count($catalog['zones']);
            
            $this->info("✓ Catalogs built: {$deviceGroupCount} device groups, {$objectCount} objects, {$zoneCount} zones");
            
            // Initialize dereferencer
            $dereferencer = new Dereferencer($catalog, Log::getLogger());
            
            // Initialize rule emitter
            $ruleEmitter = new RuleEmitter(
                $parameters['tenant'],
                $parameters['date'],
                $dereferencer,
                Log::getLogger()
            );
            
            // Open output stream
            $this->info("Processing security rules...");
            $outputStream = $this->openOutputStream($parameters['output']);
            
            // Process rules and emit NDJSON
            $rulesProcessed = $ruleEmitter->emitSecurityRulesAsNdjson($xml, $outputStream);
            
            // Close output stream
            fclose($outputStream);
            
            $endTime = microtime(true);
            $processingTime = round($endTime - $startTime, 2);
            
            // Report completion statistics
            $this->info("✓ Processing complete!");
            $this->info("Rules processed: {$rulesProcessed}");
            $this->info("Processing time: {$processingTime} seconds");
            $this->info("Output written to: {$parameters['output']}");
            
            Log::info('Panorama rules ingestion completed successfully', [
                'rules_processed' => $rulesProcessed,
                'processing_time_seconds' => $processingTime,
                'device_groups' => $deviceGroupCount,
                'objects' => $objectCount,
                'zones' => $zoneCount
            ]);
            
            return Command::SUCCESS;
            
        } catch (\InvalidArgumentException $e) {
            $this->line($e->getMessage());
            Log::error('Panorama ingestion failed - parameter validation', [
                'error' => $e->getMessage()
            ]);
            return Command::FAILURE;
        } catch (XmlParsingException $e) {
            $this->line("XML parsing failed: " . $e->getMessage());
            Log::error('Panorama ingestion failed - XML parsing', [
                'error' => $e->getMessage(),
                'context' => $e->getContext()
            ]);
            return Command::FAILURE;
        } catch (PanoramaException $e) {
            $this->line("Panorama processing error: " . $e->getMessage());
            Log::error('Panorama ingestion failed - processing error', [
                'error' => $e->getMessage(),
                'context' => $e->getContext()
            ]);
            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->line("Unexpected error: " . $e->getMessage());
            Log::error('Panorama ingestion failed - unexpected error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            if ($this->option('verbose')) {
                $this->line("Stack trace: " . $e->getTraceAsString());
            }
            
            return Command::FAILURE;
        }
    }

    /**
     * Get and validate command parameters
     */
    private function getValidatedParameters(): array
    {
        // Get file parameter
        $file = $this->option('file');
        if (!$file) {
            $file = $this->ask('Path to Panorama XML export file');
        }
        
        if (!$file) {
            throw new \InvalidArgumentException('XML file path is required');
        }
        
        // Validate file exists and is readable
        if (!file_exists($file)) {
            throw new \InvalidArgumentException("File does not exist: {$file}");
        }
        
        if (!is_readable($file)) {
            throw new \InvalidArgumentException("File is not readable: {$file}");
        }
        
        // Get tenant parameter with default
        $tenant = $this->option('tenant') ?: 'default';
        
        // Get date parameter with default
        $date = $this->option('date') ?: date('Y-m-d');
        
        // Validate date format
        if (!$this->isValidDate($date)) {
            throw new \InvalidArgumentException("Invalid date format. Use YYYY-MM-DD format: {$date}");
        }
        
        // Get output parameter with default
        $output = $this->option('out') ?: storage_path('app/panorama_rules.ndjson');
        
        // Ensure output directory exists
        $outputDir = dirname($output);
        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0755, true)) {
                throw new \InvalidArgumentException("Cannot create output directory: {$outputDir}");
            }
        }
        
        // Check if output directory is writable
        if (!is_writable($outputDir)) {
            throw new \InvalidArgumentException("Output directory is not writable: {$outputDir}");
        }
        
        return [
            'file' => $file,
            'tenant' => $tenant,
            'date' => $date,
            'output' => $output
        ];
    }

    /**
     * Validate date format (YYYY-MM-DD)
     */
    private function isValidDate(string $date): bool
    {
        $dateTime = \DateTime::createFromFormat('Y-m-d', $date);
        return $dateTime && $dateTime->format('Y-m-d') === $date;
    }

    /**
     * Count total objects across all scopes
     */
    private function countObjects(array $objects): int
    {
        $total = 0;
        
        foreach ($objects as $scope => $types) {
            foreach ($types as $type => $items) {
                $total += count($items);
            }
        }
        
        return $total;
    }

    /**
     * Open output stream for writing NDJSON
     */
    private function openOutputStream(string $outputPath)
    {
        $stream = fopen($outputPath, 'w');
        
        if ($stream === false) {
            throw new \RuntimeException("Cannot open output file for writing: {$outputPath}");
        }
        
        return $stream;
    }
}