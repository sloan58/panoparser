<?php

namespace App\Services\Panorama\Exceptions;

class XmlParsingException extends PanoramaException
{
    public static function fromLibxmlErrors(array $errors, string $filePath): self
    {
        $messages = [];
        foreach ($errors as $error) {
            $messages[] = sprintf(
                "Line %d: %s",
                $error->line,
                trim($error->message)
            );
        }

        return new self(
            "XML parsing failed for file: {$filePath}",
            0,
            null,
            [
                'file_path' => $filePath,
                'xml_errors' => $messages,
            ]
        );
    }
}