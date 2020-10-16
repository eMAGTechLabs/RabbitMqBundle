<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

class Binding extends BaseAmqp
{
    /**
     * @var string
     */
    protected $exchange;

    /**
     * @var string
     */
    protected $destination;

    /**
     * @var bool
     */
    protected $destinationIsExchange = false;

    /**
     * @var string
     */
    protected $routingKey;

    /**
     * @var bool
     */
    protected $nowait = false;

    /**
     * @var array
     */
    protected $arguments;

     public function getExchange(): string
    {
        return $this->exchange;
    }

     public function setExchange(string $exchange): void
    {
        $this->exchange = $exchange;
    }

    public function getDestination(): string
    {
        return $this->destination;
    }

    public function setDestination(string $destination): void
    {
        $this->destination = $destination;
    }

    public function getDestinationIsExchange(): bool
    {
        return $this->destinationIsExchange;
    }

    public function setDestinationIsExchange(bool $destinationIsExchange): void
    {
        $this->destinationIsExchange = $destinationIsExchange;
    }

    public function getRoutingKey(): string
    {
        return $this->routingKey;
    }

    public function setRoutingKey(string $routingKey): void
    {
        $this->routingKey = $routingKey;
    }

    public function isNowait(): bool
    {
        return $this->nowait;
    }

    public function setNowait(bool $nowait): void
    {
        $this->nowait = $nowait;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function setArguments(array $arguments): void
    {
        $this->arguments = $arguments;
    }


    /**
     * create bindings
     */
    public function setupFabric(): void
    {
        $method  = ($this->destinationIsExchange) ? 'exchange_bind' : 'queue_bind';
        $channel = $this->getChannel();
        call_user_func(
            array($channel, $method),
            $this->destination,
            $this->exchange,
            $this->routingKey,
            $this->nowait,
            $this->arguments
        );
    }
}
