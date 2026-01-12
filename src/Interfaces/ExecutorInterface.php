<?php

declare(strict_types=1);

namespace Hibla\Dns\Interfaces;

use Hibla\Dns\Models\Message;
use Hibla\Dns\Models\Query;
use Hibla\Promise\Interfaces\PromiseInterface;

interface ExecutorInterface
{
    /**
     * Executes a DNS query.
     *
     * @param Query $query
     * @return PromiseInterface<Message> Resolves with the response Message.
     */
    public function query(Query $query): PromiseInterface;
}