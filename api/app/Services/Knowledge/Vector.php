<?php

namespace App\Services\Knowledge;

/** Conversión de arreglos PHP al literal de texto que entiende pgvector: "[0.1,0.2,...]". */
final class Vector
{
    /** @param  list<float>  $values */
    public static function toSql(array $values): string
    {
        return '['.implode(',', array_map(fn ($v) => sprintf('%.8F', $v), $values)).']';
    }
}
