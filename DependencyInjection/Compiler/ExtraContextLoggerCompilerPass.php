<?php

namespace OldSound\RabbitMqBundle\DependencyInjection\Compiler;

use OldSound\RabbitMqBundle\Logger\ExtraContextLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class ExtraContextLoggerCompilerPass implements CompilerPassInterface
{
    private $tags = [
        'old_sound_rabbit_mq.producer',
        'old_sound_rabbit_mq.consumer',
    ];

    public function process(ContainerBuilder $container)
    {
        $taggedProducers = $container->findTaggedServiceIds('old_sound_rabbit_mq.producer');
        $taggedConsumers = $container->findTaggedServiceIds('old_sound_rabbit_mq.consumer');

        foreach ($taggedProducers as $id => $tags) {
            $producerName = $tags[0]['name'];
            $originLogger = $id . '.logger';

            if ($container->hasDefinition($originLogger)) {
                $consumerDef = $container->getDefinition($id);
                $consumerDef->removeMethodCall('setLogger');
                $loggerDef = new Definition(ExtraContextLogger::class, [new Reference($originLogger), ['producer' => $producerName]]);
                $consumerDef->addMethodCall('setLogger', [$loggerDef]);
            }
        }

        foreach ($taggedConsumers as $id => $tags) {
            $consumerName = $tags[0]['name'];
            $originLogger = $id . '.logger';

            if ($container->hasDefinition($originLogger)) {
                $consumerDef = $container->getDefinition($id);
                $consumerDef->removeMethodCall('setLogger');
                $loggerDef = new Definition(ExtraContextLogger::class, [new Reference($originLogger), ['consumer' => $consumerName]]);
                $consumerDef->addMethodCall('setLogger', [$loggerDef]);
            }
        }
    }
}
