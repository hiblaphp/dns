<?php

declare(strict_types=1);

namespace Hibla\Dns\Exceptions;

/**
 * Thrown when the DNS server returns NXDOMAIN (response code 3).
 *
 * NXDOMAIN means the queried domain does not exist at all in the DNS.
 * No records of any type exist for this name. Retrying with a different
 * record type will not help — the domain itself is absent.
 */
class NxDomainException extends RecordNotFoundException
{
}
