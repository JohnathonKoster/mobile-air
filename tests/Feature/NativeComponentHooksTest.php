<?php

use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\Inspector\ElementInspector;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\PingReceived;

afterEach(function () {
    ElementInspector::enable(false);
    ElementInspector::setActiveScope(null);
    TestableComponent::useHarness(null);
});

it('dispatches reserved native events to registered handlers', function () {
    $received = null;

    $id = NativeComponent::registerReservedNativeEventHandler('__test:ping', function (array $payload, NativeComponent $component) use (&$received) {
        $received = [$payload, $component];
    });

    try {
        $component = new CounterScreen;
        $handled = (new ReflectionMethod($component, 'dispatchReservedNativeEvent'))
            ->invoke($component, ['event' => '__test:ping', 'payload' => ['x' => 1]]);

        expect($handled)->toBeTrue();
        expect($received[0])->toBe(['x' => 1]);
        expect($received[1])->toBe($component);
    } finally {
        NativeComponent::unregisterReservedNativeEventHandler($id);
    }
});

it('ignores events with no registered handler or a non-reserved name', function () {
    $component = new CounterScreen;
    $dispatch = new ReflectionMethod($component, 'dispatchReservedNativeEvent');

    expect($dispatch->invoke($component, ['event' => '__unclaimed', 'payload' => []]))->toBeFalse();
    expect($dispatch->invoke($component, ['event' => 'ping', 'payload' => []]))->toBeFalse();
});

it('rejects handler registrations outside the double-underscore namespace', function () {
    NativeComponent::registerReservedNativeEventHandler('_test:ping', fn () => null);
})->throws(InvalidArgumentException::class);

it('scopes element overrides by component class and current uri', function () {
    $component = new CounterScreen;
    $component->setRouter(new class extends NativeRouter
    {
        public function currentUri(): ?string
        {
            return '/counters/42';
        }
    });

    expect($component->elementInspectorScope())->toBe(CounterScreen::class.'|/counters/42');
});

it('stops dispatching after a handler unregisters', function () {
    $calls = 0;

    $id = NativeComponent::registerReservedNativeEventHandler('__test:once', function () use (&$calls) {
        $calls++;
    });

    $component = new CounterScreen;
    $dispatch = new ReflectionMethod($component, 'dispatchReservedNativeEvent');

    $dispatch->invoke($component, ['event' => '__test:once', 'payload' => []]);
    NativeComponent::unregisterReservedNativeEventHandler($id);
    $handled = $dispatch->invoke($component, ['event' => '__test:once', 'payload' => []]);

    expect($calls)->toBe(1);
    expect($handled)->toBeFalse();
});

it('isolates a throwing reserved-event handler from its peers', function () {
    $secondRan = false;

    $first = NativeComponent::registerReservedNativeEventHandler('__test:boom', function () {
        throw new RuntimeException('broken subscriber');
    });
    $second = NativeComponent::registerReservedNativeEventHandler('__test:boom', function () use (&$secondRan) {
        $secondRan = true;
    });

    try {
        $component = new CounterScreen;
        $handled = (new ReflectionMethod($component, 'dispatchReservedNativeEvent'))
            ->invoke($component, ['event' => '__test:boom', 'payload' => []]);

        expect($handled)->toBeTrue();
        expect($secondRan)->toBeTrue();
    } finally {
        NativeComponent::unregisterReservedNativeEventHandler($first);
        NativeComponent::unregisterReservedNativeEventHandler($second);
    }
});

it('notifies interaction observers with the state change a tap caused', function () {
    $snapshots = [];

    $id = NativeComponent::observeInteractionDispatched(function (array $snapshot) use (&$snapshots) {
        $snapshots[] = $snapshot;
    });

    try {
        Native::test(CounterScreen::class)->tap('Increment');
    } finally {
        NativeComponent::stopObservingInteractionDispatched($id);
    }

    expect($snapshots)->toHaveCount(1);
    expect($snapshots[0]['class'])->toBe(CounterScreen::class);
    expect($snapshots[0]['method'])->toBe('increment');
    expect($snapshots[0]['stateBefore']['count'])->toBe(0);
    expect($snapshots[0]['stateAfter']['count'])->toBe(1);
    expect($snapshots[0]['error'])->toBeNull();
    expect($snapshots[0]['durationMs'])->toBeGreaterThanOrEqual(0);
});

it('announces an interaction before its handler runs', function () {
    $order = [];

    $willId = NativeComponent::observeInteractionWillDispatch(function (array $snapshot) use (&$order) {
        $order[] = 'will:'.$snapshot['method'];
    });
    $didId = NativeComponent::observeInteractionDispatched(function (array $snapshot) use (&$order) {
        $order[] = 'did:'.$snapshot['method'];
    });

    try {
        Native::test(CounterScreen::class)->tap('Increment');
    } finally {
        NativeComponent::stopObservingInteractionWillDispatch($willId);
        NativeComponent::stopObservingInteractionDispatched($didId);
    }

    expect($order)->toBe(['will:increment', 'did:increment']);
});

it('observes native events around their component handler', function () {
    $order = [];
    $snapshots = [];

    $willId = NativeComponent::observeNativeEventWillDispatch(function (array $snapshot) use (&$order) {
        $order[] = 'will:'.$snapshot['event'];
    });
    $didId = NativeComponent::observeNativeEventDispatched(function (array $snapshot) use (&$order, &$snapshots) {
        $order[] = 'did:'.$snapshot['event'];
        $snapshots[] = $snapshot;
    });

    try {
        Native::test(CounterScreen::class)
            ->emitNative(PingReceived::class, ['message' => 'hello']);
    } finally {
        NativeComponent::stopObservingNativeEventWillDispatch($willId);
        NativeComponent::stopObservingNativeEventDispatched($didId);
    }

    expect($order)->toBe([
        'will:'.PingReceived::class,
        'did:'.PingReceived::class,
    ]);
    expect($snapshots)->toHaveCount(1)
        ->and($snapshots[0]['class'])->toBe(CounterScreen::class)
        ->and($snapshots[0]['method'])->toBe('onPing')
        ->and($snapshots[0]['payload'])->toBe(['message' => 'hello'])
        ->and($snapshots[0]['stateBefore']['pings'])->toBe([])
        ->and($snapshots[0]['stateAfter']['pings'])->toBe(['hello'])
        ->and($snapshots[0]['error'])->toBeNull()
        ->and($snapshots[0]['durationMs'])->toBeGreaterThanOrEqual(0);
});

it('isolates throwing native event observers from the component handler', function () {
    $willId = NativeComponent::observeNativeEventWillDispatch(function (): void {
        throw new RuntimeException('broken will observer');
    });
    $didId = NativeComponent::observeNativeEventDispatched(function (): void {
        throw new RuntimeException('broken did observer');
    });

    try {
        $screen = Native::test(CounterScreen::class)
            ->emitNative(PingReceived::class, ['message' => 'hello']);
    } finally {
        NativeComponent::stopObservingNativeEventWillDispatch($willId);
        NativeComponent::stopObservingNativeEventDispatched($didId);
    }

    $screen->assertSet('pings', ['hello']);
});

it('reports handler failures without replacing the original exception', function () {
    $snapshots = [];
    $observerId = NativeComponent::observeNativeEventDispatched(function (array $snapshot) use (&$snapshots): void {
        $snapshots[] = $snapshot;
    });
    $exception = null;

    try {
        Native::test(ThrowingNativeEventScreen::class)
            ->emitNative(PingReceived::class, ['message' => 'hello']);
    } catch (RuntimeException $caught) {
        $exception = $caught;
    } finally {
        NativeComponent::stopObservingNativeEventDispatched($observerId);
    }

    expect($exception)->not->toBeNull()
        ->and($exception->getMessage())->toBe('native handler failed')
        ->and($snapshots)->toHaveCount(1)
        ->and($snapshots[0]['error']['class'])->toBe(RuntimeException::class)
        ->and($snapshots[0]['error']['message'])->toBe('native handler failed')
        ->and($snapshots[0]['stateBefore'])->not->toHaveKey('notInitialized')
        ->and($snapshots[0]['stateAfter'])->not->toHaveKey('notInitialized');
});

it('keeps native dispatch independent of subclass helper name collisions', function () {
    Native::test(NativeEventHelperCollisionScreen::class)
        ->emitNative(PingReceived::class, ['message' => 'hello'])
        ->assertSet('pings', ['hello'])
        ->assertSet('dispatchHelperCalled', false);
});

it('stops notifying native event observers after they unregister', function () {
    $notifications = 0;
    $willId = NativeComponent::observeNativeEventWillDispatch(function () use (&$notifications): void {
        $notifications++;
    });
    $didId = NativeComponent::observeNativeEventDispatched(function () use (&$notifications): void {
        $notifications++;
    });

    NativeComponent::stopObservingNativeEventWillDispatch($willId);
    NativeComponent::stopObservingNativeEventDispatched($didId);

    Native::test(CounterScreen::class)
        ->emitNative(PingReceived::class, ['message' => 'hello'])
        ->assertSet('pings', ['hello']);

    expect($notifications)->toBe(0);
});

it('normalizes signed native node ids to their uint32 inspector identity', function () {
    $normalize = new ReflectionMethod(NativeComponent::class, 'unsignedNodeId');

    expect($normalize->invoke(null, -1))->toBe(4294967295)
        ->and($normalize->invoke(null, 42))->toBe(42);
});

it('does not snapshot interactions when nothing observes', function () {
    Native::test(CounterScreen::class)
        ->tap('Increment')
        ->assertSet('count', 1);
});

it('notifies render-error observers once per throwable instance', function () {
    $seen = [];

    $id = NativeComponent::observeRenderError(function (Throwable $e, string $componentClass) use (&$seen) {
        $seen[] = [$e, $componentClass];
    });

    try {
        $component = new CounterScreen;
        $notify = new ReflectionMethod($component, 'notifyRenderError');
        $failure = new RuntimeException('render blew up');

        $notify->invoke($component, $failure);
        $notify->invoke($component, $failure);

        expect($seen)->toHaveCount(1);
        expect($seen[0][0])->toBe($failure);
        expect($seen[0][1])->toBe(CounterScreen::class);
    } finally {
        NativeComponent::stopObservingRenderError($id);
    }
});

it('stamps the callback registry on the root node when capture is enabled', function () {
    ElementInspector::enable();

    Native::test(CounterScreen::class)->assertElement('column', function (array $node) {
        $callbacks = json_decode($node['props']['_dbg_callbacks'] ?? 'null', true);

        return is_array($callbacks) && array_key_exists('increment', $callbacks);
    });
});

it('does not stamp callbacks on production frames', function () {
    Native::test(CounterScreen::class)->assertMissingElement('column', function (array $node) {
        return isset($node['props']['_dbg_callbacks']);
    });
});

it('constructs a registered harness subclass from test()', function () {
    TestableComponent::useHarness(ObservingHarness::class);

    $harness = Native::test(CounterScreen::class);

    expect($harness)->toBeInstanceOf(ObservingHarness::class);

    $harness->tap('Increment')->assertSet('count', 1);
});

it('restores the default harness when useHarness receives null', function () {
    TestableComponent::useHarness(ObservingHarness::class);
    TestableComponent::useHarness(null);

    expect(Native::test(CounterScreen::class))->not->toBeInstanceOf(ObservingHarness::class);
});

it('ignores a registered harness that is not a TestableComponent subclass', function () {
    TestableComponent::useHarness(stdClass::class);

    expect(Native::test(CounterScreen::class))->toBeInstanceOf(TestableComponent::class);
});

class ObservingHarness extends TestableComponent {}

class ThrowingNativeEventScreen extends CounterScreen
{
    public int $notInitialized;

    #[On(PingReceived::class)]
    public function onPing(string $message): void
    {
        throw new RuntimeException('native handler failed');
    }
}

class NativeEventHelperCollisionScreen extends CounterScreen
{
    public bool $dispatchHelperCalled = false;

    protected function dispatchNativeEventHandlers(string $eventName, mixed $payload): void
    {
        $this->dispatchHelperCalled = true;
    }
}
