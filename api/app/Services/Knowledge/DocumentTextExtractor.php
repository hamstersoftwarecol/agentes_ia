<?php

namespace App\Services\Knowledge;

use Smalot\PdfParser\Parser;
use Throwable;

class DocumentTextExtractor
{
    /** @throws DocumentProcessingException */
    public function extract(string $absolutePath, string $extension): string
    {
        $text = match (strtolower($extension)) {
            'pdf' => $this->fromPdf($absolutePath),
            'txt', 'md' => $this->fromText($absolutePath),
            default => throw new DocumentProcessingException('Tipo de archivo no soportado.'),
        };

        if (trim($text) === '') {
            throw new DocumentProcessingException(
                'No se encontró texto en el archivo. Si es un PDF escaneado (imágenes), conviértelo a texto antes de subirlo.'
            );
        }

        return $text;
    }

    private function fromPdf(string $path): string
    {
        try {
            return (new Parser)->parseFile($path)->getText();
        } catch (Throwable) {
            throw new DocumentProcessingException('No se pudo leer el PDF. Puede estar dañado o protegido con contraseña.');
        }
    }

    private function fromText(string $path): string
    {
        $raw = (string) file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // BOM UTF-8

        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        // Archivos de Windows antiguos suelen venir en Windows-1252/Latin-1.
        return mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
}
