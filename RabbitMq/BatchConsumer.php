<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use OldSound\RabbitMqBundle\Declarations\QueueConsuming;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

class BatchConsumer extends Consumer implements DequeuerInterface
{
    /** @var array
     * [0 => AMQPMessage, 1 => QueueConsuming]
     */
    protected $batch = [];

    /** @var int */
    protected $batchCount;

    public function __construct(string $name, AMQPChannel $channel, int $batchCount)
    {
        parent::__construct($name, $channel);
        $this->batchCount = $batchCount;
    }


    protected function preConsume()
    {
        parent::preConsume();

        if ($this->isCompleteBatch()) {
            $this->releaseMessagesBuffer();
        }
    }

    protected function catchTimeout(AMQPTimeoutException $e)
    {
        if (count($this->batch) > 0) {
            $this->runConsumeCallbacks();
        }
    }

    public function consume(int $msgAmount = null)
    {
        if($msgAmount !== null) {
            throw new \InvalidArgumentException('Can not specify msgAmount for batchConsumer');
        }

        return parent::consume(null);
    }

    /**
     * @return  bool
     */
    protected function isCompleteBatch()
    {
        return count($this->batch) === $this->batchCount;
    }

    /**
     * @return  bool
     */
    protected function isEmptyBatch()
    {
        return count($this->batch) === 0;
    }

    protected function consumeCallback(AMQPMessage $message, QueueConsuming $queueConsuming)
    {
        $this->batch[$message->getDeliveryTag()] = [$message, $queueConsuming];

        if ($this->isCompleteBatch()) {
            $this->runConsumeCallbacks();
        }
    }

    protected function runConsumeCallbacks()
    {
        try {
            parent::processMessages(array_column($this->batch, 0), array_column($this->batch, 1));
            $this->batch = [];
        } catch (\Throwable $exception) {
            $this->batch = [];
            throw $exception;
        }
    }

    /**
     * @return  void
     */
    public function stopConsuming()
    {
        if (!$this->isEmptyBatch()) {
            $this->runConsumeCallbacks();
        }

        parent::stopConsuming();
    }
}
