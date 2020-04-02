<?php

/**
 * @copyright Visma Digital Commerce AS 2020
 * @license   Proprietary
 * @author    Marcus Pettersen Irgens <marcus.irgens@visma.com>
 */

declare(strict_types=1);

namespace Visma\Magento2Psalm;

use PHPUnit\Framework\TestCase;

class PluginTest extends TestCase
{
    public function testRegistration()
    {
        $registraion = $this->createMock(\Psalm\Plugin\RegistrationInterface::class);

        $registraion->expects($this->atLeastOnce())->method("addStubFile");
        // $registraion->expects($this->atLeastOnce())->method("registerHooksFromClass");

        $plugin = new \Visma\Magento2Psalm\Plugin();
        $plugin($registraion);
    }
}
