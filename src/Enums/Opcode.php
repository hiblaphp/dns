<?php

declare(strict_types=1);

namespace Hibla\Dns\Enums;

/**
 * DNS message operation code (OPCODE).
 *
 * Specifies the kind of query in a DNS message. The opcode is set by the
 * originator of a query and copied into the response.
 *
 * Values defined in RFC 1035 Section 4.1.1:
 * - QUERY: Standard query (0)
 * - IQUERY: Inverse query (1) - obsolete per RFC 3425
 * - STATUS: Server status request (2)
 */
enum Opcode: int
{
    case QUERY = 0;
    case IQUERY = 1;
    case STATUS = 2;
}
