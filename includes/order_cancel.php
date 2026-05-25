<?php

require_once __DIR__ . '/order_email.php';

function cancelCustomerOrder(mysqli $conn, int $orderId, int $userId): array
{
    if ($orderId <= 0 || $userId <= 0) {
        return ['success' => false, 'message' => 'Unable to cancel this order.'];
    }

    try {
        $conn->begin_transaction();

        $orderStmt = $conn->prepare("
            SELECT id, status
            FROM orders
            WHERE id = ?
            AND user_id = ?
            FOR UPDATE
        ");
        $orderStmt->bind_param("ii", $orderId, $userId);
        $orderStmt->execute();
        $order = $orderStmt->get_result()->fetch_assoc();

        if (!$order) {
            throw new Exception('Order not found.');
        }

        if ($order['status'] !== 'pending') {
            throw new Exception('Only pending orders can be canceled.');
        }

        $itemStmt = $conn->prepare("
            SELECT variant_id, quantity
            FROM order_items
            WHERE order_id = ?
            AND COALESCE(item_status, 'active') <> 'canceled'
        ");
        $itemStmt->bind_param("i", $orderId);
        $itemStmt->execute();
        $items = $itemStmt->get_result();

        $restoreStmt = $conn->prepare("
            UPDATE product_variants
            SET inventory = inventory + ?
            WHERE id = ?
        ");

        while ($item = $items->fetch_assoc()) {
            $quantity = (int) $item['quantity'];
            $variantId = (int) $item['variant_id'];
            if ($quantity > 0 && $variantId > 0) {
                $restoreStmt->bind_param("ii", $quantity, $variantId);
                $restoreStmt->execute();
            }
        }

        $updateStmt = $conn->prepare("
            UPDATE orders
            SET status = 'canceled'
            WHERE id = ?
            AND user_id = ?
        ");
        $updateStmt->bind_param("ii", $orderId, $userId);
        $updateStmt->execute();

        $conn->commit();
        sendCustomerOrderDetailsEmail($conn, $orderId, 'updated');

        return ['success' => true, 'message' => 'Order canceled successfully.'];
    } catch (Throwable $exception) {
        $conn->rollback();
        return ['success' => false, 'message' => $exception->getMessage()];
    }
}
