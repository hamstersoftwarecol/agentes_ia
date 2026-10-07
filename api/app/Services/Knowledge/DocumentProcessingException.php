<?php

namespace App\Services\Knowledge;

use RuntimeException;

/** Error "esperado" al procesar un documento; el mensaje se muestra tal cual al usuario y no se reintenta. */
class DocumentProcessingException extends RuntimeException {}
