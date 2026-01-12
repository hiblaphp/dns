<?php

declare(strict_types=1);

namespace Hibla\Dns\Enums;

enum ResponseCode: int
{
    case OK              = 0;
    case FORMAT_ERROR    = 1;
    case SERVER_FAILURE  = 2;
    case NAME_ERROR      = 3;
    case NOT_IMPLEMENTED = 4;
    case REFUSED         = 5;
}