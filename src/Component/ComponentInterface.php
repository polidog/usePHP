<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Component;

use Polidog\UsePhp\Runtime\Element;

/**
 * Interface that all usePHP components must implement.
 */
interface ComponentInterface
{
    /**
     * Render the component and return an Element tree.
     */
    public function render(): Element;
}
