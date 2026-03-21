<?php

declare(strict_types=1);

namespace Hibla\Dns\Exceptions;

/**
 * Thrown when the DNS server returns NOERROR but no records of the
 * requested type exist for the domain (NODATA / NOERROR-NODATA).
 *
 * NODATA means the domain exists and is valid, but it has no records
 * of the type you queried. For example, querying AAAA for a domain
 * that only has A records produces a NODATA response.
 */
class NoDataException extends RecordNotFoundException
{
}
