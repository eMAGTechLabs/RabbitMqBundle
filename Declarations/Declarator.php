<?php


namespace OldSound\RabbitMqBundle\Declarations;


use OldSound\RabbitMqBundle\RabbitMq\BindingDeclaration;
use OldSound\RabbitMqBundle\RabbitMq\ExchangeDeclaration;
use OldSound\RabbitMqBundle\RabbitMq\QueueDeclaration;
use PhpAmqpLib\Channel\AMQPChannel;

class Declarator
{
    /** @var AMQPChannel */
    private $channel;
    
    public function __construct(AMQPChannel $channel)
    {
        $this->channel = $channel;
    }

    /**
     * @param ExchangeDeclaration[] $exchanges
     */
    public function declareExchanges(array $exchanges) 
    {
        foreach ($exchanges as $exchange) {
            $this->channel->exchange_declare(
                $exchange->name,
                $exchange->type,
                $exchange->passive,
                $exchange->durable,
                $exchange->autoDelete,
                $exchange->internal,
                $exchange->nowait,
                $exchange->arguments,
                $exchange->ticket,
            );
        }
    }

    /**
     * @param QueueDeclaration[] $queues
     */
    public function declareQueues(array $queues) 
    {
        foreach ($queues as $queue) {
            $this->channel->queue_declare(
                $queue->name,
                $queue->passive,
                $queue->durable,
                $queue->exclusive,
                $queue->autoDelete,
                $queue->nowait,
                $queue->arguments,
                $queue->ticket,
            );
        }
    }

    /**
     * @param BindingDeclaration[] $bindings
     */
    public function declareBindings(array $bindings) 
    {
        foreach ($bindings as $binding) {
            if ($this->destinationIsExchange) {
                foreach ($binding->routingKeys as $routingKey) {
                    $this->channel->exchange_bind(
                        $binding->destination,
                        $binding->exchange,
                        $routingKey,
                        $binding->nowait,
                        $binding->arguments
                    );
                }
            } else {
                foreach ($binding->routingKeys as $routingKey) {
                    $this->channel->queue_bind(
                        $binding->destination,
                        $binding->exchange,
                        $routingKey,
                        $binding->nowait,
                        $binding->arguments
                    );
                }
            }
        }
    }

    public function declareForExchange(ExchangeDeclaration $exchange, DeclarationsRegistry $declarationsRegistry) 
    {
        $bindings = array_filter($declarationsRegistry->bindings, function ($binding) use ($exchange) {
           return $binding->exchange === $exchange->name || 
               $binding->destinationIsExchange && $binding->destination === $exchange->name;
        });

        $queues = array_filter($bindings, function ($binding) use($exchange) {
            false === $binding->destinationIsExchange && $binding->destination == $exchange->name;
        });

        $this->declareExchanges([$exchange]);
        $this->declareQueues($queues);
        $this->declareBindings($bindings);
    }
    
    public function declareForQueue(QueueDeclaration $queue)
    {
        $exchanges = array_map(function ($binding) {
            return $binding->getExchange();
        }, $queue->bindings);

        $this->declareExchanges($exchanges);
        $this->declareQueues([$queue]);
        $this->declareBindings($queue->bindings);
    }
    
    public function purgeQueue(QueueDeclaration $queue, $nowait = true, ?int $ticket = null)
    {
        $this->channel->queue_purge($queue->name, $nowait, $ticket);
    }
    
    public function deleteQueue(
        QueueDeclaration $queue, 
        bool $ifUnsed = true, 
        bool $ifEmpry = false,
        bool $nowait = false, 
        ?int $ticket = null
    ) {
        $this->channel->queue_delete($queue->name, $ifUnsed, $ifEmpry, $nowait, $ticket);
    }
}