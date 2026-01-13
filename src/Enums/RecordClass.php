<?php

declare(strict_types=1);

namespace Hibla\Dns\Enums;

enum RecordClass: int
{
    case IN = 1;
    case CS = 2; 
    case CH = 3; 
    case HS = 4;
}