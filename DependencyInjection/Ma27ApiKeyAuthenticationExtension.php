<?php

namespace Ma27\ApiKeyAuthenticationBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class Ma27ApiKeyAuthenticationExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $processor = new Processor();
        $configuration = new Configuration();
        $config = $processor->processConfiguration($configuration, $configs);

        $container->setParameter('ma27_api_key_authentication.model_name', $config['user']['model_name']);
        $fieldValues = array(
            $config['user']['properties']['password']['property'],
            $config['user']['properties']['username'] ?: '',
            $config['user']['properties']['email'] ?: '',
            $config['user']['properties']['apiKey'],
        );

        if (count(array_unique($fieldValues)) < 4) {
            $valueCount = array_filter(
                array_count_values($fieldValues),
                function ($count) {
                    return $count > 1;
                }
            );

            throw new InvalidConfigurationException(
                sprintf(
                    'The user model properties must be unique! Duplicated items found: %s',
                    implode(', ', array_keys($valueCount))
                )
            );
        }

        foreach (array('username', 'email', 'apiKey') as $authProperty) {
            $container->setParameter(
                sprintf('ma27_api_key_authentication.property.%s', $authProperty),
                $config['user']['properties'][$authProperty]
            );
        }

        $container->setParameter('ma27_api_key_authentication.object_manager', $config['user']['object_manager']);

        $container->setParameter(
            'ma27_api_key_authentication.property.apiKeyLength',
            intval(floor($config['user']['api_key_length'] / 2))
        );

        $passwordConfig = $config['user']['properties']['password'];
        $container->setParameter('ma27_api_key_authentication.property.password', $passwordConfig['property']);

        $strategyArguments = array();
        switch ($passwordConfig['strategy']) {
            case 'php55':
                $className = 'Ma27\\ApiKeyAuthenticationBundle\\Model\\Password\\PhpPasswordHasher';

                break;
            case 'crypt':
                $className = 'Ma27\\ApiKeyAuthenticationBundle\\Model\\Password\\CryptPasswordHasher';

                break;
            case 'sha512':
                $className = 'Ma27\\ApiKeyAuthenticationBundle\\Model\\Password\\Sha512PasswordHasher';

                break;
            case 'phpass':
                $className = 'Ma27\\ApiKeyAuthenticationBundle\\Model\\Password\\PHPassHasher';
                $strategyArguments[] = $config['user']['properties']['password']['phpass_iteration_length'];

                break;
            default:
                throw new InvalidConfigurationException('Cannot create password config!');
        }

        $container->setDefinition('ma27_api_key_authentication.password.strategy', new Definition($className, $strategyArguments));

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        foreach (array('security_key', 'authentication', 'security') as $file) {
            $loader->load(sprintf('%s.yml', $file));
        }

        if ($this->isConfigEnabled($container, $config['api_key_purge'])) {
            $container->setParameter(
                'ma27_api_key_authentication.last_activation_parameter',
                $config['api_key_purge']['last_active_property']
            );

            $loader->load('session_cleanup.yml');

            if ($config['api_key_purge']['log_state']) {
                if (!$container->hasDefinition('logger')) {
                    // set empty logger
                    // unless logger isn't currently registered
                    $container->setDefinition('logger', new Definition());
                }

                $definition = $container->getDefinition('ma27_api_key_authentication.cleanup_command');
                $definition->replaceArgument(5, $container->getDefinition('logger'));
            }
        }

        $semanticServiceReplacements = array_filter($config['services']);
        if (!empty($semanticServiceReplacements)) {
            $serviceConfig = array(
                'auth_handler'    => 'ma27_api_key_authentication.auth_handler',
                'key_factory'     => 'ma27_api_key_authentication.key_factory',
                'password_hasher' => 'ma27_api_key_authentication.password.strategy',
            );

            foreach ($serviceConfig as $configIndex => $replaceableServiceId) {
                if (!isset($semanticServiceReplacements[$configIndex])) {
                    continue;
                }

                if (null === $serviceId = $semanticServiceReplacements[$configIndex]) {
                    continue;
                }

                $container->removeDefinition($replaceableServiceId);
                $container->setAlias($replaceableServiceId, new Alias($serviceId));
            }
        }
    }
}
