<?php

declare(strict_types=1);

namespace Hibla\Dns\Models;

use Hibla\Dns\Enums\Opcode;
use Hibla\Dns\Enums\ResponseCode;
use Random\Randomizer;

final class Message
{
    public int $id;

    public Opcode $opcode = Opcode::QUERY;

    public ResponseCode $responseCode = ResponseCode::OK;

    /**
     * Query (false) or Response (true).
     * RFC 1035: QR
     */
    public bool $isResponse = false;


    /**
     * Authoritative Answer.
     * RFC 1035: AA
     */
    public bool $isAuthoritative = false;

    /**
     * TrunCated.
     * RFC 1035: TC
     */
    public bool $isTruncated = false;

    /**
     * Recursion Desired.
     * RFC 1035: RD
     */
    public bool $recursionDesired = false;

    /**
     * Recursion Available.
     * RFC 1035: RA
     */
    public bool $recursionAvailable = false;


    /**
     *  @var list<Query> 
     */
    public array $questions = [];

    /**
     *  @var list<Record> 
     */
    public array $answers = [];

    /**
     *  @var list<Record> 
     */
    public array $authority = [];

    /**
     *  @var list<Record> 
     */
    public array $additional = [];

    public function __construct()
    {
        $this->id = (new Randomizer())->getInt(0, 0xFFFF);
    }

    /**
     * @param Query $query The query to be added to the message.
     * @return self A new Message instance with the query added.
     */
    public static function createRequest(Query $query): self
    {
        $message = new self();
        $message->recursionDesired = true;
        $message->questions[] = $query;
        return $message;
    }
}
