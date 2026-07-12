<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Inspector\ElementInspector;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\TailwindParser;

beforeEach(function () {
    NativeElementCollector::reset();
});

afterEach(function () {
    NativeElementCollector::reset();
    ElementInspector::enable(false);
    ElementInspector::resetStyleOverrides();
    ElementInspector::setActiveScope(null);
});

it('strips runtime_data attributes from parsing but keeps them on the element', function () {
    NativeElementCollector::leaf('text', ['text' => 'Hi', 'runtime_data:map' => 'abc123']);

    $element = NativeElementCollector::collect();
    $tree = $element->toArray(new CallbackRegistry);

    expect($element->getRuntimeData())->toBe(['map' => 'abc123']);
    expect($tree['props'] ?? [])->not->toHaveKey('runtime_data:map');
    expect($tree['props'] ?? [])->not->toHaveKey('_dbg_rt_map');
});

it('serializes runtime_data to _dbg_rt props only when capture is enabled', function () {
    ElementInspector::enable();

    NativeElementCollector::leaf('text', ['text' => 'Hi', 'runtime_data:map' => 'abc123']);

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['props']['_dbg_rt_map'])->toBe('abc123');
});

it('uses an instrumented runtime sidecar without a per-node source backtrace', function () {
    ElementInspector::enable();
    SourceCountingCollector::$sourceResolutions = 0;

    SourceCountingCollector::leaf('text', [
        'text' => 'Hi',
        'runtime_data:tesseract' => 'encoded-source',
        'runtime_data:source' => 'resources/views/native/home.blade.php',
    ]);

    $tree = SourceCountingCollector::collect()->toArray(new CallbackRegistry);

    expect(SourceCountingCollector::$sourceResolutions)->toBe(0)
        ->and($tree['props']['_dbg_rt_tesseract'])->toBe('encoded-source')
        ->and($tree['props']['_dbg_rt_source'])->toBe('resources/views/native/home.blade.php')
        ->and($tree['props'] ?? [])->not->toHaveKey('_dbg_src');
});

it('captures the authored class string as _dbg_classes when enabled', function () {
    ElementInspector::enable();

    NativeElementCollector::leaf('text', ['text' => 'Hi', 'class' => 'p-4 opacity-50']);

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['props']['_dbg_classes'])->toBe('p-4 opacity-50');
});

it('emits no _dbg props when capture is disabled', function () {
    NativeElementCollector::leaf('text', ['text' => 'Hi', 'class' => 'p-4 opacity-50']);

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    $dbgKeys = array_filter(array_keys($tree['props'] ?? []), fn ($key) => str_starts_with($key, '_dbg_'));

    expect($dbgKeys)->toBe([]);
});

it('surfaces press handler expressions and refs as _dbg props when enabled', function () {
    ElementInspector::enable();

    $tree = Button::make('Go')->onPress('increment')->ref('go-btn')
        ->toArray(new CallbackRegistry);

    expect($tree['props']['_dbg_press'])->toBe('increment');
    expect($tree['props']['_dbg_ref'])->toBe('go-btn');
});

it('applies a style override scoped to the active screen', function () {
    ElementInspector::enable();

    NativeElementCollector::leaf('text', ['text' => 'Hi', 'class' => 'opacity-50']);
    $original = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    ElementInspector::setActiveScope('App\\Screens\\Home');
    ElementInspector::setStyleOverride('App\\Screens\\Home', $original['id'], 'opacity-25');

    NativeElementCollector::reset();
    NativeElementCollector::leaf('text', ['text' => 'Hi', 'class' => 'opacity-50']);
    $overridden = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($overridden['id'])->toBe($original['id']);
    expect($overridden['style'])->not->toBe($original['style']);
    expect($overridden['props']['_dbg_classes_active'])->toBe('opacity-25');
});

it('never applies an override authored on a different screen', function () {
    ElementInspector::enable();

    NativeElementCollector::leaf('text', ['text' => 'Hi', 'class' => 'opacity-50']);
    $original = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    ElementInspector::setActiveScope('App\\Screens\\Detail');
    ElementInspector::setStyleOverride('App\\Screens\\Home', $original['id'], 'opacity-25');

    NativeElementCollector::reset();
    NativeElementCollector::leaf('text', ['text' => 'Hi', 'class' => 'opacity-50']);
    $second = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($second['style'] ?? null)->toBe($original['style'] ?? null);
    expect($second['props'] ?? [])->not->toHaveKey('_dbg_classes_active');
});

it('preserves later builder styling when an override changes unrelated classes', function () {
    ElementInspector::enable();
    ElementInspector::setActiveScope('App\\Screens\\Home');

    $element = Button::make('Go')->class('opacity-50')->opacity(0.8);
    $original = $element->toArray(new CallbackRegistry);

    ElementInspector::setStyleOverride('App\\Screens\\Home', $original['id'], 'p-4');
    $overridden = $element->toArray(new CallbackRegistry);

    expect($overridden['style']['opacity'])->toBe(0.8)
        ->and($overridden['layout']['padding'])->toBe(16.0)
        ->and($overridden['props']['_dbg_classes_active'])->toBe('p-4');
});

it('decorates streaming nodes with the same metadata and scoped overrides', function () {
    ElementInspector::enable();
    ElementInspector::setActiveScope('App\\Screens\\Home');
    ElementInspector::setStyleOverride('App\\Screens\\Home', 7, 'p-4 opacity-25');

    $authored = TailwindParser::parse('opacity-50');
    $layout = NativeElementCollector::buildLayoutArray($authored);
    $style = NativeElementCollector::buildStyleArray($authored);
    $props = [];

    ElementInspector::decorateStreaming(
        'text',
        7,
        'opacity-50',
        ['_press' => 'increment', 'ref' => 'counter'],
        $layout,
        $style,
        $props,
    );

    expect($layout['padding'])->toBe(16.0)
        ->and($style['opacity'])->toBe(0.25)
        ->and($props['_dbg_classes'])->toBe('opacity-50')
        ->and($props['_dbg_classes_active'])->toBe('p-4 opacity-25')
        ->and($props['_dbg_press'])->toBe('increment')
        ->and($props['_dbg_ref'])->toBe('counter');
});

it('mirrors streaming positional ids while keyed ids still consume a writer slot', function () {
    StreamingIdentityCollector::setStreaming(true);

    expect(StreamingIdentityCollector::publishedId(0))->toBe(1)
        ->and(StreamingIdentityCollector::publishedId(0x81234567))->toBe(0x81234567)
        ->and(StreamingIdentityCollector::publishedId(0))->toBe(3);
});

class SourceCountingCollector extends NativeElementCollector
{
    public static int $sourceResolutions = 0;

    protected static function resolveAuthoredSource(): ?string
    {
        self::$sourceResolutions++;

        return 'fallback.blade.php';
    }
}

class StreamingIdentityCollector extends NativeElementCollector
{
    public static function publishedId(int $explicitId): int
    {
        return static::resolveStreamingPublishedId($explicitId);
    }
}
