<?php

declare(strict_types=1);

namespace stekycz\Cronner\DI;

use Nette\Configurator;
use Nette\DI\Compiler;
use Nette\DI\CompilerExtension;
use Nette\DI\ContainerBuilder;
use Nette\DI\Extensions\InjectExtension;
use Nette\DI\Helpers;
use Nette\DI\ServiceDefinition;
use Nette\DI\Statement;
use Nette\PhpGenerator\ClassType;
use Nette\Utils\Json;
use Nette\Utils\Validators;
use Bileto\CriticalSection\CriticalSection;
use Bileto\CriticalSection\Driver\FileDriver;
use Bileto\CriticalSection\Driver\IDriver;
use stekycz\Cronner\Bar\Tasks;
use stekycz\Cronner\Cronner;
use stekycz\Cronner\ITimestampStorage;
use stekycz\Cronner\TimestampStorage\FileStorage;

class CronnerExtension extends CompilerExtension
{

	const TASKS_TAG = 'cronner.tasks';

	const DEFAULT_STORAGE_CLASS = FileStorage::class;
	const DEFAULT_STORAGE_DIRECTORY = '%tempDir%/cronner';

	/**
	 * @var array
	 */
	public $defaults = [
		'timestampStorage' => NULL,
		'maxExecutionTime' => NULL,
		'criticalSectionTempDir' => "%tempDir%/critical-section",
		'criticalSectionDriver' => NULL,
		'tasks' => [],
		'bar' => '%debugMode%',
        'cronLogService' => NULL
	];

	private ?string $cronLogService = null;

	public function loadConfiguration()
	{
		$container = $this->getContainerBuilder();

		$config = (array)$this->getConfig() + $this->defaults;
		Validators::assert($config['timestampStorage'], 'string|object|null', 'Timestamp storage definition');
		Validators::assert($config['maxExecutionTime'], 'integer|null', 'Script max execution time');
		Validators::assert($config['criticalSectionTempDir'], 'string|null', 'Critical section files directory path (for critical section files driver only)');
		Validators::assert($config['criticalSectionDriver'], 'string|object|null', 'Critical section driver definition');

		$storage = $this->createServiceByConfig(
			$container,
			$this->prefix('timestampStorage'),
			$config['timestampStorage'],
			ITimestampStorage::class,
			self::DEFAULT_STORAGE_CLASS,
			[
				self::DEFAULT_STORAGE_DIRECTORY,
			]
		);

		$criticalSectionDriver = $this->createServiceByConfig(
			$container,
			$this->prefix('criticalSectionDriver'),
			$config['criticalSectionDriver'],
			IDriver::class,
			FileDriver::class,
			[
				$config['criticalSectionTempDir'],
			]
		);

		$criticalSection = $container->addDefinition($this->prefix("criticalSection"))
			 ->setFactory(CriticalSection::class, [
			 	$criticalSectionDriver,
			 ])
			 ->setAutowired(FALSE)
			 ->addTag(InjectExtension::TAG_INJECT, false);

		$runner = $container->addDefinition($this->prefix('runner'))
			->setFactory(Cronner::class, [
				$storage,
				$criticalSection,
				$config['maxExecutionTime'],
				array_key_exists('debugMode', $config) ? !$config['debugMode'] : TRUE,
			]);

		Validators::assert($config['tasks'], 'array');
		foreach ($config['tasks'] as $task) {
			$def = $container->addDefinition($this->prefix('task.' . md5(is_string($task) ? $task : sprintf('%s-%s', $task->getEntity(), Json::encode($task)))));
			list($def->factory) = Compiler::filterArguments([
				is_string($task) ? new Statement($task) : $task,
			]);

			if (class_exists($def->factory->entity)) {
				$def->setFactory($def->factory->entity);
			}

			$def->setAutowired(FALSE);
			$def->setInject(FALSE);
			$def->addTag(self::TASKS_TAG);
		}

		if ($config['bar'] && class_exists('Tracy\Bar')) {
			$container->addDefinition($this->prefix('bar'))
				->setFactory(Tasks::class, [
					$this->prefix('@runner'),
					$this->prefix('@timestampStorage'),
				]);
		}

		$this->cronLogService = $config['cronLogService'];
	}

	public function beforeCompile()
	{
		$builder = $this->getContainerBuilder();

		$runner = $builder->getDefinition($this->prefix('runner'));
		foreach (array_keys($builder->findByTag(self::TASKS_TAG)) as $serviceName) {
			$runner->addSetup('addTasks', ['@' . $serviceName]);
		}

		if ($this->cronLogService) {
		    if (!class_exists($this->cronLogService)) {
		        throw new \Exception("Class \"{$this->cronLogService}\" for cron logs does not exist.");
            }

		    if (!method_exists($this->cronLogService, 'logStart')) {
                throw new \Exception("Cron log service needs to have a \"logStart()\" method.");
            }

            if (!method_exists($this->cronLogService, 'logEnd')) {
                throw new \Exception("Cron log service needs to have a \"logEnd()\" method.");
            }

            $runner->addSetup('addCronLogService', ['@' . $this->cronLogService]);
        }
    }

	public function afterCompile(ClassType $class)
	{
		$builder = $this->getContainerBuilder();
		$init = $class->getMethod('initialize');

		if ($builder->hasDefinition($this->prefix('bar'))) {
			$init->addBody('$this->getByType(?)->addPanel($this->getService(?));', [
				'Tracy\Bar',
				$this->prefix('bar'),
			]);
		}
	}

	public static function register(Configurator $configurator)
	{
		$configurator->onCompile[] = function (Configurator $config, Compiler $compiler) {
			$compiler->addExtension('cronner', new CronnerExtension());
		};
	}

	private function createServiceByConfig(
		ContainerBuilder $container,
		string $serviceName,
		$config,
		string $fallbackType,
		string $fallbackClass,
		array $fallbackArguments
	) : ServiceDefinition
	{
		if (is_string($config) && $container->getServiceName($config)) {
			$definition = $container->addDefinition($serviceName)
				->setFactory($config);
		} elseif ($config instanceof Statement) {
			$definition = $container->addDefinition($serviceName)
				->setFactory($config->entity, $config->arguments);
		} else {
			$foundServiceName = $container->getByType($fallbackType);
			if ($foundServiceName) {
				$definition = $container->addDefinition($serviceName)
					->setFactory('@' . $foundServiceName);
			} else {
				$definition = $container->addDefinition($serviceName)
					->setFactory($fallbackClass, Helpers::expand($fallbackArguments, $container->parameters));
			}
		}

		return $definition
			->setAutowired(FALSE)
			->addTag(InjectExtension::TAG_INJECT, false);
	}

}
