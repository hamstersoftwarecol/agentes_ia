<?php

namespace App\Services\Knowledge;

use Illuminate\Support\Str;

/**
 * Parte un texto en fragmentos de ~$size caracteres respetando, en lo posible,
 * párrafos y oraciones, con un solapamiento para no perder contexto en los cortes.
 */
class TextChunker
{
    public function __construct(
        private readonly int $size = 1000,
        private readonly int $overlap = 150,
    ) {}

    public static function fromConfig(): self
    {
        return new self((int) config('knowledge.chunk_size'), (int) config('knowledge.chunk_overlap'));
    }

    /** @return list<string> */
    public function chunk(string $text): array
    {
        $text = trim(preg_replace(["/\r\n?/", "/[ \t]+/", "/\n{3,}/"], ["\n", ' ', "\n\n"], $text));
        if ($text === '') {
            return [];
        }

        $chunks = [];
        $current = '';

        foreach ($this->units($text) as $unit) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($unit) + 1 > $this->size) {
                $chunks[] = trim($current);
                $current = $this->tail($current);
            }
            $current = $current === '' ? $unit : $current.' '.$unit;
        }

        // El último fragmento siempre contiene al menos la última unidad (el solapamiento
        // solo se hereda cuando va a agregarse otra unidad justo después).
        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }

    /**
     * Unidades indivisibles: oraciones, o trozos duros si una oración excede el tamaño.
     *
     * @return list<string>
     */
    private function units(string $text): array
    {
        $units = [];

        foreach (preg_split('/\n{2,}/', $text) as $paragraph) {
            $sentences = preg_split('/(?<=[.!?¿¡:;])\s+|\n/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY);

            foreach ($sentences as $sentence) {
                if (mb_strlen($sentence) <= $this->size) {
                    $units[] = $sentence;

                    continue;
                }
                foreach (mb_str_split($sentence, $this->size) as $piece) {
                    $units[] = $piece;
                }
            }
        }

        return $units;
    }

    /** Final del fragmento anterior que se repite al inicio del siguiente. */
    private function tail(string $chunk): string
    {
        if ($this->overlap <= 0) {
            return '';
        }

        $tail = mb_substr($chunk, -$this->overlap);

        // Empieza en una palabra completa.
        return Str::contains($tail, ' ') && mb_strlen($chunk) > $this->overlap
            ? trim(Str::after($tail, ' '))
            : trim($tail);
    }
}
