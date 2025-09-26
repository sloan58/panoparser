<?php

namespace App\Services\Panorama;

use App\Services\Panorama\Exceptions\XmlParsingException;
use SimpleXMLElement;

class PanoramaXmlLoader implements Contracts\XmlLoaderInterface
{
    /**
     * Load and parse a Panorama XML file with robust error handling
     *
     * @param string $path Path to the XML file
     * @return SimpleXMLElement Parsed XML document
     * @throws XmlParsingException If file cannot be loaded or parsed
     */
    public function load(string $path): SimpleXMLElement
    {
        // Validate file existence and readability
        $this->validateFile($path);

        // Configure libxml for large file handling and error reporting
        $this->configureLibxml();

        // Clear any previous libxml errors
        libxml_clear_errors();

        // Attempt to load the XML file (includes error checking)
        $xml = $this->loadXmlFile($path);

        return $xml;
    }

    /**
     * Validate that the file exists and is readable
     *
     * @param string $path
     * @throws XmlParsingException
     */
    private function validateFile(string $path): void
    {
        if (!file_exists($path)) {
            throw new XmlParsingException("XML file does not exist: {$path}");
        }

        if (!is_readable($path)) {
            throw new XmlParsingException("XML file is not readable: {$path}");
        }

        if (!is_file($path)) {
            throw new XmlParsingException("Path is not a file: {$path}");
        }

        // Check if file is empty
        if (filesize($path) === 0) {
            throw new XmlParsingException("XML file is empty: {$path}");
        }
    }

    /**
     * Configure libxml settings for optimal parsing
     */
    private function configureLibxml(): void
    {
        // Enable internal error handling
        libxml_use_internal_errors(true);

        // Set memory limit for large documents
        ini_set('memory_limit', '512M');
    }

    /**
     * Load the XML file with appropriate options
     *
     * @param string $path
     * @return SimpleXMLElement
     * @throws XmlParsingException
     */
    private function loadXmlFile(string $path): SimpleXMLElement
    {
        // Use LIBXML_COMPACT for memory efficiency and LIBXML_PARSEHUGE for large files
        $options = LIBXML_COMPACT | LIBXML_PARSEHUGE | LIBXML_NOCDATA;

        try {
            $xml = simplexml_load_file($path, SimpleXMLElement::class, $options);
            
            if ($xml === false) {
                // Check if there are libxml errors first
                $errors = libxml_get_errors();
                if (!empty($errors)) {
                    libxml_clear_errors();
                    throw XmlParsingException::fromLibxmlErrors($errors, $path);
                }
                
                // If no specific errors, throw generic parsing exception
                throw new XmlParsingException("Failed to parse XML file: {$path}");
            }

            return $xml;
        } catch (XmlParsingException $e) {
            // Re-throw our custom exceptions
            throw $e;
        } catch (\Exception $e) {
            throw new XmlParsingException(
                "Error loading XML file {$path}: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }


}