<?php
/**
 * Reads, writes or migrates Dispatch's settings in project config from a fresh process — a
 * process that watched a web request change them holds a stale copy.
 *
 *     php _pc.php get | set '<json>' | migrate
 */
$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

$pc = Craft::$app->getProjectConfig();
$path = 'plugins.dispatch.settings';

switch ($argv[1] ?? 'get') {
    case 'set':
        $value = json_decode($argv[2], true);
        empty($value) ? $pc->remove($path) : $pc->set($path, $value);
        $pc->flush();
        break;
    case 'migrate':
        (new justinholtweb\dispatch\migrations\m260929_000000_move_secrets_out_of_transport_settings())->safeUp();
        $pc->flush();
        break;
}

echo json_encode($pc->get($path));
