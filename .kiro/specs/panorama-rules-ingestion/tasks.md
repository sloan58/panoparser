# Implementation Plan

- [x] 1. Set up project structure and core service interfaces
  - Create directory structure for Panorama services under app/Services/Panorama
  - Define base interfaces and contracts for the ingestion pipeline
  - Set up proper namespacing and autoloading
  - _Requirements: 6.1, 6.2_

- [x] 2. Implement XML loading service with error handling
  - Create PanoramaXmlLoader service class with robust XML parsing
  - Implement proper libxml error handling and memory management
  - Add validation for file existence and readability
  - Write unit tests for XML loading with various file conditions
  - _Requirements: 1.1, 1.3, 1.4, 7.4_

- [x] 3. Create catalog builder for device groups hierarchy
  - Implement device group tree construction with parent-child relationships
  - Add inheritance path computation for proper object resolution order
  - Handle edge cases like missing parents and circular references
  - Write unit tests for hierarchy building and path computation
  - _Requirements: 3.1, 3.2, 3.3, 7.1_

- [x] 4. Extend catalog builder for objects and zones
  - Add object catalog building for addresses, services, and applications
  - Implement support for static and dynamic groups
  - Create zone catalog with device group associations
  - Write unit tests for object catalog construction across different scopes
  - _Requirements: 2.3, 2.4, 2.5, 3.4_

- [x] 5. Implement object dereferencer with inheritance resolution
  - Create Dereferencer service that follows device group inheritance paths
  - Implement address expansion with static group resolution
  - Add service expansion with port combination logic
  - Write unit tests for object resolution following inheritance rules
  - _Requirements: 2.1, 2.2, 3.2, 3.5_

- [x] 6. Add group expansion with cycle detection
  - Extend dereferencer to handle static address and service group expansion
  - Implement cycle detection to prevent infinite loops in malformed configs
  - Add proper handling of dynamic address groups (mark as unresolved)
  - Write unit tests for group expansion and cycle detection scenarios
  - _Requirements: 2.3, 2.4, 7.1, 7.2_

- [x] 7. Create rule document structure and UID generation
  - Define the complete Elasticsearch document structure for security rules
  - Implement unique rule UID generation combining device group, rulebase, position, and name
  - Add proper handling of rule metadata (action, disabled status, targets)
  - Write unit tests for document structure and UID uniqueness
  - _Requirements: 5.1, 5.4, 8.5_

- [x] 8. Implement rule emitter for NDJSON generation
  - Create RuleEmitter service that processes security rules from XML
  - Implement streaming NDJSON output for memory efficiency
  - Add support for pre-rules, local rules, and post-rules processing
  - Write unit tests for rule processing and NDJSON format compliance
  - _Requirements: 1.2, 4.3, 5.2, 8.1_

- [x] 9. Integrate original and expanded values in rule documents
  - Extend rule emitter to include both original references and expanded values
  - Implement proper handling of unresolved references with clear marking
  - Add metadata tracking for dynamic groups and resolution status
  - Write unit tests for dual-format document generation
  - _Requirements: 2.1, 2.2, 5.3, 7.2_

- [x] 10. Create Laravel Artisan command interface
  - Implement ImportPanorama command with proper parameter handling
  - Add progress reporting and completion statistics
  - Implement proper error handling with descriptive messages and exit codes
  - Write unit tests for command interface and parameter validation
  - _Requirements: 4.1, 4.2, 4.4, 4.5_

- [x] 11. Add comprehensive error handling and logging
  - Implement graceful handling of malformed XML elements
  - Add detailed logging for troubleshooting and audit purposes
  - Ensure processing continues despite individual object resolution failures
  - Write unit tests for error scenarios and recovery behavior
  - _Requirements: 7.2, 7.3, 7.4, 7.5_

- [ ] 12. Optimize for Elasticsearch compatibility
  - Ensure NDJSON output format matches Elasticsearch bulk API requirements
  - Validate field types and structure for optimal search performance
  - Add proper handling of arrays and nested objects
  - Write integration tests for Elasticsearch document compatibility
  - _Requirements: 8.1, 8.2, 8.3, 8.4_

- [ ] 13. Create comprehensive test suite with sample data
  - Develop sample Panorama XML files covering various configuration scenarios
  - Create reference NDJSON outputs for validation
  - Implement performance tests for large configuration processing
  - Add memory usage monitoring and validation
  - _Requirements: 1.4, 6.1, 6.2, 6.3_

- [ ] 14. Add command registration and documentation
  - Register the panorama:import command in Laravel's console kernel
  - Create comprehensive command help and usage documentation
  - Add configuration examples and best practices guide
  - Write integration tests for complete end-to-end processing
  - _Requirements: 4.1, 4.2, 4.4_