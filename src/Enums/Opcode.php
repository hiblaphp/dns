<?php

declare(strict_types=1);

namespace Hibla\Dns\Enums;

enum Opcode: int
{
    case QUERY  = 0;
    case IQUERY = 1;
    case STATUS = 2;
}