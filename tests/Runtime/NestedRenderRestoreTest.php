<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\Component;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;

use function Polidog\UsePhp\Runtime\fc;

use Polidog\UsePhp\Runtime\FunctionComponent;
use Polidog\UsePhp\Runtime\RenderContext;

use function Polidog\UsePhp\Runtime\useState;

use Polidog\UsePhp\Storage\StorageType;
use Polidog\UsePhp\UsePHP;

#[Component(name: 'RestoreChild')]
final class RestoreChild extends BaseComponent
{
    public function render(): Element
    {
        [$value] = $this->useState('child');

        return H::span(children: $value);
    }
}

#[Component(name: 'RestoreThrowingChild')]
final class RestoreThrowingChild extends BaseComponent
{
    public function render(): Element
    {
        throw new \RuntimeException('child failed');
    }
}

/** A class parent reads its hooks through $this, not through the current state. */
#[Component(name: 'RestoreClassParent')]
final class RestoreClassParent extends BaseComponent
{
    public static mixed $setter = null;

    public function render(): Element
    {
        $kid = H::div(children: [new RestoreChild()]);
        [$value, self::$setter] = $this->useState('parent');

        return H::div(children: [$value, $kid]);
    }
}

/**
 * A component can be rendered while another one is rendering, either as a
 * BaseComponent in a children array or through UsePHP::render(). Coming back
 * must leave the outer component's state, stack and instance ids as it found them.
 */
final class NestedRenderRestoreTest extends TestCase
{
    private UsePHP $app;

    protected function setUp(): void
    {
        if (\session_status() === \PHP_SESSION_NONE) {
            \session_start();
        }
        $_SESSION = [];
        ComponentState::clearInstances();
        RenderContext::clearApp();
        RenderContext::beginRender();

        $this->app = new UsePHP();
        $this->app->setSnapshotSecret('test-secret-key-for-nested-render-restore-tests');
        $this->app->register(RestoreChild::class);
        $this->app->register(RestoreThrowingChild::class);
        $this->app->register(RestoreClassParent::class);
        RenderContext::setApp($this->app);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        ComponentState::clearInstances();
        RenderContext::clearApp();
        RenderContext::beginRender();
    }

    // =========================================================================
    // A BaseComponent in a children array
    // =========================================================================

    public function testSessionParentHooksAfterAClassChildStayOnTheParent(): void
    {
        $setter = null;
        $Parent = fc(function (array $props) use (&$setter): Element {
            $kid = H::div(children: [new RestoreChild()]);
            [$value, $setter] = useState('parent');

            return H::div(children: [$value, $kid]);
        }, 'session-parent');

        $parentId = $Parent([])->props['data-usephp'];

        self::assertSame('parent', $_SESSION["usephp:{$parentId}:state:0"] ?? null);
        self::assertSame($parentId, $setter(1)->componentId, 'the setter must target the parent');
        self::assertArrayNotHasKey('usephp:RestoreChild#0:state:1', $_SESSION);
    }

    public function testSnapshotParentKeepsItsStateAfterAClassChild(): void
    {
        $setter = null;
        $Parent = fc(function (array $props) use (&$setter): Element {
            $kid = H::div(children: [new RestoreChild()]);
            [$value, $setter] = useState('parent');

            return H::div(children: [$value, $kid]);
        }, 'snapshot-parent', StorageType::Snapshot);

        $element = $Parent([]);
        $parentId = $element->props['data-usephp'];

        self::assertSame($parentId, $setter(1)->componentId, 'the setter must target the parent');
        self::assertStringContainsString('parent', (string) $element->props['data-usephp-snapshot']);
        self::assertArrayNotHasKey('usephp:RestoreChild#0:state:1', $_SESSION);
    }

    public function testClassParentIsNotAffectedByAClassChild(): void
    {
        $this->app->render('RestoreClassParent');

        self::assertIsCallable(RestoreClassParent::$setter);
        self::assertSame('RestoreClassParent#0', (RestoreClassParent::$setter)(1)->componentId);
        self::assertSame('parent', $_SESSION['usephp:RestoreClassParent#0:state:0'] ?? null);
    }

    public function testThrowingClassChildRestoresTheParentAndKeepsLaterIds(): void
    {
        $setter = null;
        $depth = null;
        $sibling = new RestoreChild();
        $Parent = fc(function (array $props) use (&$setter, &$depth, $sibling): Element {
            try {
                H::div(children: [new RestoreThrowingChild()]);
            } catch (\RuntimeException) {
                // A boundary in the parent renders a fallback instead.
            }
            $depth = RenderContext::getStackDepth();
            [$value, $setter] = useState('parent');

            return H::div(children: [$value, $sibling]);
        }, 'boundary-parent');

        $parentId = $Parent([])->props['data-usephp'];

        self::assertSame(1, $depth, 'only the parent is on the stack');
        self::assertSame($parentId, $setter(1)->componentId);
        self::assertSame('parent', $_SESSION["usephp:{$parentId}:state:0"] ?? null);
        self::assertSame('RestoreChild#0', $sibling->getComponentState()?->getComponentId());
        self::assertSame(0, RenderContext::getStackDepth());
        self::assertNull(ComponentState::current());
    }

    public function testClassSiblingsKeepTheirIdOrder(): void
    {
        $first = new RestoreChild();
        $second = new RestoreChild();

        H::div(children: [$first, $second]);

        self::assertSame('RestoreChild#0', $first->getComponentState()?->getComponentId());
        self::assertSame('RestoreChild#1', $second->getComponentState()?->getComponentId());
        self::assertSame('child', $_SESSION['usephp:RestoreChild#0:state:0']);
        self::assertSame('child', $_SESSION['usephp:RestoreChild#1:state:0']);
    }

    // =========================================================================
    // UsePHP::render() during a render
    // =========================================================================

    /**
     * @return array<string, array{StorageType}>
     */
    public static function storageTypes(): array
    {
        return ['session' => [StorageType::Session], 'snapshot' => [StorageType::Snapshot]];
    }

    #[DataProvider('storageTypes')]
    public function testSiblingsAroundANestedRenderGetDistinctIds(StorageType $storageType): void
    {
        $Row = self::row($storageType);
        $app = $this->app;
        $ids = [];
        $Parent = fc(function (array $props) use ($Row, $app, &$ids): Element {
            $first = $Row([]);
            $app->render('RestoreChild');
            $second = $Row([]);
            $ids = [$first->props['data-usephp'], $second->props['data-usephp']];

            return H::div(children: [$first, $second]);
        }, 'row-parent-' . $storageType->value, $storageType);

        $Parent([]);

        self::assertStringEndsWith('#0', $ids[0]);
        self::assertStringEndsWith('#1', $ids[1], 'two rows sharing an id share one slot of state');
    }

    public function testStateWrittenToOneRowStaysOutOfTheNextOne(): void
    {
        $Row = self::row(StorageType::Session);
        $app = $this->app;
        $render = function () use ($Row, $app): array {
            $first = $Row([]);
            $app->render('RestoreChild');
            $second = $Row([]);

            return [$first, $second];
        };
        RenderContext::beginRender();
        $firstId = $render()[0]->props['data-usephp'];
        ComponentState::getInstance($firstId)->setState(0, 'clicked');
        ComponentState::setCurrent(null);

        RenderContext::beginRender();
        [$first, $second] = $render();

        self::assertSame('clicked', $first->children[0]->children[0]);
        self::assertSame('row', $second->children[0]->children[0]);
    }

    public function testParentStaysOnTheStackAfterANestedRender(): void
    {
        $app = $this->app;
        $seen = null;
        $Parent = fc(function (array $props) use ($app, &$seen): Element {
            $app->render('RestoreChild');
            $seen = RenderContext::currentComponentId();

            return H::div();
        }, 'stack-parent');

        $parentId = $Parent([])->props['data-usephp'];

        self::assertSame($parentId, $seen);
        self::assertSame(0, RenderContext::getStackDepth());
    }

    public function testThrowingNestedRenderRestoresCountersAndStack(): void
    {
        $Row = self::row(StorageType::Session);
        $app = $this->app;
        $ids = [];
        $seen = null;
        $Parent = fc(function (array $props) use ($Row, $app, &$ids, &$seen): Element {
            $ids[] = $Row([])->props['data-usephp'];
            try {
                $app->render('RestoreThrowingChild');
            } catch (\RuntimeException) {
                // A boundary in the parent renders a fallback instead.
            }
            $seen = RenderContext::currentComponentId();
            $ids[] = $Row([])->props['data-usephp'];

            return H::div();
        }, 'throwing-nested-parent');

        $parentId = $Parent([])->props['data-usephp'];

        self::assertSame($parentId, $seen);
        self::assertStringEndsWith('#0', $ids[0]);
        self::assertStringEndsWith('#1', $ids[1]);
    }

    /**
     * Before the counters were restored this already came out as #1. Going back
     * to #0 would hand this instance the nested render's state and drop what
     * #1 has stored.
     */
    public function testInstanceAfterANestedRenderOfTheSameComponentKeepsItsId(): void
    {
        $this->app->render('RestoreChild');

        $element = $this->app->createElement('RestoreChild');

        self::assertSame('RestoreChild#1', $element->props['data-usephp']);
    }

    public function testNestedRenderStillStartsItsOwnPass(): void
    {
        $this->app->createElement('RestoreChild');

        $html = $this->app->render('RestoreChild');

        self::assertStringContainsString('data-usephp="RestoreChild#0"', $html);
    }

    private static function row(StorageType $storageType): FunctionComponent
    {
        return fc(function (array $props): Element {
            [$value] = useState('row');

            return H::span(children: $value);
        }, storageType: $storageType);
    }
}
