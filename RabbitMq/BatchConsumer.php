<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

class BatchConsumer extends BaseAmqp implements DequeuerInterface
{
    /**
     * @var \Closure|callable
     */
    protected $callback;

    /**
     * @var bool
     */
    protected $forceStop = false;

    /**
     * @var int
     */
    protected $idleTimeout = 0;

    /**
     * @var bool
     */
    private $keepAlive = false;

    /**
     * @var int
     */
    protected $idleTimeoutExitCode;

    /**
     * @var int
     */
    protected $memoryLimit = null;

    /**
     * @var int
     */
    protected $prefetchCount;

    /**
     * @var int
     */
    protected $timeoutWait = 3;

    /**
     * @var array
     */
    protected $messages = array();

    /**
     * @var int
     */
    protected $batchCounter = 0;

    /**
     * @var int
     */
    protected $batchAmount = 0;

    /**
     * @var int
     */
    protected $consumed = 0;

    /**
     * @var \DateTime|null DateTime after which the consumer will gracefully exit. "Gracefully" means, that
     *      any currently running consumption will not be interrupted.
     */
    protected $gracefulMaxExecutionDateTime;

    /** @var int */
    private $batchAmountTarget;

    /**
     * @param \DateTime|null $dateTime
     */
    public function setGracefulMaxExecutionDateTime(\DateTime $dateTime = null): void
    {
        $this->gracefulMaxExecutionDateTime = $dateTime;
    }

    public function setGracefulMaxExecutionDateTimeFromSecondsInTheFuture(int $secondsInTheFuture): void
    {
        $this->setGracefulMaxExecutionDateTime(new \DateTime("+{$secondsInTheFuture} seconds"));
    }

    /**
     * @param   \Closure|callable    $callback
     */
    public function setCallback($callback): BatchConsumer
    {
        $this->callback = $callback;

        return $this;
    }

    /**
     * @throws \ErrorException
     */
    public function consume(int $batchAmountTarget = 0): ?int
    {
        $this->batchAmountTarget = $batchAmountTarget;

        $this->setupConsumer();

        while (count($this->getChannel()->callbacks)) {
            if ($this->isCompleteBatch()) {
                $this->batchConsume();
            }

            $this->checkGracefulMaxExecutionDateTime();
            $this->maybeStopConsumer();

            $timeout = $this->isEmptyBatch() ? $this->getIdleTimeout() : $this->getTimeoutWait();

            try {
                $this->getChannel()->wait(null, false, $timeout);
            } catch (AMQPTimeoutException $e) {
                if (!$this->isEmptyBatch()) {
                    $this->batchConsume();
                    $this->maybeStopConsumer();
                } elseif ($this->keepAlive === true) {
                    continue;
                } elseif (null !== $this->getIdleTimeoutExitCode()) {
                    return $this->getIdleTimeoutExitCode();
                } else {
                    throw $e;
                }
            }
        }

        return 0;
    }

    /**
     * @throws \Exception
     */
    private function batchConsume(): void
    {
        try {
            $processFlags = call_user_func($this->callback, $this->messages);
            $this->handleProcessMessages($processFlags);
            $this->logger->debug('Queue message processed', array(
                'amqp' => array(
                    'queue' => $this->queueOptions['name'],
                    'messages' => $this->messages,
                    'return_codes' => $processFlags
                )
            ));
        } catch (Exception\StopConsumerException $e) {
            $this->logger->info('Consumer requested restart', array(
                'amqp' => array(
                    'queue' => $this->queueOptions['name'],
                    'message' => $this->messages,
                    'stacktrace' => $e->getTraceAsString()
                )
            ));
            $this->resetBatch();
            $this->stopConsuming();
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), array(
                'amqp' => array(
                    'queue' => $this->queueOptions['name'],
                    'message' => $this->messages,
                    'stacktrace' => $e->getTraceAsString()
                )
            ));
            $this->resetBatch();
            throw $e;
        } catch (\Error $e) {
            $this->logger->error($e->getMessage(), array(
                'amqp' => array(
                    'queue' => $this->queueOptions['name'],
                    'message' => $this->messages,
                    'stacktrace' => $e->getTraceAsString()
                )
            ));
            $this->resetBatch();
            throw $e;
        }

        $this->batchAmount++;
        $this->resetBatch();
    }

    /**
     * @param   mixed   $processFlags
     */
    protected function handleProcessMessages($processFlags = null): void
    {
        $processFlags = $this->analyzeProcessFlags($processFlags);
        foreach ($processFlags as $deliveryTag => $processFlag) {
            $this->handleProcessFlag($deliveryTag, $processFlag);
        }
    }

    /**
     * @param   string|int     $deliveryTag
     * @param   mixed          $processFlag
     *
     * @return  void
     */
    private function handleProcessFlag($deliveryTag, $processFlag): void
    {
        if ($processFlag === ConsumerInterface::MSG_REJECT_REQUEUE || false === $processFlag) {
            // Reject and requeue message to RabbitMQ
            $this->getMessageChannel($deliveryTag)->basic_reject($deliveryTag, true);
        } else if ($processFlag === ConsumerInterface::MSG_SINGLE_NACK_REQUEUE) {
            // NACK and requeue message to RabbitMQ
            $this->getMessageChannel($deliveryTag)->basic_nack($deliveryTag, false, true);
        } else if ($processFlag === ConsumerInterface::MSG_REJECT) {
            // Reject and drop
            $this->getMessageChannel($deliveryTag)->basic_reject($deliveryTag, false);
        } else {
            // Remove message from queue only if callback return not false
            $this->getMessageChannel($deliveryTag)->basic_ack($deliveryTag);
        }

    }

    protected function isCompleteBatch(): bool
    {
        return $this->batchCounter === $this->prefetchCount;
    }

    protected function isEmptyBatch(): bool
    {
        return $this->batchCounter === 0;
    }

    /**
     * @throws  \Error
     * @throws  \Exception
     */
    public function processMessage(AMQPMessage $msg): void
    {
        $this->addMessage($msg);

        $this->maybeStopConsumer();
    }

    /**
     * @param   mixed   $processFlags
     */
    private function analyzeProcessFlags($processFlags = null): array
    {
        if (is_array($processFlags)) {
            if (count($processFlags) !== $this->batchCounter) {
                throw new AMQPRuntimeException(
                    'Method batchExecute() should return an array with elements equal with the number of messages processed'
                );
            }

            return $processFlags;
        }

        $response = array();
        foreach ($this->messages as $deliveryTag => $message) {
            $response[$deliveryTag] = $processFlags;
        }

        return $response;
    }


    private function resetBatch(): void
    {
        $this->messages = array();
        $this->batchCounter = 0;
    }

    private function addMessage(AMQPMessage $message): void
    {
        $this->batchCounter++;
        $this->messages[(int)$message->delivery_info['delivery_tag']] = $message;
    }

    private function getMessage(int $deliveryTag): ?AMQPMessage
    {
        return isset($this->messages[$deliveryTag])
            ? $this->messages[$deliveryTag]
            : null
        ;
    }

    /**
     * @throws  AMQPRuntimeException
     */
    private function getMessageChannel(int $deliveryTag): AMQPChannel
    {
        $message = $this->getMessage($deliveryTag);
        if ($message === null) {
            throw new AMQPRuntimeException(sprintf('Unknown delivery_tag %d!', $deliveryTag));
        }

        return $message->delivery_info['channel'];
    }

    public function stopConsuming(): void
    {
        if (!$this->isEmptyBatch()) {
            $this->batchConsume();
        }

        $this->getChannel()->basic_cancel($this->getConsumerTag(), false, true);
    }

    protected function setupConsumer(): void
    {
        if ($this->autoSetupFabric) {
            $this->setupFabric();
        }

        $this->getChannel()->basic_consume($this->queueOptions['name'], $this->getConsumerTag(), false, false, false, false, array($this, 'processMessage'));
    }

    /**
     * @throws \BadFunctionCallException
     */
    protected function maybeStopConsumer(): void
    {
        if (extension_loaded('pcntl') && (defined('AMQP_WITHOUT_SIGNALS') ? !AMQP_WITHOUT_SIGNALS : true)) {
            if (!function_exists('pcntl_signal_dispatch')) {
                throw new \BadFunctionCallException("Function 'pcntl_signal_dispatch' is referenced in the php.ini 'disable_functions' and can't be called.");
            }

            pcntl_signal_dispatch();
        }

        if ($this->forceStop || ($this->batchAmount == $this->batchAmountTarget && $this->batchAmountTarget > 0)) {
            $this->stopConsuming();
        }

        if (null !== $this->getMemoryLimit() && $this->isRamAlmostOverloaded()) {
            $this->stopConsuming();
        }
    }

    public function setConsumerTag(string $tag): BatchConsumer
    {
        $this->consumerTag = $tag;

        return $this;
    }

    public function getConsumerTag(): string
    {
        return $this->consumerTag;
    }

    public function forceStopConsumer(): void
    {
        $this->forceStop = true;
    }

    /**
     * Sets the qos settings for the current channel
     * Consider that prefetchSize and global do not work with rabbitMQ version <= 8.0
     */
    public function setQosOptions(int $prefetchSize = 0, int $prefetchCount = 0, bool $global = false): void
    {
        $this->prefetchCount = $prefetchCount;
        $this->getChannel()->basic_qos($prefetchSize, $prefetchCount, $global);
    }

    public function setIdleTimeout(int $idleTimeout): BatchConsumer
    {
        $this->idleTimeout = $idleTimeout;

        return $this;
    }

    /**
     * Set exit code to be returned when there is a timeout exception
     */
    public function setIdleTimeoutExitCode(int $idleTimeoutExitCode): BatchConsumer
    {
        $this->idleTimeoutExitCode = $idleTimeoutExitCode;

        return $this;
    }

    /**
     * keepAlive
     */
    public function keepAlive(): BatchConsumer
    {
        $this->keepAlive = true;

        return $this;
    }

    /**
     * Purge the queue
     */
    public function purge(): void
    {
        $this->getChannel()->queue_purge($this->queueOptions['name'], true);
    }

    /**
     * Delete the queue
     */
    public function delete(): void
    {
        $this->getChannel()->queue_delete($this->queueOptions['name'], true);
    }

    /**
     * Checks if memory in use is greater or equal than memory allowed for this process
     */
    protected function isRamAlmostOverloaded(): bool
    {
        return (memory_get_usage(true) >= ($this->getMemoryLimit() * 1048576));
    }

    public function getIdleTimeout(): int
    {
        return $this->idleTimeout;
    }

    /**
     * Get exit code to be returned when there is a timeout exception
     */
    public function getIdleTimeoutExitCode(): ?int
    {
        return $this->idleTimeoutExitCode;
    }

    /**
     * Resets the consumed property.
     * Use when you want to call start() or consume() multiple times.
     */
    public function resetConsumed(): void
    {
        $this->consumed = 0;
    }

    public function setTimeoutWait(int $timeout): BatchConsumer
    {
        $this->timeoutWait = $timeout;

        return $this;
    }

    public function setPrefetchCount(int $amount): BatchConsumer
    {
        $this->prefetchCount = $amount;

        return $this;
    }

    public function getTimeoutWait(): int
    {
        return $this->timeoutWait;
    }

    public function getPrefetchCount(): int
    {
        return $this->prefetchCount;
    }

    /**
     * Set the memory limit
     */
    public function setMemoryLimit(int $memoryLimit): void
    {
        $this->memoryLimit = $memoryLimit;
    }

    /**
     * Get the memory limit
     */
    public function getMemoryLimit(): int
    {
        return $this->memoryLimit;
    }

    /**
     * Check graceful max execution date time and stop if limit is reached
     */
    private function checkGracefulMaxExecutionDateTime(): void
    {
        if (!$this->gracefulMaxExecutionDateTime) {
            return;
        }

        $now = new \DateTime();

        if ($this->gracefulMaxExecutionDateTime > $now) {
            return;
        }

        $this->forceStopConsumer();
    }
}
