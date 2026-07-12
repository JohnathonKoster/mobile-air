package com.nativephp.mobile.ui.nativerender

import android.util.Log
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.atomic.AtomicInteger

/**
 * Opt-in observation seam for tooling that consumes rendered trees or native
 * interaction events. Payloads are serialized only while observers exist.
 */
object NativeElementObservationRegistry {
    enum class Stream { TREES, EVENTS }

    data class Subscription internal constructor(
        internal val stream: Stream,
        internal val id: Int,
    )

    private const val TAG = "NativeElementObservers"
    private val sequence = AtomicInteger(0)
    private val treeLock = Any()
    private val treeObservers = linkedMapOf<Int, (String) -> Unit>()
    private val eventObservers = ConcurrentHashMap<Int, (String) -> Unit>()
    private var latestTree: NativeUITree? = null

    fun observeTrees(observer: (String) -> Unit): Subscription {
        val id = sequence.incrementAndGet()
        val replay = synchronized(treeLock) {
            treeObservers[id] = observer
            latestTree
        }
        replay?.let { tree -> serializeTree(tree)?.let { notify(listOf(observer), it, "tree") } }
        return Subscription(Stream.TREES, id)
    }

    fun observeEvents(observer: (String) -> Unit): Subscription =
        subscribeEvents(observer)

    fun unsubscribe(subscription: Subscription) {
        when (subscription.stream) {
            Stream.TREES -> synchronized(treeLock) { treeObservers.remove(subscription.id) }
            Stream.EVENTS -> eventObservers.remove(subscription.id)
        }
    }

    internal fun publishTree(tree: NativeUITree) {
        val observers = synchronized(treeLock) {
            latestTree = tree
            treeObservers.values.toList()
        }
        if (observers.isEmpty()) return
        val json = serializeTree(tree) ?: return
        notify(observers, json, "tree")
    }

    internal fun publishEvent(type: Int, callbackId: Int, nodeId: Int) {
        if (type > EventType.SHEET_DISMISS) return
        if (eventObservers.isEmpty()) return
        val json = runCatching {
            JSONObject()
                .put("type", type)
                .put("callback_id", callbackId)
                .put("node_id", nodeId.toLong() and 0xffffffffL)
                .put("timestamp", System.currentTimeMillis())
                .toString()
        }.getOrNull() ?: return
        notify(eventObservers.values, json, "event")
    }

    private fun subscribeEvents(observer: (String) -> Unit): Subscription {
        val id = sequence.incrementAndGet()
        eventObservers[id] = observer
        return Subscription(Stream.EVENTS, id)
    }

    private fun notify(
        observers: Collection<(String) -> Unit>,
        json: String,
        label: String,
    ) {
        for (observer in observers) {
            try {
                observer(json)
            } catch (t: Throwable) {
                Log.w(TAG, "$label observer error: ${t.message}")
            }
        }
    }

    internal fun serializeTree(tree: NativeUITree): String? = runCatching {
        JSONObject()
            .put("version", tree.version)
            .put("callback_count", tree.callbackCount)
            .put("root", serializeNode(tree.root))
            .toString()
    }.onFailure {
        Log.w(TAG, "tree serialization failed: ${it.message}")
    }.getOrNull()

    private fun serializeNode(node: NativeUINode): JSONObject {
        val objectValue = JSONObject()
            .put("id", node.id.toLong() and 0xffffffffL)
            .put("type", node.type)
            .put("on_press", node.onPress)
            .put("on_long_press", node.onLongPress)
            .put("props", serializeProps(node.props))

        node.layout?.let { objectValue.put("layout", serializeLayout(it)) }
        node.style?.let { objectValue.put("style", serializeStyle(it)) }

        val children = JSONArray()
        node.children.forEach { children.put(serializeNode(it)) }
        objectValue.put("children", children)

        return objectValue
    }

    private fun serializeLayout(layout: NodeLayout): JSONObject = JSONObject()
        .put("width", layout.width.toDouble())
        .put("width_mode", layout.widthMode)
        .put("height", layout.height.toDouble())
        .put("height_mode", layout.heightMode)
        .put("padding_top", layout.paddingTop.toDouble())
        .put("padding_right", layout.paddingRight.toDouble())
        .put("padding_bottom", layout.paddingBottom.toDouble())
        .put("padding_left", layout.paddingLeft.toDouble())
        .put("margin_top", layout.marginTop.toDouble())
        .put("margin_right", layout.marginRight.toDouble())
        .put("margin_bottom", layout.marginBottom.toDouble())
        .put("margin_left", layout.marginLeft.toDouble())
        .put("flex_grow", layout.flexGrow.toDouble())
        .put("flex_shrink", layout.flexShrink.toDouble())
        .put("flex_basis", layout.flexBasis.toDouble())
        .put("flex_direction", layout.flexDirection)
        .put("flex_wrap", layout.flexWrap)
        .put("align_self", layout.alignSelf)
        .put("align_items", layout.alignItems)
        .put("align_content", layout.alignContent)
        .put("justify_content", layout.justifyContent)
        .put("gap", layout.gap.toDouble())
        .put("row_gap", layout.rowGap.toDouble())
        .put("position_type", layout.positionType)
        .put("display", layout.display)
        .put("overflow", layout.overflow)
        .put("safe_area", layout.safeArea)

    private fun serializeStyle(style: NodeStyle): JSONObject = JSONObject()
        .put("bg_color", style.bgColor)
        .put("border_radius", style.borderRadius.toDouble())
        .put("border_width", style.borderWidth.toDouble())
        .put("border_color", style.borderColor)
        .put("opacity", style.opacity.toDouble())
        .put("elevation", style.elevation.toDouble())

    private fun serializeProps(props: GenericProps): JSONObject {
        val objectValue = JSONObject()
        for ((key, value) in props.entries) {
            runCatching { objectValue.put(key, if (value is List<*>) JSONArray(value) else value) }
        }
        return objectValue
    }
}
