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
use MongoDB\Client as MongoClient;
use MongoDB\Exception\Exception as MongoException;

class ImportPanorama extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'panorama:import 
        {--file= : Path to Panorama XML export file}
        {--tenant= : Logical tenant name (default: default)}
        {--date= : Snapshot date in YYYY-MM-DD format (default: today)}
        {--out= : NDJSON output file path (default: storage/app/panorama_rules.ndjson)}
        {--mongodb= : MongoDB connection string (optional)}
        {--db= : MongoDB database name (default: firewall)}
        {--collection= : MongoDB collection name (default: rules)}';

    /**
     * The console command description.
     */
    protected $description = 'Import Panorama XML configuration and generate NDJSON output with optional MongoDB upsert';

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
            
            // MongoDB upsert if connection provided
            if ($parameters['mongodb']) {
                $this->info("Starting MongoDB upsert...");
                $upsertedCount = $this->upsertToMongoDB(
                    $parameters['output'],
                    $parameters['mongodb'],
                    $parameters['db'],
                    $parameters['collection']
                );
                $this->info("✓ MongoDB upsert complete: {$upsertedCount} documents");
            }
            
            Log::info('Panorama rules ingestion completed successfully', [
                'rules_processed' => $rulesProcessed,
                'processing_time_seconds' => $processingTime,
                'device_groups' => $deviceGroupCount,
                'objects' => $objectCount,
                'zones' => $zoneCount,
                'mongodb_upserted' => $parameters['mongodb'] ? $upsertedCount ?? 0 : null
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
        
        // Get MongoDB parameters
        $mongodb = $this->option('mongodb');
        $db = $this->option('db') ?: 'firewall';
        $collection = $this->option('collection') ?: 'rules';
        
        return [
            'file' => $file,
            'tenant' => $tenant,
            'date' => $date,
            'output' => $output,
            'mongodb' => $mongodb,
            'db' => $db,
            'collection' => $collection
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

    /**
     * Upsert NDJSON data to MongoDB
     *
     * @param string $ndjsonPath Path to NDJSON file
     * @param string $connectionString MongoDB connection string
     * @param string $database Database name
     * @param string $collection Collection name
     * @return int Number of documents upserted
     */
    private function upsertToMongoDB(string $ndjsonPath, string $connectionString, string $database, string $collection): int
    {
        try {
            // Connect to MongoDB
            $client = new MongoClient($connectionString);
            $db = $client->selectDatabase($database);
            $coll = $db->selectCollection($collection);
            
            $this->info("Connected to MongoDB: {$database}.{$collection}");
            
            // Create indexes for optimal upsert performance
            $this->createMongoDBIndexes($coll);
            
            // Read and process NDJSON file
            $handle = fopen($ndjsonPath, 'r');
            if ($handle === false) {
                throw new \RuntimeException("Cannot open NDJSON file: {$ndjsonPath}");
            }
            
            $upsertedCount = 0;
            $batchSize = 1000;
            $batch = [];
            
            $this->info("Processing NDJSON file for MongoDB upsert...");
            
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }
                
                try {
                    $document = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    
                    // Prepare upsert operation
                    $batch[] = [
                        'replaceOne' => [
                            [
                                'rule_uid' => $document['rule_uid'],
                                'panorama_tenant' => $document['panorama_tenant']
                            ],
                            $document,
                            ['upsert' => true]
                        ]
                    ];
                    
                    // Execute batch when it reaches the batch size
                    if (count($batch) >= $batchSize) {
                        $result = $coll->bulkWrite($batch);
                        $upsertedCount += $result->getUpsertedCount() + $result->getModifiedCount();
                        $batch = [];
                        
                        $this->info("Processed {$upsertedCount} documents...");
                    }
                    
                } catch (\JsonException $e) {
                    Log::warning('Failed to parse JSON line', [
                        'line' => substr($line, 0, 100),
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
            }
            
            // Process remaining batch
            if (!empty($batch)) {
                $result = $coll->bulkWrite($batch);
                $upsertedCount += $result->getUpsertedCount() + $result->getModifiedCount();
            }
            
            fclose($handle);
            
            return $upsertedCount;
            
        } catch (MongoException $e) {
            Log::error('MongoDB operation failed', [
                'error' => $e->getMessage(),
                'connection' => $connectionString
            ]);
            throw new \RuntimeException("MongoDB operation failed: " . $e->getMessage());
        }
    }

    /**
     * Create MongoDB indexes for optimal performance
     *
     * @param \MongoDB\Collection $collection MongoDB collection
     */
    private function createMongoDBIndexes($collection): void
    {
        $this->info("Creating MongoDB indexes...");
        
        try {
            // Unique index for upserts
            $collection->createIndex(
                ['rule_uid' => 1, 'panorama_tenant' => 1],
                ['unique' => true, 'name' => 'rule_uid_tenant_unique']
            );
            
            // Performance indexes for common queries
            $collection->createIndex(
                ['panorama_tenant' => 1, 'snapshot_date' => -1],
                ['name' => 'tenant_date_idx']
            );
            
            $collection->createIndex(
                ['device_group' => 1],
                ['name' => 'device_group_idx']
            );
            
            $collection->createIndex(
                ['action' => 1, 'disabled' => 1],
                ['name' => 'action_disabled_idx']
            );
            
            $collection->createIndex(
                ['snapshot_date' => -1],
                ['name' => 'snapshot_date_idx']
            );
            
            $this->info("✓ MongoDB indexes created successfully");
            
        } catch (MongoException $e) {
            // Log warning but don't fail - indexes might already exist
            Log::warning('Failed to create some MongoDB indexes', [
                'error' => $e->getMessage()
            ]);
            $this->warn("Warning: Some indexes may not have been created");
        }
    }
}