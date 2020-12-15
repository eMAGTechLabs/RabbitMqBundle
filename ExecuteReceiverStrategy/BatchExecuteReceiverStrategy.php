<?php

namespace OldSound\RabbitMqBundle\ExecuteReceiverStrategy;

use OldSound\RabbitMqBundle\Declarations\ConsumeOptions;
use OldSound\RabbitMqBundle\ExecuteCallbackStrategy\ExecuteReceiverStrategyInterface;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

class BatchExecuteReceiverStrategy extends AbstractExecuteReceiverStrategy
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

    public function onConsumeCallback(AMQPMessage $message): ?array
    {
        $this->messagesBatch[$message->getDeliveryTag()] = $message;

        if ($this->isBatchCompleted()) {
            return $this->execute($this->messagesBatch);
        }
    }

    public function onMessageProcessed(AMQPMessage $message)
    {
        unset($this->messagesBatch[array_search($message, $this->messagesBatch, true)]);
    }

    public function onCatchTimeout(AMQPTimeoutException $e)
    {
        if (!$this->isBatchEmpty()) {
            $this->processMessages($this->messagesBatch);
        }
    }

    public function onStopConsuming()
    {
        if (!$this->isBatchEmpty()) {
            $this->processMessages($this->messagesBatch);
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