<?php
/*********************************
	Zend Framework 2 Z-Ray Extension
	Version: 1.00
**********************************/

namespace ZF2Extension;

use Serializable,
	Traversable,
	Closure,
	Zend\Mvc\MvcEvent,
	Zend\Version\Version,
	Zend\ModuleManager\Feature\ConfigProviderInterface,
	ReflectionObject,
	ReflectionProperty,
	Zend\Stdlib\ArrayUtils;

class ZF2 {

	private $isConfigSaved = false;
	private $isModulesSaved = false;
	private $isEventSaved = false;
	private $isLatestVersionSaved = false;
	private $helpers = array();
	private $backtrace = null;

	public function storeTriggerExit($context, &$storage) {
		$mvcEvent = $context["functionArgs"][1];

		if ($mvcEvent instanceof MvcEvent) {
			$storage['events'][] = array(	'name' => $context["functionArgs"][0],
    										'target' => $this->getEventTarget($mvcEvent),
    										'file'   => $this->getEventTriggerFile(),
    										'line'   => $this->getEventTriggerLine(),
    										'memory' => $this->formatSizeUnits(memory_get_usage(true)),
    										'time' => $this->formatTime($context['durationInclusive']) . 'ms');
		}

		$this->collectVersionData($storage);
		$this->collectRequest($context["functionArgs"][0], $mvcEvent, $storage);
		$this->collectConfigurations($mvcEvent, $storage);
	}

	public function storeHelperExit($context, &$storage) {
	    $helperName = $context["functionArgs"][0]; // plugin  name

	    if (! array_key_exists($helperName, $this->helpers)) {
	        $this->helpers[$helperName]['count'] = 1;
	        if (is_object($context['returnValue'])) {

	            $reflect = new \ReflectionClass($context['returnValue']);

	            $properties = array();
    	        foreach ($reflect->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED | \ReflectionProperty::IS_PRIVATE) as $prop) {
    	            $prop->setAccessible(true);
    	            $value = $prop->getValue($context['returnValue']);
    	            if (is_object($value)) {
    	                $properties[$prop->getName()] = get_class($value);
    	            } else {
    	                $properties[$prop->getName()] = $value;
    	            }
    	        }

	           $this->helpers[$helperName]['properties'] = $properties;
	        }
	    } else {
	        $this->helpers[$helperName]['count']++;
	    }
	}

	public function storeApplicationExit($context, &$storage) {
	    $Zend_Mvc_Application = $context['this'];
	    $response = $Zend_Mvc_Application->getResponse();

	    //Zend\Http\PhpEnvironment\Response
	    $storage['response'][] = $response;

	    // store the kept helpers info
	    $storage['viewHelpers'][] = $this->helpers;
	}

	public function storeModulesInfoExit($context, &$storage) {
	    $event = $context["functionArgs"][0]; // event
	    $module = $event->getModule();
	    $moduleName = $event->getModuleName();

	    if (!$module instanceof ConfigProviderInterface && !is_callable(array($module, 'getConfig'))) {
	        return;
	    }

	    $config = $module->getConfig();
	    $config = $this->makeArraySerializable($config);

	    $moduleObject = new \ReflectionObject($module);
	    $location = $moduleObject->getFileName();

	    $storage['modules'][$moduleName] = array(  'location' => $location,
	                                               'config' => $config);
	}

	public function storeFormInfoExit($context, &$storage) {
	    $form = $context["functionArgs"][0]; // form object

	    // Needs to filter csrf elements to avoid Session serialization that leads to error
	    $elements = array();
	    foreach ($form->getElements() as $element) {
	        if (!$element instanceof \Zend\Form\Element\Csrf) {
	            $elements[] = $element;
	        } else {
	            $csrf = clone($element);
	            $csrfOptions = $element->getCsrfValidatorOptions();
                $csrfOptions = array_merge($csrfOptions, array('name' => $element->getName()));
                $csrf->setCsrfValidator(new \Zend\Validator\Csrf($csrfOptions));
	            $elements[] = $csrf;
	        }
	    }

    	$storage['forms'][get_class($form)] = array(   'label'      => $form->getLabel(),
                    	                               'elements'   => $elements,
                    	                               'attributes' => $form->getAttributes(),
                    	                               'messages'   => $form->getMessages());
	}

	////////////////////////////////////////////////////////////////
	//   PRIVATES
	////////////////////////////////////////////////////////////////

    private function collectVersionData(&$storage) {
        
		if (! class_exists('Zend\Version\Version') || $this->isLatestVersionSaved){
			return;
		}

		if(! function_exists('zend_shm_cache_store') || !zend_shm_cache_fetch('ZF2_version_isLatest')) {
		    $isLatest = Version::isLatest();
		    $latest   = Version::getLatest();
		    $isLatest = ($isLatest) ? 'yes' : 'no';

		    if(function_exists('zend_shm_cache_store')) {
		        zend_shm_cache_store('ZF2_version_isLatest', $isLatest);
		        zend_shm_cache_store('ZF2_version_latest', $latest);
		    }
		} else {
		    $isLatest = zend_shm_cache_fetch('ZF2_version_isLatest');
		    $latest = zend_shm_cache_fetch('ZF2_version_latest');
		}

		$latest = ($latest === null) ? 'N/A' : $latest;

		$storage['version'][] = array('version' => Version::VERSION, 'isLatest' => $isLatest,'latest' => $latest);
		$this->isLatestVersionSaved = true;
	}

	/**
	 * Returns the line number of the file from which the event was triggered.
	 *
	 * @return integer
	 */
	private function getEventTriggerFile() {
		$trace = debug_backtrace();
		$this->backtrace = array_splice($trace, 2);
		if (isset($this->backtrace[0]) && isset($this->backtrace[0]['file']) && file_exists($this->backtrace[0]['file'])) {
			return basename(dirname($this->backtrace[0]['file'])) . '/' . basename($this->backtrace[0]['file']);
		}
	}

	private function getEventTriggerLine() {
		if (!$this->backtrace) {
			$trace = debug_backtrace();
			$this->backtrace = array_splice($trace, 2);
		}
		if (isset($this->backtrace[0]) && isset($this->backtrace[0]['line'])) {
			return $this->backtrace[0]['line'];
		}
	}

	/**
	 * Returns either the class name of the target, or the target string
	 *
	 * @return string
	 */
	private function getEventTarget($event) {
		return (is_object($event->getTarget())) ? get_class($event->getTarget()) : (string) $event->getTarget();
	}

	private function collectRequest($event, $mvcEvent, &$storage) {
		if (!($mvcEvent instanceof MvcEvent) ||  $event != MvcEvent::EVENT_FINISH) {
			return;
		}

		$templates   = array();
		$match       = $mvcEvent->getRouteMatch();

		$templates[] = $mvcEvent->getViewModel()->getTemplate();
		if ($mvcEvent->getViewModel()->hasChildren()) {
			foreach ($mvcEvent->getViewModel()->getChildren() as $child) {
				$templates[] = $child->getTemplate();
			}
		}

		if (empty($templates)) {
			$templates[] = 'N/A';
		}

		$data = array(
				'method'     => $mvcEvent->getRequest()->getMethod(),
				'status'     => $mvcEvent->getResponse()->getStatusCode(),
				'route'      => ($match === null) ? 'N/A' : $match->getMatchedRouteName(),
				'action'     => ($match === null) ? 'N/A' : $match->getParam('action', 'N/A'),
				'controller' => ($match === null) ? 'N/A' : $match->getParam('controller', 'N/A'),
				'templates'  => $templates,
		);
		$storage['request'][] = $data;
	}

	private function collectConfigurations($mvcEvent, &$storage) {
		if (!($mvcEvent instanceof MvcEvent)) {
			return;
		}

		if ($this->isConfigSaved) {
			return;
		}

		if (! $application = $mvcEvent->getApplication()) {
			return;
		}

		$serviceLocator = $application->getServiceManager();

		if ($serviceLocator->has('Config')) {
    		 $storage['config'][] = $this->makeArraySerializable($serviceLocator->get('Config'));
		}

		if ($serviceLocator->has('ApplicationConfig')) {
		    $storage['applicationConfig'][] = $this->makeArraySerializable($serviceLocator->get('ApplicationConfig'));
		}

		$this->isConfigSaved = true;
	}

	private function collectConfig($config, &$storage) {
	    foreach ($config as $name => $data) {
	        $storage['config'][] = array( 'name' => $name, 'data' => $data);
	    }
	}

	/**
	 * Replaces the un-serializable items in an array with stubs
	 *
	 * @param array|\Traversable $data
	 *
	 * @return array
	 */
	private function makeArraySerializable($data) {
		$serializable = array();
		try {
			foreach (ArrayUtils::iteratorToArray($data) as $key => $value) {
					if ($value instanceof Traversable || is_array($value)) {
					$serializable[$key] = $this->makeArraySerializable($value);

						continue;
					}

					if ($value instanceof Closure) {
						$serializable[$key] = new ClosureStub();
						continue;
					}

					$serializable[$key] = $value;
			}
		} catch (\InvalidArgumentException $e) {
			return $serializable;
		}

		return $serializable;
	}

	private function reorderArray($config) {
	    $reorderedArray = array();
	    foreach ($config as $key => $value) {
	       if (!is_array($value) && !is_object($value)) {
	           unset($config[$key]);
	           $reorderedArray[$key] = $value;
	       }
	    }
	    return $reorderedArray + $config;
	}

	private function formatSizeUnits($bytes) {

		if ($bytes >= 1073741824) {
			$bytes = number_format ( $bytes / 1073741824, 2 ) . ' GB';
		} elseif ($bytes >= 1048576) {
			$bytes = number_format ( $bytes / 1048576, 2 ) . ' MB';
		} elseif ($bytes >= 1024) {
			$bytes = number_format ( $bytes / 1024, 2 ) . ' KB';
		} elseif ($bytes > 1) {
			$bytes = $bytes . ' bytes';
		} elseif ($bytes == 1) {
			$bytes = $bytes . ' byte';
		}
        else
        {
            $bytes = '0 bytes';
        }

        return $bytes;
	}

	private function formatTime($ms) {
		//$uSec = $input % 1000;
		$input = floor($ms / 1000);
		return $input;
	}

}

/**
 * Empty class that represents an {@see \Closure} object
 */
class ClosureStub {
}

$zf2Storage = new ZF2();

// Allocate ZRayExtension for namespace "zf2"
$zre = new \ZRayExtension("zf2");

$zre->setMetadata(array(
	'logo' => __DIR__ . DIRECTORY_SEPARATOR . 'logo.png',
));
$zre->setEnabledAfter('Zend\Mvc\Application::init');

$zre->traceFunction("Zend\EventManager\EventManager::triggerListeners",  function(){}, array($zf2Storage, 'storeTriggerExit'));
$zre->traceFunction("Zend\View\Renderer\PhpRenderer::plugin",  function(){}, array($zf2Storage, 'storeHelperExit'));
$zre->traceFunction("Zend\Mvc\Application::run",  function(){}, array($zf2Storage, 'storeApplicationExit'));
$zre->traceFunction("Zend\ModuleManager\Listener\ConfigListener::onLoadModule", function(){}, array($zf2Storage, 'storeModulesInfoExit'));
$zre->traceFunction("Zend\Form\View\Helper\Form::render", function(){}, array($zf2Storage, 'storeFormInfoExit'));
$zre->traceFunction("Zend\Form\View\Helper\Form::openTag", function(){}, array($zf2Storage, 'storeFormInfoExit'));
