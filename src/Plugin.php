<?php

/**
 * @copyright Visma Digital Commerce AS 2020
 * @license   MIT
 * @author    Marcus Pettersen Irgens <marcus.irgens@visma.com>
 */

declare(strict_types=1);

namespace Visma\Magento2Psalm;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;
use SimpleXMLElement;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use Visma\Magento2Psalm\Factory\FactoryMocker;

/**
 * Class Plugin
 */
class Plugin implements PluginEntryPointInterface
{
    public function __invoke(RegistrationInterface $psalm, ?SimpleXMLElement $config = null): void
    {
        $mocker = new FactoryMocker($psalm);
        $mocker->registerAutoloader();

        $this->loadStubs($psalm);
    }

    private function loadStubs(RegistrationInterface $psalm): void
    {
        $stubs = dirname(__FILE__, 2) . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "Stubs";

        // @see https://www.php.net/manual/en/class.recursivedirectoryiterator.php#97228

        $stubsDir = new RecursiveDirectoryIterator($stubs);
        $iterator = new RecursiveIteratorIterator($stubsDir);
        $filter = new RegexIterator($iterator, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);

        if (!is_iterable($filter)) {
            throw new \Exception("Could not iterate over stub    directory");
        }
        /** @psalm-var mixed $match */
        foreach ($filter as $match) {
            if (is_array($match) && array_key_exists(0, $match)) {
                if (is_string($match[0]) && file_exists($match[0])) {
                    $psalm->addStubFile($match[0]);
                }
            }
        }
    }
}
