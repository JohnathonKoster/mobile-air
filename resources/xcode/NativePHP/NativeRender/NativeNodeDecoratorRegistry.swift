import SwiftUI

final class NativeNodeDecoratorRegistry {
    static let shared = NativeNodeDecoratorRegistry()
    typealias Decorator = (_ node: NativeUINode, _ content: AnyView) -> AnyView

    private let lock = NSLock()
    private var decorators: [String: Decorator] = [:]
    private var order: [String] = []
    private var snapshot: [Decorator] = []

    private init() {}

    func register(_ name: String, decorator: @escaping Decorator) {
        lock.lock(); defer { lock.unlock() }
        if decorators[name] == nil { order.append(name) }
        decorators[name] = decorator
        snapshot = order.compactMap { decorators[$0] }
    }

    func unregister(_ name: String) {
        lock.lock(); defer { lock.unlock() }
        decorators.removeValue(forKey: name)
        order.removeAll { $0 == name }
        snapshot = order.compactMap { decorators[$0] }
    }

    func decorate(node: NativeUINode, content: AnyView) -> AnyView {
        let current = snapshot
        guard !current.isEmpty else { return content }
        var decorated = content
        for decorator in current {
            decorated = decorator(node, decorated)
        }
        return decorated
    }

    var hasDecorators: Bool { !snapshot.isEmpty }
}

struct NativeNodeDecorationModifier: ViewModifier {
    let node: NativeUINode

    @ViewBuilder
    func body(content: Content) -> some View {
        if NativeNodeDecoratorRegistry.shared.hasDecorators {
            NativeNodeDecoratorRegistry.shared.decorate(node: node, content: AnyView(content))
        } else {
            content
        }
    }
}
