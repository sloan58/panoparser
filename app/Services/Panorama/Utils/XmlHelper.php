<?php

namespace App\Services\Panorama\Utils;

use SimpleXMLElement;

class XmlHelper
{
    /**
     * Safely get text content from XML element
     *
     * @param SimpleXMLElement|null $element XML element
     * @param string $default Default value if element is null or empty
     * @return string Element text content or default
     */
    public static function getText(?SimpleXMLElement $element, string $default = ''): string
    {
        if ($element === null) {
            return $default;
        }

        $text = trim((string) $element);
        return $text !== '' ? $text : $default;
    }

    /**
     * Safely get attribute value from XML element
     *
     * @param SimpleXMLElement|null $element XML element
     * @param string $attribute Attribute name
     * @param string $default Default value if attribute doesn't exist
     * @return string Attribute value or default
     */
    public static function getAttribute(?SimpleXMLElement $element, string $attribute, string $default = ''): string
    {
        if ($element === null || !isset($element[$attribute])) {
            return $default;
        }

        return trim((string) $element[$attribute]);
    }

    /**
     * Convert XML element children to array of names
     *
     * @param SimpleXMLElement|null $parent Parent element
     * @param string $childName Child element name to extract
     * @return array Array of child element names
     */
    public static function getChildNames(?SimpleXMLElement $parent, string $childName): array
    {
        if ($parent === null) {
            return [];
        }

        $names = [];
        foreach ($parent->xpath($childName) as $child) {
            $name = self::getAttribute($child, 'name');
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Check if XML element exists and has content
     *
     * @param SimpleXMLElement|null $element XML element
     * @return bool True if element exists and has content
     */
    public static function hasContent(?SimpleXMLElement $element): bool
    {
        return $element !== null && trim((string) $element) !== '';
    }

    /**
     * Get all member names from a group element
     *
     * @param SimpleXMLElement|null $group Group element
     * @return array Array of member names
     */
    public static function getGroupMembers(?SimpleXMLElement $group): array
    {
        if ($group === null) {
            return [];
        }

        $members = [];
        
        // Handle both <member>name</member> and <member name="name"/> formats
        foreach ($group->xpath('member') as $member) {
            $name = self::getAttribute($member, 'name');
            if ($name === '') {
                $name = self::getText($member);
            }
            
            if ($name !== '') {
                $members[] = $name;
            }
        }

        return $members;
    }
}