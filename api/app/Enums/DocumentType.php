<?php

namespace App\Enums;

enum DocumentType: string
{
    case Text = 'text';
    case Faq = 'faq';
    case File = 'file';
}
