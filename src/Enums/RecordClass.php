<?php

declare(strict_types=1);

namespace Hibla\Dns\Enums;

/**
 * DNS resource record class.
 *
 * Defines the protocol family or instance of a protocol for DNS records.
 * In practice, IN (Internet) is used for nearly all modern DNS queries.
 *
 * Values defined in RFC 1035 Section 3.2.4:
 * - IN: Internet (1) - the standard class for Internet hostnames
 * - CS: CSNET (2) - obsolete
 * - CH: CHAOS (3) - rarely used
 * - HS: Hesiod (4) - rarely used
 */
enum RecordClass: int
{
    case IN = 1;
    case CS = 2;
    case CH = 3;
    case HS = 4;
}
