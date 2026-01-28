<?php

declare(strict_types=1);

namespace Polidog\UsePhp;

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\ComponentInterface;
use Polidog\UsePhp\Component\ComponentRegistry;
use Polidog\UsePhp\Router\NullRouter;
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Router\RouteMatch;
use Polidog\UsePhp\Router\RouterInterface;
use Polidog\UsePhp\Router\SimpleRouter;
use Polidog\UsePhp\Router\SnapshotBehavior;
use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Runtime\Renderer;
use Polidog\UsePhp\Runtime\Snapshot;
use Polidog\UsePhp\Snapshot\SnapshotSerializer;
use Polidog\UsePhp\Snapshot\SnapshotVerificationException;
use Polidog\UsePhp\Storage\StorageType;

/**
 * Main application class for usePHP.
 * Minimal JS for partial updates, falls back to full page reload.
 */
final class UsePHP
{
    private static ?self $instance = null;

    private ComponentRegistry $registry;
    private ?SnapshotSerializer $snapshotSerializer = null;
    private ?RouterInterface $router = null;
    private ?RouteMatch $currentMatch = null;

    private function __construct()
    {
        $this->registry = new ComponentRegistry();
    }

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register a component class.
     *
     * @param class-string<ComponentInterface> $className
     */
    public static function register(string $className): self
    {
        $instance = self::getInstance();
        $instance->registry->register($className);
        return $instance;
    }

    /**
     * Render a component and return HTML.
     *
     * @param string $componentName The registered component name
     * @param string|null $key Optional explicit key for the component instance
     */
    public static function render(string $componentName, ?string $key = null): string
    {
        $instance = self::getInstance();
        return $instance->doRenderComponent($componentName, $key);
    }

    /**
     * Create a component Element (without rendering to HTML).
     *
     * Use this when you want to compose multiple components using H class,
     * then render the entire tree with renderElement().
     *
     * @param string $componentName The registered component name
     * @param string|null $key Optional explicit key for the component instance
     */
    public static function createElement(string $componentName, ?string $key = null): Element
    {
        $instance = self::getInstance();
        return $instance->doCreateElement($componentName, $key);
    }

    /**
     * Render an Element tree to HTML.
     *
     * Use this to render Element trees created with createElement() and H class.
     */
    public static function renderElement(Element $element): string
    {
        $instance = self::getInstance();
        return $instance->doRenderElement($element);
    }

    /**
     * Configure the snapshot serializer with a secret key.
     *
     * @param string $secretKey The secret key for snapshot verification
     */
    public static function setSnapshotSecret(string $secretKey): self
    {
        $instance = self::getInstance();
        $instance->snapshotSerializer = new SnapshotSerializer($secretKey);
        return $instance;
    }

    /**
     * Get the snapshot serializer.
     */
    public function getSnapshotSerializer(): SnapshotSerializer
    {
        if ($this->snapshotSerializer === null) {
            $this->snapshotSerializer = new SnapshotSerializer();
        }
        return $this->snapshotSerializer;
    }

    /**
     * Set a custom router.
     */
    public static function setRouter(RouterInterface $router): self
    {
        $instance = self::getInstance();
        $instance->router = $router;
        return $instance;
    }

    /**
     * Get the current router, creating a SimpleRouter if none set.
     */
    public static function getRouter(): RouterInterface
    {
        $instance = self::getInstance();
        if ($instance->router === null) {
            $instance->router = new SimpleRouter($instance->getSnapshotSerializer());
        }
        return $instance->router;
    }

    /**
     * Disable routing (use NullRouter).
     * Use this when integrating with frameworks like Laravel or Symfony.
     */
    public static function disableRouter(): self
    {
        $instance = self::getInstance();
        $instance->router = new NullRouter();
        return $instance;
    }

    /**
     * Get the current route match.
     */
    public static function getCurrentMatch(): ?RouteMatch
    {
        return self::getInstance()->currentMatch;
    }

    /**
     * Run the router and render the matched component.
     *
     * This is the main entry point for standalone usePHP applications.
     *
     * @param RequestContext|null $request Optional request context (defaults to fromGlobals)
     */
    public static function run(?RequestContext $request = null): void
    {
        $instance = self::getInstance();
        $request ??= RequestContext::fromGlobals();

        // Handle POST actions first
        if ($request->isPost() && isset($_POST['_usephp_action'])) {
            $html = $instance->doHandleAction();
            echo $html;
            return;
        }

        $router = self::getRouter();
        $match = $router->match($request);

        if ($match === null) {
            http_response_code(404);
            echo '404 Not Found';
            return;
        }

        $instance->currentMatch = $match;

        // Handle snapshot restoration for persistent/session behaviors
        $instance->handleSnapshotRestoration($request, $match);

        // Render the component
        $handler = $match->handler;
        $html = '';

        if (is_string($handler) && class_exists($handler)) {
            // Component class
            $html = self::render($handler);
        } elseif (is_callable($handler)) {
            // Callable handler
            $result = $handler($match->params, $request);
            if ($result instanceof Element) {
                $html = self::renderElement($result);
            } else {
                $html = (string) $result;
            }
        }

        echo $html;
    }

    /**
     * Handle snapshot restoration based on route behavior.
     */
    private function handleSnapshotRestoration(RequestContext $request, RouteMatch $match): void
    {
        $router = $this->router ?? new NullRouter();
        $snapshotData = $router->extractSnapshot($request);

        if ($snapshotData === null) {
            return;
        }

        switch ($match->snapshotBehavior) {
            case SnapshotBehavior::Persistent:
                // Restore snapshot from URL
                try {
                    $snapshot = $this->getSnapshotSerializer()->deserialize($snapshotData);
                    ComponentState::fromSnapshot($snapshot);
                } catch (SnapshotVerificationException $e) {
                    // Invalid snapshot, ignore
                }
                break;

            case SnapshotBehavior::Session:
                // Store snapshot in session for later use
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['_usephp_snapshot'] = $snapshotData;
                }
                break;

            case SnapshotBehavior::Shared:
                // Restore from session if in same group
                if ($match->sharedGroup !== null && session_status() === PHP_SESSION_ACTIVE) {
                    $sessionKey = '_usephp_shared_' . $match->sharedGroup;
                    if (isset($_SESSION[$sessionKey])) {
                        try {
                            $snapshot = $this->getSnapshotSerializer()->deserialize($_SESSION[$sessionKey]);
                            ComponentState::fromSnapshot($snapshot);
                        } catch (SnapshotVerificationException $e) {
                            // Invalid snapshot, ignore
                        }
                    }
                }
                break;

            case SnapshotBehavior::Isolated:
            default:
                // No restoration for isolated pages
                break;
        }
    }

    /**
     * Redirect to a named route with optional snapshot preservation.
     *
     * @param string $routeName The name of the route to redirect to
     * @param array<string, string> $params Route parameters
     * @param Snapshot|null $snapshot Optional snapshot to pass
     */
    public static function redirectTo(string $routeName, array $params = [], ?Snapshot $snapshot = null): never
    {
        $instance = self::getInstance();
        $router = self::getRouter();

        $url = $router->generate($routeName, $params);

        if ($snapshot !== null && $instance->currentMatch?->snapshotBehavior === SnapshotBehavior::Persistent) {
            $url = $router->createRedirectUrl($url, $snapshot);
        }

        header('Location: ' . $url, true, 303);
        exit;
    }

    /**
     * Handle a POST action and return the partial HTML.
     * Returns null if not a valid action request.
     */
    public static function handleAction(): ?string
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['_usephp_action'])) {
            return null;
        }

        $instance = self::getInstance();
        return $instance->doHandleAction();
    }

    /**
     * Handle form action submission.
     */
    private function doHandleAction(): string
    {
        $instanceId = $_POST['_usephp_component'] ?? null;
        $actionJson = $_POST['_usephp_action'] ?? null;
        $snapshotJson = $_POST['_usephp_snapshot'] ?? null;
        $isPartial = isset($_SERVER['HTTP_X_USEPHP_PARTIAL']);

        if ($instanceId === null || $actionJson === null) {
            http_response_code(400);
            return 'Invalid action request';
        }

        // Extract component name from instanceId (e.g., "Counter#0" => "Counter")
        $componentName = explode('#', $instanceId)[0];

        // Check if this is a registered class-based component
        $isRegisteredComponent = $this->registry->has($componentName);

        // For function components (not in registry), use session storage
        $storageType = $isRegisteredComponent
            ? $this->registry->getStorageType($componentName)
            : StorageType::Session;

        // Parse and execute the action
        try {
            $actionData = json_decode($actionJson, true, 512, JSON_THROW_ON_ERROR);
            $action = Action::fromArray($actionData);

            // Handle snapshot storage - restore state from snapshot
            if ($storageType === StorageType::Snapshot && $snapshotJson !== null) {
                $snapshot = $this->getSnapshotSerializer()->deserialize($snapshotJson);
                $state = ComponentState::fromSnapshot($snapshot);
            } else {
                // Use instanceId for state to match the correct component instance
                $state = ComponentState::getInstance($instanceId, $storageType);
            }

            if ($action->type === 'setState') {
                $index = $action->payload['index'] ?? 0;
                $value = $action->payload['value'] ?? null;
                $state->setState($index, $value);
            }
        } catch (\JsonException $e) {
            http_response_code(400);
            return 'Invalid action data';
        } catch (SnapshotVerificationException $e) {
            http_response_code(400);
            return 'Invalid snapshot';
        }

        // Partial update (AJAX) - return only component HTML
        if ($isPartial && $isRegisteredComponent) {
            return $this->doRenderComponentPartialWithInstanceId($instanceId, $componentName);
        }

        // Full page - PRG pattern with snapshot behavior handling
        $redirectUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';

        // Handle snapshot preservation based on route behavior
        if ($this->currentMatch !== null && $storageType === StorageType::Snapshot) {
            $snapshot = $state->createSnapshot();

            switch ($this->currentMatch->snapshotBehavior) {
                case SnapshotBehavior::Persistent:
                    // Pass snapshot in URL
                    $router = $this->router ?? new NullRouter();
                    $redirectUrl = $router->createRedirectUrl((string) $redirectUrl, $snapshot);
                    break;

                case SnapshotBehavior::Session:
                    // Store snapshot in session
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        $serialized = $this->getSnapshotSerializer()->serialize($snapshot);
                        $_SESSION['_usephp_snapshot'] = $serialized;
                    }
                    break;

                case SnapshotBehavior::Shared:
                    // Store in shared group session
                    if ($this->currentMatch->sharedGroup !== null && session_status() === PHP_SESSION_ACTIVE) {
                        $sessionKey = '_usephp_shared_' . $this->currentMatch->sharedGroup;
                        $serialized = $this->getSnapshotSerializer()->serialize($snapshot);
                        $_SESSION[$sessionKey] = $serialized;
                    }
                    break;

                case SnapshotBehavior::Isolated:
                default:
                    // No preservation for isolated pages
                    break;
            }
        }

        header('Location: ' . $redirectUrl, true, 303);
        exit;
    }

    /**
     * Create a component Element with wrapper.
     */
    private function doCreateElement(string $componentName, ?string $key = null): Element
    {
        $component = $this->registry->create($componentName);

        if ($component === null) {
            return new Element('div', [], []);
        }

        $instanceId = RenderContext::nextInstanceId($componentName, $key);
        $storageType = $this->registry->getStorageType($componentName);
        $state = ComponentState::getInstance($instanceId, $storageType);
        ComponentState::reset();

        if ($component instanceof BaseComponent) {
            $component->setComponentState($state);
        }

        // Get the element from component
        $innerElement = $component->render();

        // Build wrapper props
        $props = ['data-usephp' => $instanceId];

        // Add snapshot if using snapshot storage
        if ($storageType === StorageType::Snapshot) {
            $snapshot = $state->createSnapshot();
            $snapshotJson = $this->getSnapshotSerializer()->serialize($snapshot);
            $props['data-usephp-snapshot'] = $snapshotJson;
        }

        return new Element('div', $props, [$innerElement]);
    }

    /**
     * Render an Element tree to HTML.
     */
    private function doRenderElement(Element $element): string
    {
        $renderer = new Renderer('_root_', $this->getSnapshotSerializer());
        return $renderer->renderElement($element);
    }

    /**
     * Render a component with wrapper.
     */
    private function doRenderComponent(string $componentName, ?string $key = null): string
    {
        $component = $this->registry->create($componentName);

        if ($component === null) {
            return '';
        }

        // Start a new render pass
        RenderContext::beginRender();

        $instanceId = RenderContext::nextInstanceId($componentName, $key);
        $storageType = $this->registry->getStorageType($componentName);
        $state = ComponentState::getInstance($instanceId, $storageType);
        ComponentState::reset();

        if ($component instanceof BaseComponent) {
            $component->setComponentState($state);
        }

        $renderer = new Renderer(
            $instanceId,
            $storageType === StorageType::Snapshot ? $this->getSnapshotSerializer() : null,
            $storageType,
        );

        return $renderer->render(fn() => $component->render());
    }

    /**
     * Render a component partial with a specific instance ID (for form action handling).
     */
    private function doRenderComponentPartialWithInstanceId(string $instanceId, string $componentName): string
    {
        $component = $this->registry->create($componentName);

        if ($component === null) {
            return '';
        }

        $storageType = $this->registry->getStorageType($componentName);
        $state = ComponentState::getInstance($instanceId, $storageType);
        ComponentState::reset();

        if ($component instanceof BaseComponent) {
            $component->setComponentState($state);
        }

        $renderer = new Renderer(
            $instanceId,
            $storageType === StorageType::Snapshot ? $this->getSnapshotSerializer() : null,
            $storageType,
        );

        return $renderer->renderPartial(fn() => $component->render());
    }

    /**
     * Get the component registry.
     */
    public function getRegistry(): ComponentRegistry
    {
        return $this->registry;
    }
}
