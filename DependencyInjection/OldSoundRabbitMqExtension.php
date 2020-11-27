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

    private $channelIds = array();
    private $groups = array();

    private $config = array();

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
            $declarationRegistryDef->addMethodCall('addBinding', [$binding]);
        };

        $this->loadProducers();
        $this->loadConsumers();
        //$this->loadRpcClients();
        //$this->loadRpcServers();

        if ($this->collectorEnabled && $this->channelIds) {
            $channels = array();
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

            $this->loadBindings($exchange['bindings'], $exchange['name'], null);
            foreach($exchange['bindings'] as $binding) {
                //$this->loadBinding($binding, $exchange['name'], $binding['destination'], !!$binding['destination_is_exchange']);
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
        return array_map(function ($queue) use ($queues) {
            $queue['name'] = array_search($queue, $queues, true);
            $queueDeclaration = new Definition(QueueDeclaration::class);
            $queueDeclaration->setProperties($queue);

            $this->loadBindings($queue['bindings'], null, $queue['name'], false);

            return $queueDeclaration;
        }, $queues);
    }

    protected function loadBinding($binding, string $exchange = null, string $destination = null, bool $destinationIsExchange = null): array
    {
        $routingKeys = $binding['routing_keys'] ? $binding['routing_keys'] : [$binding['routing_key']];

        $definitions = [];
        foreach ($routingKeys as $routingKey)
        {
            $definition = new Definition(BindingDeclaration::class);
            $definition->addTag('old_sound_rabbit_mq.binding');
            $definition->setProperties([
                'exchange' => $exchange ? $exchange : $binding['exchange'],
                'destinationIsExchange' => isset($destinationIsExchange) ? $destinationIsExchange : $binding['destination_is_exchange'],
                'destination' => $destination ? $destination : $binding['destination'],
                'routingKey' => $routingKey,
                // TODO 'arguments' => $binding['arguments'],
                //'nowait' => $binding['nowait'],
            ]);
            $definitions[] = $definition;
        }

        return $definitions;
    }

    protected function loadBindings($bindings, string $exchange = null, string $destination = null, bool $destinationIsExchange = null): array
    {
        $definitions = [];
        foreach ($bindings as $binding) {
            $this->loadBinding($binding, $exchange, $destination, $destinationIsExchange);
        }

        return $definitions;
    }

    protected function loadConnections()
    {
        foreach ($this->config['connections'] as $key => $connection) {
            $connectionSuffix = $connection['use_socket'] ? 'socket_connection.class' : 'connection.class';
            $classParam =
                $connection['lazy']
                    ? '%old_sound_rabbit_mq.lazy.'.$connectionSuffix.'%'
                    : '%old_sound_rabbit_mq.'.$connectionSuffix.'%';

            $definition = new Definition('%old_sound_rabbit_mq.connection_factory.class%', [
                $classParam, $connection,
            ]);
            if (isset($connection['connection_parameters_provider'])) {
                $definition->addArgument(new Reference($connection['connection_parameters_provider']));
                unset($connection['connection_parameters_provider']);
            }
            $definition->setPublic(false);
            $factoryName = sprintf('old_sound_rabbit_mq.connection_factory.%s', $key);
            $this->container->setDefinition($factoryName, $definition);

            $definition = new Definition($classParam);
            if (method_exists($definition, 'setFactory')) {
                // to be inlined in services.xml when dependency on Symfony DependencyInjection is bumped to 2.6
                $definition->setFactory([new Reference($factoryName), 'createConnection']);
            } else {
                // to be removed when dependency on Symfony DependencyInjection is bumped to 2.6
                $definition->setFactoryService($factoryName);
                $definition->setFactoryMethod('createConnection');
            }
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
        if ($this->config['sandbox'] == false) {
            foreach ($this->config['producers'] as $key => $producer) {
                $definition = new Definition($producer['class']);
                $definition->setPublic(true);
                $definition->addTag('old_sound_rabbit_mq.base_amqp');
                $definition->addTag('old_sound_rabbit_mq.producer');
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

                //if (!$producer['auto_setup_fabric']) {
                //    $definition->addMethodCall('disableAutoSetupFabric');
                //}

                if ($producer['enable_logger']) {
                    $this->injectLogger($definition);
                }

                $producerServiceName = sprintf('old_sound_rabbit_mq.%s_producer', $key);

                $this->container->setDefinition($producerServiceName, $definition);
                if (null !== $producer['service_alias']) {
                    $this->container->setAlias($producer['service_alias'], $producerServiceName);
                }
            }
        } else {
            foreach ($this->config['producers'] as $key => $producer) {
                $definition = new Definition('%old_sound_rabbit_mq.fallback.class%');
                $this->container->setDefinition(sprintf('old_sound_rabbit_mq.%s_producer', $key), $definition);
            }
        }
    }

    private function createChannelReference($connectionName): Reference
    {
        return new Reference(sprintf('old_sound_rabbit_mq.channel.%s', $connectionName));
    }

    protected function loadConsumers()
    {
        $simpleExecuteCallbackStrategyAlias = 'old_sound_rabbit_mq.execute_callback_strategy.simple';
        $this->container->setDefinition($simpleExecuteCallbackStrategyAlias, new Definition(SimpleExecuteCallbackStrategy::class));

        $hasSerializer = true;//$this->container->has('serializer');

        foreach ($this->config['consumers'] as $key => $consumer) {
            $alias = sprintf('old_sound_rabbit_mq.%s_consumer', $key);
            $serializerAlias = sprintf('old_sound_rabbit_mq.%s_consumer.serializer', $key);// TODO

            $connectionName = isset($consumer['connection']) ? $consumer['connection'] : 'default';

            $definition = new Definition('%old_sound_rabbit_mq.consumer.class%', [
                $key,
                $this->createChannelReference($connectionName)
            ]);
            $definition->setPublic(true);
            //dump($hasSerializer);
            if ($hasSerializer) {
                $this->container->setAlias($serializerAlias, SerializerInterface::class);
                $definition->addMethodCall('setSerializer', [new Reference($serializerAlias)]);
            }
            $definition->addTag('old_sound_rabbit_mq.consumer');
            foreach($consumer['consumeQueues'] as $index => $consumeQueue) {
                $queueConsumingDef = new Definition(QueueConsuming::class);
                $queueConsumingDef->setProperties([
                    'queueName' => $consumeQueue['queue'],
                    'callback' => new Reference($consumeQueue['callback']),
                    //'qosPrefetchSize' => $consumeQueue['qos_prefetch_size'],
                    'qosPrefetchCount' => $consumeQueue['qos_prefetch_count'],
                    //'consumerTag' => $consumeQueue['consumer_tag'],
                    //'noLocal' => $consumeQueue['no_local'],
                ]);

                $executeCallbackStrategyRef = isset($consumeQueue['batch_count']) ?
                    new Definition(BatchExecuteCallbackStrategy::class, [$consumeQueue['batch_count']]) :
                    new Reference($simpleExecuteCallbackStrategyAlias);

                $definition->addMethodCall('consumeQueue', [
                    $queueConsumingDef,
                    $executeCallbackStrategyRef
                ]);
            }

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
            if (!$consumer['auto_setup_fabric']) {
                $definition->addMethodCall('disableAutoSetupFabric');
            }

            $this->injectConnection($definition, $consumer['connection']);
            if ($this->collectorEnabled) {
                $this->injectTraceableChannel($definition, $key, $consumer['connection']);
            }

            if ($consumer['enable_logger']) {
                $this->injectLogger($definition);
            }

            $this->container->setDefinition($alias, $definition);
            //TODO $this->addDequeuerAwareCall($consumer['callback'], $name);
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

            $newArguments = array();
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
        $argumentsArray = array();

        $argumentPairs = explode(',', $arguments);
        foreach ($argumentPairs as $argument) {
            $argumentPair = explode(':', $argument);
            $type = 'S';
            if (isset($argumentPair[2])) {
                $type = $argumentPair[2];
            }
            $argumentsArray[$argumentPair[0]] = array($type, $argumentPair[1]);
        }

        return $argumentsArray;
    }

    protected function loadRpcClients()
    {
        foreach ($this->config['rpc_clients'] as $key => $client) {
            $definition = new Definition('%old_sound_rabbit_mq.rpc_client.class%');
            $definition->setLazy($client['lazy']);
            $definition
                ->addTag('old_sound_rabbit_mq.rpc_client')
                ->addMethodCall('initClient', array($client['expect_serialized_response']));
            $this->injectConnection($definition, $client['connection']);
            if ($this->collectorEnabled) {
                $this->injectTraceableChannel($definition, $key, $client['connection']);
            }
            if (array_key_exists('unserializer', $client)) {
                $definition->addMethodCall('setUnserializer', array($client['unserializer']));
            }
            if (array_key_exists('direct_reply_to', $client)) {
                $definition->addMethodCall('setDirectReplyTo', array($client['direct_reply_to']));
            }
            $definition->setPublic(true);

            $this->container->setDefinition(sprintf('old_sound_rabbit_mq.%s_rpc', $key), $definition);
        }
    }

    protected function loadRpcServers()
    {
        foreach ($this->config['rpc_servers'] as $key => $server) {
            $definition = new Definition('%old_sound_rabbit_mq.rpc_server.class%');
            $definition
                ->setPublic(true)
                ->addTag('old_sound_rabbit_mq.base_amqp')
                ->addTag('old_sound_rabbit_mq.rpc_server')
                ->addMethodCall('initServer', array($key))
                ->addMethodCall('setCallback', array(array(new Reference($server['callback']), 'execute')));
            $this->injectConnection($definition, $server['connection']);
            if ($this->collectorEnabled) {
                $this->injectTraceableChannel($definition, $key, $server['connection']);
            }
            if (array_key_exists('qos_options', $server)) {
                $definition->addMethodCall('setQosOptions', array(
                    $server['qos_options']['prefetch_size'],
                    $server['qos_options']['prefetch_count'],
                    $server['qos_options']['global']
                ));
            }
            if (array_key_exists('exchange_options', $server)) {
                $definition->addMethodCall('setExchangeOptions', array($server['exchange_options']));
            }
            if (array_key_exists('queue_options', $server)) {
                $definition->addMethodCall('setQueueOptions', array($server['queue_options']));
            }
            if (array_key_exists('serializer', $server)) {
                $definition->addMethodCall('setSerializer', array($server['serializer']));
            }
            $this->container->setDefinition(sprintf('old_sound_rabbit_mq.%s_server', $key), $definition);
        }
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
            $callbackDefinition->addMethodCall('setDequeuer', array(new Reference($name)));
        }
    }

    private function injectLogger(Definition $definition)
    {
        $definition->addTag('monolog.logger', array(
            'channel' => 'phpamqplib'
        ));
        $definition->addMethodCall('setLogger', array(new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE)));
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
