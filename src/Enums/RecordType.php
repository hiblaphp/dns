<?php

declare(strict_types=1);

namespace Hibla\Dns\Enums;

enum RecordType: int
{
    case A     = 1;
    case NS    = 2;
    case CNAME = 5;
    case SOA   = 6;
    case PTR   = 12;
    case MX    = 15;
    case TXT   = 16;
    case AAAA  = 28;
    case SRV   = 33;
    case OPT   = 41;
    case SSHFP = 44;
    case ANY   = 255;
    case CAA   = 257;
}