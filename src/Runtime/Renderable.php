<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

/**
 * Interface for objects that can be rendered as an Element.
 *
 * Implement this interface to allow your component to be used
 * directly in children arrays without calling ->render() explicitly.
 *
 * Example:
 *   class Counter implements Renderable {
 *       public function render(): Element {
 *           return H::div(children: 'Count: 0');
 *       }
 *   }
 *
 *   // Usage: No need to call ->render()
 *   H::div(children: [new Counter()])
 */
interface Renderable
{
    public function render(): Element;
}
