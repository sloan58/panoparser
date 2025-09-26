<?php

namespace App\Services\Panorama\Contracts;

use SimpleXMLElement;

interface XmlLoaderInterface
{
    /**
     * Load and parse a Panorama XML file
     *
     * @param string $path Path to the XML file
     * @return SimpleXMLElement Parsed XML structure
     * @throws \Exception If file cannot be loaded or parsed
     */
    public function load(string $path): SimpleXMLElement;
}