<?php

declare(strict_types=1);

namespace Hibla\Dns\Enums;

/**
 * DNS response code (RCODE).
 *
 * Indicates the status of a DNS query response. Set by the server to
 * communicate whether the query was successful or what type of error occurred.
 *
 * Values defined in RFC 1035 Section 4.1.1:
 * - OK (0): No error, query successful
 * - FORMAT_ERROR (1): Server unable to interpret query due to format error
 * - SERVER_FAILURE (2): Server failure, unable to process query
 * - NAME_ERROR (3): Domain name does not exist (NXDOMAIN)
 * - NOT_IMPLEMENTED (4): Server does not support the requested query type
 * - REFUSED (5): Server refuses to perform the operation (policy reasons)
 */
enum ResponseCode: int
{
    case OK = 0;
    case FORMAT_ERROR = 1;
    case SERVER_FAILURE = 2;
    case NAME_ERROR = 3;
    case NOT_IMPLEMENTED = 4;
    case REFUSED = 5;
}
