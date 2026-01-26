<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Http;

use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Renderer;

/**
 * Handles AJAX action requests from the client.
 */
class ActionHandler
{
    /** @var array<string, callable(): \Polidog\UsePhp\Runtime\Element> */
    private array $components = [];

    /**
     * Register a component.
     *
     * @param callable(): \Polidog\UsePhp\Runtime\Element $component
     */
    public function register(string $componentId, callable $component): self
    {
        $this->components[$componentId] = $component;
        return $this;
    }

    /**
     * Handle an incoming action request.
     *
     * @return array{html: string}|array{error: string}
     */
    public function handle(): array
    {
        $input = $this->getInput();

        if (!isset($input['componentId']) || !isset($input['action'])) {
            return ['error' => 'Missing componentId or action'];
        }

        $componentId = $input['componentId'];
        $actionData = $input['action'];

        if (!isset($this->components[$componentId])) {
            return ['error' => 'Component not found: ' . $componentId];
        }

        // Parse the action
        $action = Action::fromArray($actionData);

        // Get component state and apply the action
        $state = ComponentState::getInstance($componentId);

        if ($action->type === 'setState') {
            $index = $action->payload['index'] ?? 0;
            $value = $action->payload['value'] ?? null;
            $state->setState($index, $value);
        }

        // Re-render the component
        $renderer = new Renderer($componentId);
        $html = $renderer->rerender($this->components[$componentId]);

        return ['html' => $html];
    }

    /**
     * Handle the request and send JSON response.
     */
    public function handleAndRespond(): void
    {
        header('Content-Type: application/json');

        $result = $this->handle();

        echo json_encode($result, JSON_THROW_ON_ERROR);
    }

    /**
     * Check if the current request is an action request.
     */
    public function isActionRequest(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_SERVER['HTTP_X_USEPHP_ACTION']);
    }

    /**
     * Get input data from the request.
     *
     * @return array<string, mixed>
     */
    private function getInput(): array
    {
        $rawInput = file_get_contents('php://input');

        if ($rawInput === false || $rawInput === '') {
            return [];
        }

        try {
            return json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
    }
}
