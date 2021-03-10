<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use PhpAmqpLib\Message\AMQPMessage;

abstract class BaseConsumer extends BaseAmqp implements DequeuerInterface
{
    /** @var int */
    protected $target;

    /** @var int */
    protected $consumed = 0;

    /** @var callable */
    protected $callback;

    /** @var bool */
    protected $forceStop = false;

    /** @var int */
    protected $idleTimeout = 0;

    /** @var int */
    protected $idleTimeoutExitCode;

    /**
     * @param callable $callback
     */
    public function setCallback($callback): void
    {
        $this->callback = $callback;
    }

    /**
     * @return callable
     */
    public function getCallback()
    {
        return $this->callback;
    }

    /**
     * @throws \ErrorException
     */
    public function start (int $msgAmount = 0): void
    {
        $this->target = $msgAmount;

        $this->setupConsumer();

        while (count($this->getChannel()->callbacks)) {
            $this->getChannel()->wait();
        }
    }

    /**
     * Tell the server you are going to stop consuming.
     *
     * It will finish up the last message and not send you any more.
     */
    public function stopConsuming(): void
    {
        // This gets stuck and will not exit without the last two parameters set.
        $this->getChannel()->basic_cancel($this->getConsumerTag(), false, true);
    }

    public function setupConsumer(): void
    {
        if ($this->autoSetupFabric) {
            $this->setupFabric();
        }
        $this->getChannel()->basic_consume($this->queueOptions['name'], $this->getConsumerTag(), false, false, false, false, array($this, 'processMessage'));
    }

    public function processMessage(AMQPMessage $msg): void
    {
        //To be implemented by descendant classes
    }

    protected function maybeStopConsumer(): void
    {
        if (extension_loaded('pcntl') && (defined('AMQP_WITHOUT_SIGNALS') ? !AMQP_WITHOUT_SIGNALS : true)) {
            if (!function_exists('pcntl_signal_dispatch')) {
                throw new \BadFunctionCallException("Function 'pcntl_signal_dispatch' is referenced in the php.ini 'disable_functions' and can't be called.");
            }

            pcntl_signal_dispatch();
        }

        if ($this->forceStop || ($this->consumed == $this->target && $this->target > 0)) {
            $this->stopConsuming();
        }
    }

    public function setConsumerTag(string $tag): void
    {
        $this->consumerTag = $tag;
    }

    public function getConsumerTag(): ?string
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
        $this->getChannel()->basic_qos($prefetchSize, $prefetchCount, $global);
    }

    public function setIdleTimeout(int $idleTimeout): void
    {
        $this->idleTimeout = $idleTimeout;
    }

    /**
     * Set exit code to be returned when there is a timeout exception
     */
    public function setIdleTimeoutExitCode(?int $idleTimeoutExitCode = null): void
    {
        $this->idleTimeoutExitCode = $idleTimeoutExitCode;
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
}
