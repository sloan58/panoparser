<?php

namespace App\Services\Panorama\Contracts;

use SimpleXMLElement;

interface RuleEmitterInterface
{
    /**
     * Process security rules and emit structured NDJSON documents
     *
     * @param SimpleXMLElement $root Root XML element
     * @param resource $stream Output stream for NDJSON
     * @return int Number of rules processed
     */
    public function emitSecurityRulesAsNdjson(SimpleXMLElement $root, $stream): int;
}