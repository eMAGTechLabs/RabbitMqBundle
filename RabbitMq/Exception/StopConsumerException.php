<?php

namespace OldSound\RabbitMqBundle\RabbitMq\Exception;
use OldSound\RabbitMqBundle\RabbitMq\ConsumerInterface;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * If this exception is thrown in consumer service the message
 * will not be ack and consumer will stop
 * if using demonized, ex: supervisor, the consumer will actually restart
 * Class StopConsumerException
 * @package OldSound\RabbitMqBundle\RabbitMq\Exception
 */
class StopConsumerException extends \RuntimeException
{
    /** @var int */
    private $handleCode;

    public function __construct(int $handleCode = ConsumerInterface::MSG_SINGLE_NACK_REQUEUE, $message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->handleCode = $handleCode;
    }

    public function getHandleCode(): int
    {
        return $this->handleCode;
    }
}

