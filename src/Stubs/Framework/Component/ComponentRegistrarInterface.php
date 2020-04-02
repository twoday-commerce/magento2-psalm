<?php

declare(strict_types=1);

namespace Magento\Framework\Component;

interface ComponentRegistrarInterface {
    /**
     * @param string $type
     * @psalm-return array<string, string>
     */
    public function getPaths($type);
}
