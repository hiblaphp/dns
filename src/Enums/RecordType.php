<?php

declare(strict_types=1);

namespace Hibla\Dns\Enums;

/**
 * DNS resource record type.
 *
 * Specifies the type of DNS record and the format of its data. Each type
 * serves a specific purpose in the DNS system, from basic address resolution
 * to service discovery and security.
 *
 * Common types defined across various RFCs:
 * - A (1): IPv4 address - RFC 1035
 * - NS (2): Authoritative name server - RFC 1035
 * - CNAME (5): Canonical name (alias) - RFC 1035
 * - SOA (6): Start of authority - RFC 1035
 * - PTR (12): Pointer for reverse DNS lookups - RFC 1035
 * - MX (15): Mail exchange server - RFC 1035
 * - TXT (16): Text records for arbitrary data - RFC 1035
 * - AAAA (28): IPv6 address - RFC 3596
 * - SRV (33): Service locator - RFC 2763
 * - OPT (41): EDNS pseudo-record - RFC 6891
 * - SSHFP (44): SSH public key fingerprint - RFC 4255
 * - ANY (255): Request for all records - RFC 1035
 * - CAA (257): Certification Authority Authorization - RFC 6844
 */
enum RecordType: int
{
    case A = 1;
    case NS = 2;
    case CNAME = 5;
    case SOA = 6;
    case PTR = 12;
    case MX = 15;
    case TXT = 16;
    case AAAA = 28;
    case SRV = 33;
    case NAPTR = 35;
    case OPT = 41;
    case SSHFP = 44;
    case ANY = 255;
    case CAA = 257;
}
