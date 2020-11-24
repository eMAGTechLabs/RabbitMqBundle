<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use OldSound\RabbitMqBundle\Event\AMQPEvent;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as ContractsEventDispatcherInterface;

abstract class BaseAmqp
{
    /** @var AbstractConnection */
    protected $conn;
    /** @var AMQPChannel|null */
    protected $ch;
    /** @var string|null */
    protected $consumerTag;
    /** @var bool */
    protected $exchangeDeclared = false;
    /** @var bool */
    protected $queueDeclared = false;
    /** @var string */
    protected $routingKey = '';
    /** @var bool */
    protected $autoSetupFabric = true;
    /** @var array */
    protected $basicProperties = array('content_type' => 'text/plain', 'delivery_mode' => 2);

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /** @var array */
    protected $exchangeOptions = array(
        'passive' => false,
        'durable' => true,
        'auto_delete' => false,
        'internal' => false,
        'nowait' => false,
        'arguments' => null,
        'ticket' => null,
        'declare' => true,
    );

    /** @var array */
    protected $queueOptions = array(
        'name' => '',
        'passive' => false,
        'durable' => true,
        'exclusive' => false,
        'auto_delete' => false,
        'nowait' => false,
        'arguments' => null,
        'ticket' => null,
        'declare' => true,
    );

    /**
     * @var EventDispatcherInterface|null
     */
    protected $eventDispatcher = null;

    /**
     * @param AbstractConnection   $conn
     * @param AMQPChannel|null $ch
     * @param null             $consumerTag
     */
    public function __construct(AbstractConnection $conn, AMQPChannel $ch = null, $consumerTag = null)
    {
        $this->conn = $conn;
        $this->ch = $ch;

        if ($conn->connectOnConstruct()) {
            $this->getChannel();
        }

        $this->consumerTag = empty($consumerTag) ? sprintf("PHPPROCESS_%s_%s", gethostname(), getmypid()) : $consumerTag;

        $this->logger = new NullLogger();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if ($this->ch) {
            try {
                $this->ch->close();
            } catch (\Exception $e) {
                // ignore on shutdown
            }
        }

        if (!empty($this->conn) && $this->conn->isConnected()) {
            try {
                $this->conn->close();
            } catch (\Exception $e) {
                // ignore on shutdown
            }
        }
    }

    public function reconnect(): void
    {
        if (!$this->conn->isConnected()) {
            return;
        }

        $this->conn->reconnect();
    }

    public function getChannel(): ?AMQPChannel
    {
        if (empty($this->ch) || null === $this->ch->getChannelId()) {
            $this->ch = $this->conn->channel();
        }

        return $this->ch;
    }

    public function setChannel(AMQPChannel $ch): void
    {
        $this->ch = $ch;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function setExchangeOptions(array $options = array()): void
    {
        if (!isset($options['name'])) {
            throw new \InvalidArgumentException('You must provide an exchange name');
        }

        if (empty($options['type'])) {
            throw new \InvalidArgumentException('You must provide an exchange type');
        }

        $this->exchangeOptions = array_merge($this->exchangeOptions, $options);
    }

    public function setQueueOptions(array $options = array()): void
    {
        $this->queueOptions = array_merge($this->queueOptions, $options);
    }

    public function setRoutingKey(string $routingKey): void
    {
        $this->routingKey = $routingKey;
    }

    public function setupFabric(): void
    {
        if (!$this->exchangeDeclared) {
            $this->exchangeDeclare();
        }

        if (!$this->queueDeclared) {
            $this->queueDeclare();
        }
    }

    /**
     * disables the automatic SetupFabric when using a consumer or producer
     */
    public function disableAutoSetupFabric(): void
    {
        $this->autoSetupFabric = false;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Declares exchange
     */
    protected function exchangeDeclare(): void
    {
        if ($this->exchangeOptions['declare']) {
            $this->getChannel()->exchange_declare(
                $this->exchangeOptions['name'],
                $this->exchangeOptions['type'],
                $this->exchangeOptions['passive'],
                $this->exchangeOptions['durable'],
                $this->exchangeOptions['auto_delete'],
                $this->exchangeOptions['internal'],
                $this->exchangeOptions['nowait'],
                $this->exchangeOptions['arguments'],
                $this->exchangeOptions['ticket']);

            $this->exchangeDeclared = true;
        }
    }

    /**
     * Declares queue, creates if needed
     */
    protected function queueDeclare(): void
    {
        if ($this->queueOptions['declare']) {
            list($queueName, ,) = $this->getChannel()->queue_declare($this->queueOptions['name'], $this->queueOptions['passive'],
                $this->queueOptions['durable'], $this->queueOptions['exclusive'],
                $this->queueOptions['auto_delete'], $this->queueOptions['nowait'],
                $this->queueOptions['arguments'], $this->queueOptions['ticket']);

            if (isset($this->queueOptions['routing_keys']) && count($this->queueOptions['routing_keys']) > 0) {
                foreach ($this->queueOptions['routing_keys'] as $routingKey) {
                    $this->queueBind($queueName, $this->exchangeOptions['name'], $routingKey, $this->queueOptions['arguments'] ?? []);
                }
            } else {
                $this->queueBind($queueName, $this->exchangeOptions['name'], $this->routingKey, $this->queueOptions['arguments'] ?? []);
            }

            $this->queueDeclared = true;
        }
    }

    /**
     * Binds queue to an exchange
     */
    protected function queueBind(string $queue, string $exchange, string $routing_key, array $arguments = array()): void
    {
        // queue binding is not permitted on the default exchange
        if ('' !== $exchange) {
            $this->getChannel()->queue_bind($queue, $exchange, $routing_key, false, $arguments);
        }
    }

    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): BaseAmqp
    {
        $this->eventDispatcher = $eventDispatcher;

        return $this;
    }

    protected function dispatchEvent(string $eventName, AMQPEvent $event): void
    {
        if ($this->getEventDispatcher() instanceof ContractsEventDispatcherInterface) {
            $this->getEventDispatcher()->dispatch(
                $event,
                $eventName
            );
        }
    }

    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }
}
