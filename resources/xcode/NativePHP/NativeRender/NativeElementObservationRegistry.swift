import Foundation

/// Opt-in observation seam for tooling that consumes rendered trees or native
/// interaction events. Serialization is skipped while no observers exist.
final class NativeElementObservationRegistry {
    static let shared = NativeElementObservationRegistry()

    struct Subscription: Hashable {
        fileprivate enum Stream: Hashable { case trees, events }
        fileprivate let stream: Stream
        fileprivate let id: Int
    }

    private let lock = NSLock()
    private var sequence = 0
    private var treeObservers: [Int: (String) -> Void] = [:]
    private var eventObservers: [Int: (String) -> Void] = [:]
    private var latestTree: NativeUITree?

    private init() {}

    func observeTrees(_ observer: @escaping (String) -> Void) -> Subscription {
        lock.lock()
        sequence &+= 1
        let subscription = Subscription(stream: .trees, id: sequence)
        treeObservers[sequence] = observer
        let replay = latestTree
        lock.unlock()

        if let replay, let json = serializeTree(replay) {
            observer(json)
        }
        return subscription
    }

    func observeEvents(_ observer: @escaping (String) -> Void) -> Subscription {
        subscribe(stream: .events, observer: observer)
    }

    func unsubscribe(_ subscription: Subscription) {
        lock.lock(); defer { lock.unlock() }
        switch subscription.stream {
        case .trees:
            treeObservers.removeValue(forKey: subscription.id)
        case .events:
            eventObservers.removeValue(forKey: subscription.id)
        }
    }

    func publish(tree: NativeUITree) {
        lock.lock()
        latestTree = tree
        let observers = Array(treeObservers.values)
        lock.unlock()
        guard !observers.isEmpty, let json = serializeTree(tree) else { return }
        notify(observers, json: json)
    }

    func publishEvent(type: Int, callbackId: Int, nodeId: Int) {
        guard type <= EventType.sheetDismiss else { return }
        let observers = snapshot(stream: .events)
        guard !observers.isEmpty else { return }

        let payload: [String: Any] = [
            "type": type,
            "callback_id": callbackId,
            "node_id": nodeId,
            "timestamp": Int(Date().timeIntervalSince1970 * 1000),
        ]
        guard let data = try? JSONSerialization.data(withJSONObject: payload),
              let json = String(data: data, encoding: .utf8) else { return }
        notify(observers, json: json)
    }

    private func subscribe(
        stream: Subscription.Stream,
        observer: @escaping (String) -> Void
    ) -> Subscription {
        lock.lock(); defer { lock.unlock() }
        sequence &+= 1
        switch stream {
        case .trees:
            treeObservers[sequence] = observer
        case .events:
            eventObservers[sequence] = observer
        }
        return Subscription(stream: stream, id: sequence)
    }

    private func snapshot(stream: Subscription.Stream) -> [(String) -> Void] {
        lock.lock(); defer { lock.unlock() }
        switch stream {
        case .trees: return Array(treeObservers.values)
        case .events: return Array(eventObservers.values)
        }
    }

    private func notify(_ observers: [(String) -> Void], json: String) {
        for observer in observers {
            observer(json)
        }
    }

    func serializeTree(_ tree: NativeUITree) -> String? {
        let payload: [String: Any] = [
            "version": tree.version,
            "callback_count": tree.callbackCount,
            "root": serializeNode(tree.root),
        ]
        guard let data = try? JSONSerialization.data(withJSONObject: payload) else { return nil }
        return String(data: data, encoding: .utf8)
    }

    private func serializeNode(_ node: NativeUINode) -> [String: Any] {
        var object: [String: Any] = [
            "id": node.id,
            "type": node.type,
            "on_press": node.onPress,
            "on_long_press": node.onLongPress,
            "props": serializeProps(node.props),
            "children": node.children.map { serializeNode($0) },
        ]
        if let layout = node.layout { object["layout"] = serializeLayout(layout) }
        if let style = node.style { object["style"] = serializeStyle(style) }
        return object
    }

    private func serializeLayout(_ layout: NodeLayout) -> [String: Any] {
        [
            "width": Double(layout.width), "width_mode": layout.widthMode,
            "height": Double(layout.height), "height_mode": layout.heightMode,
            "padding_top": Double(layout.paddingTop), "padding_right": Double(layout.paddingRight),
            "padding_bottom": Double(layout.paddingBottom), "padding_left": Double(layout.paddingLeft),
            "margin_top": Double(layout.marginTop), "margin_right": Double(layout.marginRight),
            "margin_bottom": Double(layout.marginBottom), "margin_left": Double(layout.marginLeft),
            "flex_grow": Double(layout.flexGrow), "flex_shrink": Double(layout.flexShrink),
            "flex_basis": Double(layout.flexBasis), "flex_direction": layout.flexDirection,
            "flex_wrap": layout.flexWrap, "align_self": layout.alignSelf,
            "align_items": layout.alignItems, "align_content": layout.alignContent,
            "justify_content": layout.justifyContent, "gap": Double(layout.gap),
            "row_gap": Double(layout.rowGap), "position_type": layout.positionType,
            "display": layout.display, "overflow": layout.overflow, "safe_area": layout.safeArea,
        ]
    }

    private func serializeStyle(_ style: NodeStyle) -> [String: Any] {
        [
            "bg_color": style.bgColor, "border_radius": Double(style.borderRadius),
            "border_width": Double(style.borderWidth), "border_color": style.borderColor,
            "opacity": Double(style.opacity), "elevation": Double(style.elevation),
        ]
    }

    private func serializeProps(_ props: GenericProps) -> [String: Any] {
        var object: [String: Any] = [:]
        for (key, value) in props.entries {
            switch value {
            case let string as String: object[key] = string
            case let number as NSNumber: object[key] = number
            case let bool as Bool: object[key] = bool
            case let list as [String]: object[key] = list
            default: break
            }
        }
        return object
    }
}
