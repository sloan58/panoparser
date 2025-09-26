<?php

namespace App\Services\Panorama\Contracts;

use SimpleXMLElement;

interface CatalogBuilderInterface
{
    /**
     * Build comprehensive lookup catalogs from XML configuration
     *
     * @param SimpleXMLElement $root Root XML element
     * @return array Catalog structure with device groups, objects, and zones
     */
    public function build(SimpleXMLElement $root): array;
}