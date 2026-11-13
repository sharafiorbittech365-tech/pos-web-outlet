<?php

namespace Config;

use CodeIgniter\Events\Events;
use CodeIgniter\Exceptions\FrameworkException;
use CodeIgniter\HotReloader\HotReloader;

/*
 * --------------------------------------------------------------------
 * Application Events
 * --------------------------------------------------------------------
 * Events allow you to tap into the execution of the program without
 * modifying or extending core files. This file provides a central
 * location to define your events, though they can always be added
 * at run-time, also, if needed.
 *
 * You create code that can execute by subscribing to events with
 * the 'on()' method. This accepts any form of callable, including
 * Closures, that will be executed when the event is triggered.
 *
 * Example:
 *      Events::on('create', [$myInstance, 'myMethod']);
 */

Events::on('pre_system', static function (): void {
    if (ENVIRONMENT !== 'testing') {
        $value = ini_get('zlib.output_compression');

        if (filter_var($value, FILTER_VALIDATE_BOOLEAN) || (int) $value > 0) {
            throw FrameworkException::forEnabledZlibOutputCompression();
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        ob_start(static fn ($buffer) => $buffer);
    }

    /*
     * --------------------------------------------------------------------
     * Debug Toolbar Listeners.
     * --------------------------------------------------------------------
     * If you delete, they will no longer be collected.
     */
    if (CI_DEBUG && ! is_cli()) {
        Events::on('DBQuery', 'CodeIgniter\Debug\Toolbar\Collectors\Database::collect');
        service('toolbar')->respond();
        // Hot Reload route - for framework use on the hot reloader.
        if (ENVIRONMENT === 'development') {
            service('routes')->get('__hot-reload', static function (): void {
                (new HotReloader())->run();
            });
        }
    }
});

/*
 * Request stats for `php spark omnipos:heartbeat` (Super Admin › POS servers): request count and
 * total response time since the last heartbeat, kept in the cache as ['n' => count, 'ms' => total].
 */
Events::on('post_system', static function (): void {
    if (is_cli() || ENVIRONMENT === 'testing') {
        return;
    }
    $start = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    $ms = (int) round((microtime(true) - $start) * 1000);
    try {
        $s = cache('omnipos_reqstats');
        $s = is_array($s) ? $s : ['n' => 0, 'ms' => 0];
        $s['n'] = (int) $s['n'] + 1;
        $s['ms'] = (int) $s['ms'] + max(0, $ms);
        cache()->save('omnipos_reqstats', $s, 600);
    } catch (\Throwable) {
        // stats are best effort
    }
});

// Loyalty points (back-office module): order:created / order:paid / order:returned listeners
require_once APPPATH . 'Modules/Loyalty/Config/Events.php';
