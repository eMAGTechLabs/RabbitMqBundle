<?php

namespace OldSound\RabbitMqBundle\DependencyInjection;

use OldSound\RabbitMqBundle\Consumer\ConsumersRegistry;
use OldSound\RabbitMqBundle\Declarations\DeclarationsRegistry;
use OldSound\RabbitMqBundle\Declarations\QueueConsuming;
use OldSound\RabbitMqBundle\Declarations\BindingDeclaration;
use OldSound\RabbitMqBundle\Declarations\ExchangeDeclaration;
use OldSound\RabbitMqBundle\Declarations\QueueDeclaration;
use OldSound\RabbitMqBundle\ExecuteCallbackStrategy\SimpleExecuteCallbackStrategy;
use OldSound\RabbitMqBundle\ExecuteCallbackStrategy\BatchExecuteCallbackStrategy;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\Serializer\SerializerInterface;

/**+
 * OldSoundRabbitMqExtension.
 *
 * @author Alvaro Videla
 * @author Marc Weistroff <marc.weistroff@sensio.com>
 */
class OldSoundRabbitMqExtension extends Extension
{
    /**
     * @var ContainerBuilder
     */
    private $container;

    /**
     * @var Boolean Whether the data collector is enabled
     */
    private $collectorEnabled;

    private $channelIds = [];
    private $groups = [];

    private $config = [];

    public function load(array $configs, ContainerBuilder $container)
    {
        $this->container = $container;

        $loader = new XmlFileLoader($this->container, new FileLocator(array(__DIR__ . '/../Resources/config')));
        $loader->load('rabbitmq.xml');

        $configuration = $this->getConfiguration($configs, $container);
        $this->config = $this->processConfiguration($configuration, $configs);

        $this->collectorEnabled = $this->config['enable_collector'];

        $this->loadConnections();

        $declarationRegistryDef = new Definition(DeclarationsRegistry::class);
        $declarationRegistryDef->setPublic(true);
        $declarationRegistryDef->setAutowired(true);
        $this->container->setDefinition('old_sound_rabbit_mq.declaration_registry', $declarationRegistryDef);

        # declarations
        foreach ($this->loadExchanges($this->config['declarations']['exchanges']) as $exchange) {
            $declarationRegistryDef->addMethodCall('addExchange', [$exchange]);
        };
        foreach ($this->loadQueues($this->config['declarations']['queues']) as $queue) {
            $declarationRegistryDef->addMethodCall('addQueue', [$queue]);
        };
        foreach ($this->loadBindings($this->config['declarations']['bindings']) as $binding) {
            $this->container->getDefinition('old_sound_rabbit_mq.declaration_registry')->addMethodCall('addBinding', [$binding]);
        };

        $this->loadProducers();
        $this->loadConsumers();

        if ($this->collectorEnabled && $this->channelIds) {
            $channels = [];
            foreach (array_unique($this->channelIds) as $id) {
                $channels[] = new Reference($id);
            }

            $definition = $container->getDefinition('old_sound_rabbit_mq.data_collector');
            $definition->replaceArgument(0, $channels);
        } else {
            $this->container->removeDefinition('old_sound_rabbit_mq.data_collector');
        }
    }

    public function getConfiguration(array $config, ContainerBuilder $container)
    {
        return new Configuration($this->getAlias());
    }

    /**
     * @return Definition[]
     */
    protected function loadExchanges($exchanges): array
    {
        return array_map(function ($exchange) {
            $exchangeDeclaration = new Definition(ExchangeDeclaration::class);
            $exchangeDeclaration->setProperties($exchange);

            foreach($this->loadBindings($exchange['bindings'], $exchange['name'], null) as $binding) {
                $this->container->getDefinition('old_sound_rabbit_mq.declaration_registry')->addMethodCall('addBinding', [$binding]);
            }

            $this->container->setDefinition('old_sound_rabbit_mq.exchange.'.$exchange['name'], $exchangeDeclaration);
            return $exchangeDeclaration;
        }, $exchanges);
    }

    /**
     * @return Definition[]
     */
    protected function loadQueues($queues): array
    {
        return array_map(function ($queue, $key) use ($queues) {
            $queue['name'] = $queue['name'] ?? $key;
            $queueDeclaration = new Definition(QueueDeclaration::class);
            $queueDeclaration->setProperties($queue);

            foreach ($this->loadBindings($queue['bindings'], null, $queue['name'], false) as $binding) {
                $this->container->getDefinition('old_sound_rabbit_mq.declaration_registry')->addMethodCall('addBinding', [$binding]);
            }

            return $queueDeclaration;
        }, $queues, array_keys($queues));
    }

    protected function createBindingDef($binding, string $exchange = null, string $destination = null, bool $destinationIsExchange = null): Definition
    {
        $routingKeys = $binding['routing_keys'];
        if (isset($binding['routing_key'])) {
            $routingKeys[] = $binding['routing_key'];
        }
        $definition = new Definition(BindingDeclaration::class);
        $definition->setProperties([
            'exchange' => $exchange ? $exchange : $binding['exchange'],
            'destinationIsExchange' => isset($destinationIsExchange) ? $destinationIsExchange : $binding['destination_is_exchange'],
            'destination' => $destination ? $destination : $binding['destination'],
            'routingKeys' => array_unique($routingKeys),
            // TODO 'arguments' => $binding['arguments'],
            //'nowait' => $binding['nowait'],
        ]);

        return $definition;
    }

    protected function loadBindings($bindings, string $exchange = null, string $destination = null, bool $destinationIsExchange = null): array
    {
        $definitions = [];
        foreach ($bindings as $binding) {
            $definitions[] = $this->createBindingDef($binding, $exchange, $destination, $destinationIsExchange);
        }

        return $definitions;
    }

    protected function loadConnections()
    {
        $connFactoryDer = new Definition('%old_sound_rabbit_mq.connection_factory.class%');

        foreach ($this->config['connections'] as $key => $connection) {
            $connectionSuffix = $connection['use_socket'] ? 'socket_connection.class' : 'connection.class';
            $classParam =
                $connection['lazy']
                    ? '%old_sound_rabbit_mq.lazy.'.$connectionSuffix.'%'
                    : '%old_sound_rabbit_mq.'.$connectionSuffix.'%';

            $definition = new Definition($classParam);
            $definition->setPublic(false);

            $definition->setFactory([$connFactoryDer, 'createConnection']);
            $definition->setArguments([$classParam, $connection]);

            $definition->addTag('old_sound_rabbit_mq.connection');
            $definition->setPublic(true);

            $connectionAliase = sprintf('old_sound_rabbit_mq.connection.%s', $key);
            $this->container->setDefinition($connectionAliase, $definition);

            $channelDef = new Definition(AMQPChannel::class, [
                new Reference($connectionAliase)
            ]);
            $channelDef->setFactory([self::class, 'getChannelFromConnection']);
            $channelDef->setPublic(true);
            $this->container->setDefinition(sprintf('old_sound_rabbit_mq.channel.%s', $key), $channelDef);
        }
    }

    public static function getChannelFromConnection(AbstractConnection $connection)
    {
        return $connection->channel();
    }

    protected function loadProducers()
    {
        if ($this->config['sandbox']) {
            foreach ($this->config['producers'] as $key => $producer) {
                $definition = new Definition('%old_sound_rabbit_mq.fallback.class%');
                $this->container->setDefinition(sprintf('old_sound_rabbit_mq.producer.%s', $key), $definition);
            }
            return;
        }

        $defaultAutoDeclare = $this->container->getParameter('kernel.environment') !== 'prod';
        foreach ($this->config['producers'] as $producerName => $producer) {
            $alias = sprintf('old_sound_rabbit_mq.producer.%s', $producerName);

            $definition = new Definition($producer['class']);
            $definition->setPublic(true);
            $definition->addTag('old_sound_rabbit_mq.producer', ['producer' => $producerName]);
            //this producer doesn't define an exchange -> using AMQP Default
            if (!isset($producer['exchange_options'])) {
                $producer['exchange_options'] = $this->getDefaultExchangeOptions();
            }
            //$definition->addMethodCall('setExchangeOptions', array($this->normalizeArgumentKeys($producer['exchange_options'])));
            //this producer doesn't define a queue -> using AMQP Default
            if (!isset($producer['queue_options'])) {
                $producer['queue_options'] = $this->getDefaultQueueOptions();
            }
            //$definition->addMethodCall('setQueueOptions', array($producer['queue_options']));

            $definition->addArgument($this->createChannelReference($producer['connection']));
            $definition->addArgument($producer['exchange']);
            //$this->injectConnection($definition, $producer['connection']);
            //if ($this->collectorEnabled) {
            //    $this->injectTraceableChannel($definition, $key, $producer['connection']);
            //}

            if (isset($producer['auto_declare'])) {
                $definition->setProperty('autoDeclare', $producer['auto_declare'] ?? $defaultAutoDeclare);
            }

            $this->container->setDefinition($alias, $definition);
            if ($producer['logging']) {
                $this->injectLogger($alias);
            }
        }
    }

    private function createChannelReference($connectionName): Reference
    {
        return new Reference(sprintf('old_sound_rabbit_mq.channel.%s', $connectionName));
    }

    protected function loadConsumers()
    {
        foreach ($this->config['consumers'] as $consumerName => $consumer) {
            $alias = sprintf('old_sound_rabbit_mq.consumer.%s', $consumerName);
            $serializerAlias = sprintf('old_sound_rabbit_mq.consumer.%s.serializer', $consumerName);// TODO

            $connectionName = $consumer['connection'] ?? 'default';

            $definition = new Definition('%old_sound_rabbit_mq.consumer.class%', [
                $this->createChannelReference($connectionName)
            ]);
            $definition->setPublic(true);
            $definition->addTag('old_sound_rabbit_mq.consumer', ['consumer' => $consumerName]);
            // TODO $this->container->setAlias($serializerAlias, SerializerInterface::class);
            // $definition->addMethodCall('setSerializer', [new Reference($serializerAlias)]);}
            foreach($consumer['consumeQueues'] as $index => $consumeQueue) {
                $queueConsumingDef = new Definition(QueueConsuming::class);
                $queueConsumingDef->setProperties([
                    'queueName' => $consumeQueue['queue'],
                    'receiver' => new Reference($consumeQueue['receiver']),
                    //'qosPrefetchSize' => $consumeQueue['qos_prefetch_size'],
                    'qosPrefetchCount' => $consumeQueue['qos_prefetch_count'],
                    'batchCount' => $consumeQueue['batch_count'] ?? null,
                    //'consumerTag' => $consumeQueue['consumer_tag'],
                    //'noLocal' => $consumeQueue['no_local'],
                ]);

                $queueConsumingDef->addTag(sprintf('old_sound_rabbit_mq.%s.queue_consuming', $connectionName));
                $this->container->setDefinition(sprintf('old_sound_rabbit_mq.%s.queue_consuming.%s', $connectionName, $consumerName), $queueConsumingDef);
                $definition->addMethodCall('consumeQueue', [$queueConsumingDef]);
            }

            $definition->addMethodCall('setEventDispatcher', [
                new Reference('event_dispatcher', ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
            ]);

            /* TODO if (array_key_exists('qos_options', $consumer)) {
                $definition->addMethodCall('setQosOptions', array(
                    $consumer['qos_options']['prefetch_size'],
                    $consumer['qos_options']['prefetch_count'],
                    $consumer['qos_options']['global']
                ));
            }*/

            if (isset($consumer['idle_timeout'])) {
                $definition->addMethodCall('setIdleTimeout', array($consumer['idle_timeout']));
            }
            if (isset($consumer['idle_timeout_exit_code'])) {
                $definition->addMethodCall('setIdleTimeoutExitCode', array($consumer['idle_timeout_exit_code']));
            }
            if (isset($consumer['timeout_wait'])) {
                $definition->setProperty('timeoutWait', [$consumer['timeout_wait']]);
            }
            if (isset($consumer['graceful_max_execution'])) {
                $definition->addMethodCall(
                    'setGracefulMaxExecutionDateTimeFromSecondsInTheFuture',
                    array($consumer['graceful_max_execution']['timeout'])
                );
                $definition->addMethodCall(
                    'setGracefulMaxExecutionTimeoutExitCode',
                    array($consumer['graceful_max_execution']['exit_code'])
                );
            }

            $this->injectConnection($definition, $consumer['connection']);
            if ($this->collectorEnabled) {
                $this->injectTraceableChannel($definition, $consumerName, $consumer['connection']);
            }

            $this->container->setDefinition($alias, $definition);

            if ($consumer['logging']) {
                $this->injectLogger($alias);
            }
        }
    }

    /**
     * Symfony 2 converts '-' to '_' when defined in the configuration. This leads to problems when using x-ha-policy
     * parameter. So we revert the change for right configurations.
     *
     * @param array $config
     *
     * @return array
     */
    private function normalizeArgumentKeys(array $config)
    {
        if (isset($config['arguments'])) {
            $arguments = $config['arguments'];
            // support for old configuration
            if (is_string($arguments)) {
                $arguments = $this->argumentsStringAsArray($arguments);
            }

            $newArguments = [];
            foreach ($arguments as $key => $value) {
                if (strstr($key, '_')) {
                    $key = str_replace('_', '-', $key);
                }
                $newArguments[$key] = $value;
            }
            $config['arguments'] = $newArguments;
        }
        return $config;
    }

    /**
     * Support for arguments provided as string. Support for old configuration files.
     *
     * @deprecated
     * @param string $arguments
     * @return array
     */
    private function argumentsStringAsArray($arguments)
    {
        $argumentsArray = [];

        $argumentPairs = explode(',', $arguments);
        foreach ($argumentPairs as $argument) {
            $argumentPair = explode(':', $argument);
            $type = 'S';
            if (isset($argumentPair[2])) {
                $type = $argumentPair[2];
            }
            $argumentsArray[$argumentPair[0]] = [$type, $argumentPair[1]];
        }

        return $argumentsArray;
    }

    protected function injectTraceableChannel(Definition $definition, $name, $connectionName)
    {
        $id = sprintf('old_sound_rabbit_mq.channel.%s', $name);
        $traceableChannel = new Definition('%old_sound_rabbit_mq.traceable.channel.class%');
        $traceableChannel
            ->setPublic(false)
            ->addTag('old_sound_rabbit_mq.traceable_channel');
        $this->injectConnection($traceableChannel, $connectionName);

        $this->container->setDefinition($id, $traceableChannel);

        $this->channelIds[] = $id;
        $definition->addArgument(new Reference($id));
    }

    protected function injectConnection(Definition $definition, $connectionName)
    {
        $definition->addArgument(new Reference(sprintf('old_sound_rabbit_mq.connection.%s', $connectionName)));
    }

    public function getAlias()
    {
        return 'old_sound_rabbit_mq';
    }

    /**
     * TODO!
     * Add proper dequeuer aware call
     *
     * @param string $callback
     * @param string $name
     */
    protected function addDequeuerAwareCall($callback, $name)
    {
        if (!$this->container->has($callback)) {
            return;
        }

        $callbackDefinition = $this->container->findDefinition($callback);
        $refClass = new \ReflectionClass($callbackDefinition->getClass());
        if ($refClass->implementsInterface('OldSound\RabbitMqBundle\RabbitMq\DequeuerAwareInterface')) {
            $callbackDefinition->addMethodCall('setDequeuer', [new Reference($name)]);
        }
    }

    private function injectLogger(string $definitionAlias)
    {
        $definition = $this->container->getDefinition($definitionAlias);
        $definition->addTag('monolog.logger', [
            'channel' => 'phpamqplib'
        ]);
        $loggerAlias = $definitionAlias . '.loggeer';
        $this->container->setAlias($loggerAlias, 'logger');
        $definition->addMethodCall('setLogger', [new Reference($loggerAlias, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]);
    }

    /**
     * Get default AMQP exchange options
     * TODO use
     * @return array
     */
    protected function getDefaultExchangeOptions()
    {
        return array(
            'name' => '',
            'type' => 'direct',
            'passive' => true,
            'declare' => false
        );
    }

    /**
     * Get default AMQP queue options
     * TODO use
     * @return array
     */
    protected function getDefaultQueueOptions()
    {
        return array(
            'name' => '',
            'declare' => false
        );
    }
}
