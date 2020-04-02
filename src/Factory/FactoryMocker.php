<?php

/**
 * @copyright Visma Digital Commerce AS 2020
 * @license   MIT
 * @author    Marcus Pettersen Irgens <marcus.irgens@visma.com>
 */

declare(strict_types=1);

namespace Visma\Magento2Psalm\Factory;

class FactoryMocker
{
    /**
     * @var \Psalm\Plugin\RegistrationInterface
     */
    protected $psalm;

    /**
     * @var resource[]
     */
    private static $tmpHandlers = [];

    /**
     * @var string
     */
    private $template = <<<'TEMPLATE'
<?php

namespace {{namespace}};

class {{class_name}} {
    public function create(array $params = []): {{return_class}} 
    {
        // Stubbed file    
    }
}

TEMPLATE;

    public function __construct(\Psalm\Plugin\RegistrationInterface $psalm)
    {
        $this->psalm = $psalm;
    }

    /**
     * @param $className
     * @return void
     */
    public function createMockFactory(string $className): void
    {
        $namespace = static::getNamespace($className);
        $bareClassName = static::getBareClassName($className);
        $factory = $bareClassName . "Factory";

        $contents = strtr(
            $this->template,
            [
                "{{namespace}}" => $namespace,
                "{{class_name}}" => $factory,
                "{{return_class}}" => '\\' . $className,
            ]
        );

        /** @see https://www.php.net/manual/en/function.tmpfile.php#122678 */
        $file = tmpfile();
        $md = stream_get_meta_data($file);
        $tempname = $md['uri'];
        static::$tmpHandlers[] = $file;

        static::createClassFile($file, $contents);

        if (!file_exists($tempname)) {
            throw new \Exception("Could not create temp file");
        }

        require_once $tempname;
    }

    /**
     * Registers an autoloader that generates mocked factories
     */
    public function registerAutoloader(): void
    {
        spl_autoload_register(function(string $class_name): void {
            if (!strpos($class_name, "Factory")) {
                return;
            }
            $len = strlen($class_name);
            $facLen = strlen("Factory");

            if (substr($class_name, $len - $facLen) !== "Factory") {
                return;
            }

            // Check if this is a namespace that Magento will create factories for
            $namespaces = static::getModuleNamespaces();
            $validNamespace = false;
            foreach ($namespaces as $namespace) {
                if (strpos($class_name, $namespace) === 0) {
                    $validNamespace = true;
                    break;
                }
            }

            if (!$validNamespace) {
                return;
            }

            // Get the class's base name
            $base = substr($class_name, 0, $len - $facLen);

            if (!class_exists($base)) {
                return;
            }

            $this->createMockFactory($base);
        });
    }

    /**
     * @return string[]
     */
    public static function getModuleNamespaces(): array
    {
        $registry = new \Magento\Framework\Component\ComponentRegistrar();
        $modules = $registry->getPaths(\Magento\Framework\Component\ComponentRegistrar::MODULE);

        $namespaces = [];

        foreach (array_keys($modules) as $module) {
            $parts = explode("_", $module);
            $namespaces[] = implode('\\', $parts);
        }

        return $namespaces;
    }

    /**
     * @param string $className
     * @return string
     */
    private static function getNamespace(string $className): string
    {
        $parts = explode('\\', $className);
        $parts = array_slice($parts, 0, count($parts) - 1);
        return implode('\\', $parts);
    }

    /**
     * @param string $className
     * @return string
     */
    private static function getBareClassName(string $className): string
    {
        $parts = explode('\\', $className);
        $name = array_pop($parts);
        return $name;
    }

    /**
     * @param resource $fh
     * @param string $contents
     */
    private static function createClassFile($fh, string $contents): void
    {
        fwrite($fh, $contents);
    }
}
