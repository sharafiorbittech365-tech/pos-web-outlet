<?php

/**
 * Loyalty listeners — loaded once from app/Config/Events.php (one require line).
 * Points are awarded only when the loyalty module is on for the order's tenant.
 */

use App\Modules\Loyalty\Services\LoyaltyService;
use CodeIgniter\Events\Events;

if (! defined('OMNIPOS_LOYALTY_EVENTS')) {
    define('OMNIPOS_LOYALTY_EVENTS', true);

    Events::on('order:created', static function (array $order): void {
        try {
            LoyaltyService::onCreated($order);
        } catch (\Throwable $e) {
            // never break a sale because of points
            log_message('error', 'Loyalty award failed: ' . $e->getMessage());
        }
    });
    Events::on('order:paid', static function (array $order): void {
        try {
            LoyaltyService::onPaid($order);
        } catch (\Throwable $e) {
            log_message('error', 'Loyalty award failed: ' . $e->getMessage());
        }
    });
    Events::on('order:returned', static function (array $order, float|int|string $amount = 0): void {
        try {
            LoyaltyService::onReturned($order, (float) $amount);
        } catch (\Throwable $e) {
            log_message('error', 'Loyalty return failed: ' . $e->getMessage());
        }
    });
}
