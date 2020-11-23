<?php

namespace OldSound\RabbitMqBundle\ExecuteCallbackStrategy;

use OldSound\RabbitMqBundle\Declarations\QueueConsuming;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

class BatchExecuteCallbackStrategy extends AbstractExecuteCallbackStrategy
{
    /** @var int */
    private $batchCount;
    /** @var AMQPMessage[] */
    protected $messagesBatch = [];

    public function __construct(int $batchCount)
    {
        $this->batchCount = $batchCount;
    }

    public function canPrecessMultiMessages(): bool
    {
        return true;
    }

    public function consumeCallback(AMQPMessage $message)
    {
        $this->messagesBatch[$message->getDeliveryTag()] = $message;

        if ($this->isBatchCompleted()) {
            $this->proccessMessages($this->messagesBatch);
        }
    }

    public function onMessageProcessed(AMQPMessage $message)
    {
        unset($this->messagesBatch[array_search($message, $this->messagesBatch, true)]);
    }

    public function onCatchTimeout(AMQPTimeoutException $e)
    {
        if (!$this->isBatchEmpty()) {
            $this->proccessMessages($this->messagesBatch);
        }
    }

    public function onStopConsuming()
    {
        if (!$this->isEmptyBatch()) {
            $this->proccessMessages($this->messagesBatch);
        }
    }

    protected function isBatchCompleted(): bool
    {
        return count($this->messagesBatch) === $this->batchCount;
    }

    /**
     * @return  bool
     */
    protected function isBatchEmpty()
    {
        return count($this->messagesBatch) === 0;
    }
}