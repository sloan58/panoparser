# Requirements Document

## Introduction

This feature implements a comprehensive Panorama firewall rules ingestion system that parses Palo Alto Panorama XML configuration exports and transforms them into searchable Elasticsearch documents. The system processes full XML snapshots to extract, de-reference, and expand security rules while maintaining both original references and resolved values for audit and search purposes.

The ingestion process handles complex hierarchical device group structures, object inheritance, group expansions (address groups, service groups, application groups), and produces one Elasticsearch document per security rule with complete context for fast filtering and analysis.

## Requirements

### Requirement 1

**User Story:** As a security analyst, I want to ingest Panorama XML configuration snapshots into Elasticsearch, so that I can search and analyze firewall rules efficiently.

#### Acceptance Criteria

1. WHEN a Panorama XML file is provided THEN the system SHALL parse the complete configuration without data loss
2. WHEN parsing completes THEN the system SHALL produce one Elasticsearch document per security rule
3. IF the XML file is malformed or corrupted THEN the system SHALL provide clear error messages and fail gracefully
4. WHEN processing large XML files THEN the system SHALL handle memory efficiently using streaming where possible

### Requirement 2

**User Story:** As a compliance officer, I want both original rule references and expanded values in each document, so that I can audit configurations and understand actual traffic flows.

#### Acceptance Criteria

1. WHEN creating rule documents THEN the system SHALL include original object references in an "orig" section
2. WHEN creating rule documents THEN the system SHALL include fully expanded values in an "expanded" section
3. WHEN expanding address groups THEN the system SHALL resolve static groups to individual addresses
4. WHEN encountering dynamic address groups THEN the system SHALL mark them as unresolved with filter criteria
5. WHEN expanding service groups THEN the system SHALL resolve to individual services and port combinations
6. WHEN expanding application groups THEN the system SHALL resolve to individual applications

### Requirement 3

**User Story:** As a network administrator, I want the system to handle device group hierarchies correctly, so that object inheritance and rule precedence are accurately represented.

#### Acceptance Criteria

1. WHEN processing device groups THEN the system SHALL build a complete hierarchy tree with parent-child relationships
2. WHEN resolving objects THEN the system SHALL follow inheritance order: device group → ancestors → Shared
3. WHEN creating rule documents THEN the system SHALL include the complete device group path
4. WHEN processing rules THEN the system SHALL handle pre-rules, local rules, and post-rules separately
5. IF an object reference cannot be resolved THEN the system SHALL mark it as "UNKNOWN" with the original name

### Requirement 4

**User Story:** As a system administrator, I want to run the ingestion process as a scheduled Laravel command, so that rule data stays current with daily configuration snapshots.

#### Acceptance Criteria

1. WHEN running the ingestion command THEN the system SHALL accept XML file path, tenant name, snapshot date, and output path parameters
2. WHEN the command executes THEN the system SHALL provide progress feedback and completion statistics
3. WHEN ingestion completes THEN the system SHALL output NDJSON format suitable for Elasticsearch bulk API
4. IF required parameters are missing THEN the system SHALL use sensible defaults or prompt for required values
5. WHEN errors occur THEN the system SHALL log detailed error information and exit with appropriate status codes

### Requirement 5

**User Story:** As a security analyst, I want comprehensive rule metadata in each document, so that I can perform advanced filtering and analysis.

#### Acceptance Criteria

1. WHEN creating rule documents THEN the system SHALL include rule position, action, enabled/disabled status
2. WHEN processing rules THEN the system SHALL capture rule targets (included/excluded devices)
3. WHEN expanding objects THEN the system SHALL track which dynamic groups remain unresolved
4. WHEN creating documents THEN the system SHALL generate unique rule identifiers combining device group, rulebase, position, and name
5. WHEN processing rules THEN the system SHALL include tenant identifier and snapshot date for multi-tenant scenarios

### Requirement 6

**User Story:** As a developer, I want the ingestion system to be modular and testable, so that I can maintain and extend functionality easily.

#### Acceptance Criteria

1. WHEN implementing the system THEN the code SHALL be organized into focused service classes with single responsibilities
2. WHEN processing XML THEN the system SHALL use a dedicated XML loader service with proper error handling
3. WHEN building catalogs THEN the system SHALL use a separate catalog builder service for device groups and objects
4. WHEN expanding references THEN the system SHALL use a dedicated dereferencer service with cycle detection
5. WHEN emitting documents THEN the system SHALL use a separate rule emitter service for NDJSON generation

### Requirement 7

**User Story:** As a security analyst, I want to handle edge cases and data quality issues gracefully, so that ingestion doesn't fail on imperfect configurations.

#### Acceptance Criteria

1. WHEN encountering circular group references THEN the system SHALL detect cycles and prevent infinite loops
2. WHEN finding missing object references THEN the system SHALL mark them clearly but continue processing
3. WHEN processing malformed rule elements THEN the system SHALL use safe defaults and log warnings
4. WHEN handling empty or missing XML sections THEN the system SHALL continue processing other sections
5. WHEN encountering unknown object types THEN the system SHALL log warnings but not fail the entire process

### Requirement 8

**User Story:** As a system integrator, I want the output format to be optimized for Elasticsearch indexing, so that bulk loading is efficient and search performance is optimal.

#### Acceptance Criteria

1. WHEN generating output THEN the system SHALL produce valid NDJSON with proper Elasticsearch bulk API format
2. WHEN creating documents THEN the system SHALL use appropriate field types (keyword, boolean, integer, date)
3. WHEN structuring data THEN the system SHALL organize fields for optimal search performance
4. WHEN handling arrays THEN the system SHALL ensure consistent data types within each array field
5. WHEN generating rule UIDs THEN the system SHALL create unique, deterministic identifiers suitable as Elasticsearch document IDs